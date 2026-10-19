<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\RemotePrograms\RemoteProgramFailed;
use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Domain\TaskVms\TaskVmError;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmImageBuildStep;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\RemotePrograms\RemoteProgramCatalog;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\TaskVmImageBuild;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Each invocation is one phase of one image; all artifacts are held until the complete set passes smoke and has its
 * topology seed. `smoke` keeps its VM for `topology-seed`, which builds the seed in it and then deletes it.
 */
final readonly class RecipeImageBuildSteps
{
    /** The remote program that builds a topology seed in the smoke VM, as root on the host. */
    public const string SeedProgram = 'task-vms.build-topology-seed';

    public function __construct(
        private TaskVmSettings $settings,
        private IncusHost $incus,
        private IncusTaskVmBaseImage $image,
        private IncusTaskVmImageBuilder $builder,
        private TaskVmCloudInit $cloudInit,
        private SshKeyProvider $keys,
        private RecipeImagePrograms $programs,
        private TaskVmVolumes $volumes,
        private RemoteProgramRunner $runner,
        private RemoteProgramCatalog $catalog,
    ) {}

    public function run(TaskVmImageBuild $build, TaskVmImageBuildStep $step, ?string $program): bool
    {
        $host = $this->settings->host($build->host_node_id);
        $node = $build->hostNode;
        $name = (string) $build->builder_vm_name;
        if ($step === TaskVmImageBuildStep::Promote) {
            $this->promote($build);

            return true;
        }
        $desired = DB::table('task_vm_image_recipe')->where('id', 1)->firstOrFail();
        if ($desired->identity !== $build->recipe_identity || (int) $desired->generation !== $build->recipe_generation) {
            throw new TaskVmException('task_vm.image_recipe_superseded', 'A newer recipe is desired. This build cannot continue.');
        }
        if ($step === TaskVmImageBuildStep::Prune) {
            app(RecipeImagePruning::class)->prune($build);

            return true;
        }
        [$profile, $phase] = explode(':', (string) $program, 2);
        $recipe = new RecipeLoader()->decode((string) $build->recipe_json);
        if ($recipe->identity !== $build->recipe_identity || $profile !== 'base') {
            throw new TaskVmException('task_vm.image_build_failed', 'The stored image recipe is invalid.');
        }
        $alias = RecipeImageBuilds::alias($host->imageAlias, $recipe->identity, $profile);
        $shell = $this->image->shell($host, $node, $name)->forRecipePhase($profile, $phase);
        if (isset($build->images[$profile]) && ! in_array($phase, ['reset', 'smoke', 'publish', 'topology-seed'], true)) {
            return true;
        }
        try {
            if (in_array($phase, RecipeImagePrograms::Phases, true)) {
                return $this->programs->poll($this->image->shell($host, $node, $name), $recipe, $profile, $phase, $build->id.':'.$build->attempt);
            }
            switch ($phase) {
                case 'reset':
                    $this->image->delete($host, $node, $name);
                    $this->image->delete($host, $node, $name.'-smoke');
                    break;
                case 'launch':
                    $this->image->sweep($host, $node, discardFailed: true);
                    if ($build->source_alias === null) {
                        $build->forceFill(['source_alias' => TaskVmHost::SourceImage,
                            'source_fingerprint' => $this->incus->aliasTarget($host, $node, TaskVmHost::SourceImage)])->save();
                    }
                    $source = $build->source_fingerprint ?? TaskVmHost::SourceImage;
                    if (! array_key_exists($name, $this->image->instances($host, $node))) {
                        $this->image->launch($host, $node, $name, $source, $this->cloudInit->renderImageBuilder($this->keys->publicKey()));
                    }
                    break;
                case 'boot':
                    $this->image->awaitCloudInit($host, $node, $name);
                    break;
                case 'prepare':
                    $this->builder->singleBoot($host, $node, $shell);
                    $this->image->checked($name, 'lean boot', $shell->asRoot(['bash', '-ceu', TaskVmScripts::read('lean-boot.sh').TaskVmImageRecipe::Masks]));
                    break;
                case 'clean':
                    $this->image->checked($name, 'recipe marker', $shell->asRoot(['install', '-D', '-m', '0644', '/dev/stdin', '/etc/orbit-machine-recipe.json'],
                        json_encode(['identity' => $recipe->identity, 'profile' => $profile], JSON_THROW_ON_ERROR)));
                    $this->builder->clean($shell);
                    break;
                case 'publish':
                    $fingerprint = $build->images[$profile]['fingerprint'] ?? $this->incus->aliasTarget($host, $node, $alias);
                    $fingerprint ??= $this->builder->publish($host, $node, $name, $alias, ['orbit.recipe' => $recipe->identity, 'orbit.profile' => $profile]);
                    $images = $build->images;
                    $images[$profile] = ['project' => $host->project, 'alias' => $alias, 'fingerprint' => $fingerprint, 'type' => 'virtual-machine'];
                    $build->images = $images;
                    $build->image_fingerprint = $fingerprint;
                    $build->save();
                    $this->image->delete($host, $node, $name);
                    break;
                case 'smoke':
                    $smoke = $name.'-smoke';
                    $this->ensureSmokeVm($host, $node, $smoke, $build->images[$profile]['fingerprint']);
                    $smokeShell = $this->image->shell($host, $node, $smoke)->forRecipePhase($profile, $phase);
                    $this->image->checkSmoke($smokeShell);
                    $this->image->checked($smoke, 'image check', $smokeShell->asRoot(TaskVmImageRecipe::imageCheck(['--lean-boot'])));
                    $this->programs->verify($smokeShell, $recipe, $profile);
                    break;
                case 'topology-seed':
                    $this->topologySeed($host, $node, $name.'-smoke', $build->images[$profile]['fingerprint']);
                    break;
                default:
                    throw new TaskVmException('task_vm.image_build_failed', 'Unknown recipe image phase.');
            }
        } catch (Throwable $error) {
            if ($error instanceof TaskVmException && $error->errorCode === TaskVmError::ImageChannelLost->value) {
                Log::warning($error->getMessage(), ['build_id' => $build->id, 'phase' => $program]);
                $entry = array_find($build->steps, static fn (array $entry): bool => $entry['step'] === $step->value && $entry['program'] === $program);
                if (now()->diffInSeconds(Carbon::parse($entry['started_at'] ?? now()), absolute: true)
                    <= RecipeImagePrograms::PhaseSeconds + RecipeImagePrograms::ObservationGraceSeconds) {
                    return false;
                }
            }
            $path = IncusGuestShell::recipeLog($profile, $phase);
            $detail = mb_substr(new CommandActivityInputSanitizer()->redactText($error->getMessage()), 0, 500);
            if ($phase === 'publish') {
                throw new TaskVmException('task_vm.image_build_failed', $detail."\nPublishing runs on Incus host [{$node->name}].", previous: $error);
            }
            // The seed is built from the host, so no guest log in the smoke VM tells more than the program's log.
            if ($phase === 'topology-seed') {
                throw $error instanceof TaskVmException && $error->errorCode === TaskVmError::TopologySeedFailed->value ? $error
                    : new TaskVmException('task_vm.image_build_failed', $detail."\nThe topology seed is built from Incus host [{$node->name}].", previous: $error);
            }
            try {
                $logShell = $this->image->shell($host, $node, $phase === 'smoke' ? $name.'-smoke' : $name);
                $logShell->forRecipePhase($profile, $phase)->recordRecipeFailure($detail);
                $tail = $logShell->asRoot(['tail', '-n', '40', '--', $path]);
                $output = new CommandActivityInputSanitizer()->redactText($tail->stdout);
                $detail .= $tail->succeeded() ? "\nInspect the retained builder [{$logShell->vm}]: {$path}\n".mb_substr($output, -3200)
                    : "\nBuilder log unavailable: {$path}";
            } catch (Throwable) {
                $detail .= "\nBuilder log unavailable: {$path}";
            }
            throw new TaskVmException('task_vm.image_build_failed', mb_substr($detail, 0, 4000), previous: $error);
        }

        return true;
    }

    /**
     * Builds the topology seed of the base `$fingerprint` as {@see TaskVmVolumes::newSeed()} in the smoke VM `$smoke`
     * with {@see self::SeedProgram}, then deletes the VM and renames the seed, so a seed with its final name is always
     * finished. A finished seed is kept. The program creates the `-new` volume before it changes anything in the VM,
     * so a VM beside a `-new` volume may hold the Incus state of an earlier attempt: it is replaced by a fresh one from
     * the published base, as a gone VM is.
     */
    private function topologySeed(TaskVmHost $host, Node $node, string $smoke, string $fingerprint): void
    {
        if (! $this->volumes->exists($host, $node, TaskVmVolumes::seed($fingerprint))) {
            if ($this->volumes->exists($host, $node, TaskVmVolumes::newSeed($fingerprint))) {
                $this->image->delete($host, $node, $smoke);
            }
            $this->ensureSmokeVm($host, $node, $smoke, $fingerprint);
            try {
                $this->runner->run($node, $this->catalog->program(self::SeedProgram, user: 'root'), [
                    'project' => $host->project, 'pool' => $host->pool, 'vm' => $smoke, 'device' => TaskVmVolumes::SeedDevice,
                    'volume' => TaskVmVolumes::newSeed($fingerprint), 'size' => TaskVmVolumes::SeedSize, 'fingerprint' => $fingerprint,
                ]);
            } catch (RemoteProgramFailed $failure) {
                $log = mb_substr(trim($failure->log), -3000);
                throw new TaskVmException(TaskVmError::TopologySeedFailed, "Building the topology seed in [{$smoke}] failed: {$failure->reason->value}.".($log === '' ? '' : "\n{$log}"), 502, $failure);
            }
        }
        $this->image->delete($host, $node, $smoke);
        $this->volumes->finishSeed($host, $node, $fingerprint);
    }

    /** Launches the smoke VM `$smoke` from the published base `$fingerprint` when it is missing, and waits for its cloud-init. */
    private function ensureSmokeVm(TaskVmHost $host, Node $node, string $smoke, string $fingerprint): void
    {
        if (! array_key_exists($smoke, $this->image->instances($host, $node))) {
            $this->image->launch($host, $node, $smoke, $fingerprint, $this->cloudInit->render($this->keys->publicKey()));
        }
        $this->image->awaitCloudInit($host, $node, $smoke);
    }

    public function promote(TaskVmImageBuild $build): void
    {
        DB::transaction(function () use ($build): void {
            $current = DB::table('task_vm_image_recipe')->where('id', 1)->where('identity', $build->recipe_identity)
                ->where('generation', $build->recipe_generation)->update(['identity' => $build->recipe_identity]);
            if ($current !== 1) {
                throw new TaskVmException('task_vm.image_recipe_superseded', 'A newer recipe is desired. This build cannot become current.');
            }
            $passed = static fn (string $program): bool => array_any($build->steps, static fn (array $entry): bool => $entry['program'] === $program && $entry['seconds'] !== null && $entry['error'] === null);
            if (! isset($build->images['base']) || ! $passed('base:smoke') || ! $passed('base:topology-seed')) {
                throw new TaskVmException('task_vm.image_build_failed', 'The recipe base must pass smoke and have its topology seed before promotion.');
            }
            $host = $this->settings->host($build->host_node_id);
            $this->image->promote($host, $build->hostNode, (string) $build->image_fingerprint);
            $build->promoted_at = now();
            $build->save();
        });
    }

    public function resumabilityError(TaskVmImageBuild $build): ?string
    {
        $desired = DB::table('task_vm_image_recipe')->where('id', 1)->firstOrFail();
        if ($desired->identity !== $build->recipe_identity || (int) $desired->generation !== $build->recipe_generation) {
            return 'A newer recipe is desired. Start the current recipe instead.';
        }
        if (in_array($build->current_step, [TaskVmImageBuildStep::Promote, TaskVmImageBuildStep::Prune], true)) {
            return null;
        }
        [$profile, $phase] = explode(':', (string) $build->current_program, 2) + ['', ''];
        $host = $this->settings->host($build->host_node_id);
        $published = $this->incus->aliasTarget($host, $build->hostNode, RecipeImageBuilds::alias($host->imageAlias, (string) $build->recipe_identity, $profile));
        if (in_array($phase, ['publish', 'smoke', 'topology-seed'], true) && $published !== null) {
            return null;
        }
        if ($phase === 'launch' || array_key_exists((string) $build->builder_vm_name, $this->image->instances($host, $build->hostNode))) {
            return null;
        }

        return 'The failed recipe build resources no longer exist.';
    }
}
