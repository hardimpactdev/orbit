<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmError;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmImageBuildStep;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\TaskVmImageBuild;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Each invocation is one phase of one image; all artifacts are held until the complete set passes smoke and has its
 * topology seed. `smoke` keeps its VM for `topology-seed`, which builds the seed in it and then deletes it.
 */
final readonly class RecipeImageBuildSteps
{
    public function __construct(
        private TaskVmSettings $settings,
        private IncusHost $incus,
        private IncusTaskVmBaseImage $image,
        private IncusTaskVmImageBuilder $builder,
        private TaskVmImageRecipe $imageRecipe,
        private TaskVmCloudInit $cloudInit,
        private SshKeyProvider $keys,
        private RecipeImagePrograms $programs,
        private TaskVmVolumes $volumes,
        private AgentCommandRunner $agents,
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
        $alias = $this->alias($build, $profile);
        $shell = $this->image->shell($host, $node, $name)->forRecipePhase($profile, $phase);
        if (isset($build->images[$profile]) && ! in_array($phase, ['reset', 'smoke', 'publish', 'topology-seed'], true)) {
            return true;
        }
        try {
            if (in_array($phase, RecipeImagePrograms::Phases, true)) {
                $index = array_find_key($build->steps, static fn (array $entry): bool => $entry['step'] === $step->value && $entry['program'] === $program);
                if ($index === null) {
                    throw new \LogicException('The running image phase has no persisted build step.');
                }
                $state = $build->steps[$index]['phase_state'] ?? [];

                return $this->programs->poll($this->image->shell($host, $node, $name)->agentTarget(), $recipe, $profile, $phase, (string) $build->id, $state,
                    function (array $state) use ($build, $index): void {
                        $steps = $build->steps;
                        $entry = $steps[$index] ?? throw new \LogicException('The running image phase has no persisted build step.');
                        $steps[$index] = [...$entry, 'phase_state' => $state];
                        $build->steps = $steps;
                        $build->save();
                    }, $build->attempt);
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
                    $this->imageRecipe->boot($shell->agentTarget(), lean: true);
                    break;
                case 'clean':
                    $this->imageRecipe->mark($shell->agentTarget(), $recipe->identity, $profile);
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
                    $this->programs->verify($smokeShell->agentTarget(), $recipe, $profile);
                    break;
                case 'topology-seed':
                    $this->topologySeed($host, $node, $name.'-smoke', $build->images[$profile]['fingerprint']);
                    break;
                default:
                    throw new TaskVmException('task_vm.image_build_failed', 'Unknown recipe image phase.');
            }
        } catch (Throwable $error) {
            $path = IncusGuestShell::recipeLog($profile, $phase);
            $detail = mb_substr(new CommandActivityInputSanitizer()->redactText($error->getMessage()), 0, 500);
            if ($phase === 'publish') {
                throw new TaskVmException('task_vm.image_build_failed', $detail."\nPublishing runs on Incus host [{$node->name}].", previous: $error);
            }
            // The seed is built from the host, so no guest log in the smoke VM tells more than the agent diagnostic.
            if ($phase === 'topology-seed') {
                throw $error instanceof TaskVmException && $error->errorCode === TaskVmError::TopologySeedFailed->value ? $error
                    : new TaskVmException('task_vm.image_build_failed', $detail."\nThe topology seed is built from Incus host [{$node->name}].", previous: $error);
            }
            $failure = $error instanceof TaskVmException ? $error->imageFailure : null;
            $message = new CommandActivityInputSanitizer()->redactText($error->getMessage());
            $summary = explode("\n", $message, 2)[0];
            try {
                $logShell = $this->image->shell($host, $node, $phase === 'smoke' ? $name.'-smoke' : $name);
                // Installer diagnostics already belong to the guest log; append only the observation summary.
                $logShell->forRecipePhase($profile, $phase)->recordRecipeFailure($failure === null ? $message : $summary);
                $detail = $message."\nInspect the retained builder [{$logShell->vm}]: {$path}";
            } catch (Throwable) {
                $detail = $message."\nBuilder log unavailable: {$path}";
            }
            throw new TaskVmException($error instanceof TaskVmException ? $error->errorCode : 'task_vm.image_build_failed', mb_substr($detail, 0, 4000), previous: $error, imageFailure: $failure);
        }

        return true;
    }

    /**
     * Builds the topology seed of the base `$fingerprint` as {@see TaskVmVolumes::newSeed()} in the smoke VM `$smoke`
     * with `incus.build-topology-seed`, then deletes the VM and renames the seed, so a seed with its final name is always
     * finished. A finished seed is kept. The agent creates the `-new` volume before it changes anything in the VM,
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
                $result = $this->agents->run($node, 'incus.build-topology-seed', [
                    'project' => $host->project, 'pool' => $host->pool, 'vm' => $smoke,
                    'size' => TaskVmVolumes::SeedSize, 'fingerprint' => $fingerprint,
                ]);
                if (($result['ready'] ?? null) !== true) {
                    throw new TaskVmException(TaskVmError::TopologySeedFailed, "The topology seed in [{$smoke}] did not confirm completion.", 502);
                }
            } catch (AgentCommandRefused $failure) {
                if ($failure->errorCode !== TaskVmError::TopologySeedFailed->value) {
                    throw $failure;
                }
                $reason = is_string($failure->details['reason'] ?? null) ? $failure->details['reason'] : $failure->getMessage();
                throw new TaskVmException(TaskVmError::TopologySeedFailed, "Building the topology seed in [{$smoke}] failed: {$reason}", 502, $failure);
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

    private function alias(TaskVmImageBuild $build, string $profile): string
    {
        return $build->images[$profile]['alias'] ?? RecipeImageBuilds::alias($this->settings->host($build->host_node_id)->imageAlias, (string) $build->recipe_identity, $profile, $build->id);
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
        $published = $this->incus->aliasTarget($host, $build->hostNode, $this->alias($build, $profile));
        if (in_array($phase, ['publish', 'smoke', 'topology-seed'], true) && $published !== null) {
            return null;
        }
        if ($phase === 'launch' || array_key_exists((string) $build->builder_vm_name, $this->image->instances($host, $build->hostNode))) {
            return null;
        }

        return 'The failed recipe build resources no longer exist.';
    }
}
