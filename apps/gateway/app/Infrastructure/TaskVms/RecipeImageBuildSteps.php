<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmImageBuildStep;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\TaskVmImageBuild;
use Illuminate\Support\Facades\DB;

/** Each invocation is one phase of one image; all artifacts are held until the complete set passes smoke. */
final readonly class RecipeImageBuildSteps
{
    public function __construct(
        private TaskVmSettings $settings,
        private IncusHost $incus,
        private IncusTaskVmBaseImage $image,
        private IncusTaskVmImageBuilder $builder,
        private TaskVmCloudInit $cloudInit,
        private SshKeyProvider $keys,
        private RecipeImagePrograms $programs,
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
        [$profile, $phase] = explode(':', (string) $program, 2);
        $recipe = new RecipeLoader()->decode((string) $build->recipe_json);
        if ($recipe->identity !== $build->recipe_identity || ! in_array($profile, ['base', ...RecipeImageBuilds::Roles], true)) {
            throw new TaskVmException('task_vm.image_build_failed', 'The stored image recipe is invalid.');
        }
        $alias = RecipeImageBuilds::alias($host->imageAlias, $recipe->identity, $profile);
        $shell = $this->image->shell($host, $node, $name);
        if (isset($build->images[$profile]) && ! in_array($phase, ['reset', 'smoke', 'publish'], true)) {
            return true;
        }
        switch ($phase) {
            case 'reset':
                $this->image->delete($host, $node, $name);
                $this->image->delete($host, $node, $name.'-smoke');
                break;
            case 'launch':
                if ($profile === 'base') {
                    $this->image->sweep($host, $node, discardFailed: true);
                    if ($build->source_alias === null) {
                        $build->forceFill(['source_alias' => TaskVmHost::SourceImage,
                            'source_fingerprint' => $this->incus->aliasTarget($host, $node, TaskVmHost::SourceImage)])->save();
                    }
                }
                $source = $profile === 'base' ? ($build->source_fingerprint ?? TaskVmHost::SourceImage) : $build->images['base']['fingerprint'];
                if (! array_key_exists($name, $this->image->instances($host, $node))) {
                    $this->image->launch($host, $node, $name, $source, $this->cloudInit->renderImageBuilder($this->keys->publicKey(), upgradePackages: false));
                }
                break;
            case 'boot':
                $this->image->awaitCloudInit($host, $node, $name);
                break;
            case 'packages':
            case 'javascript':
            case 'rust':
            case 'agent':
            case 'pi':
                $this->programs->apply($shell, $recipe, $profile, $phase);
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
                if ($profile === 'base') {
                    $build->image_fingerprint = $fingerprint;
                }
                $build->save();
                $this->image->delete($host, $node, $name);
                break;
            case 'smoke':
                $smoke = $name.'-smoke';
                if (! array_key_exists($smoke, $this->image->instances($host, $node))) {
                    $this->image->launch($host, $node, $smoke, $build->images[$profile]['fingerprint'], $this->cloudInit->render($this->keys->publicKey()));
                }
                $this->image->awaitCloudInit($host, $node, $smoke);
                $smokeShell = $this->image->shell($host, $node, $smoke);
                $this->image->checkSmoke($smokeShell);
                $this->image->checked($smoke, 'image check', $smokeShell->asRoot(TaskVmImageRecipe::imageCheck(['--lean-boot'])));
                $this->programs->verify($smokeShell, $recipe, $profile);
                $this->image->delete($host, $node, $smoke);
                break;
            default:
                throw new TaskVmException('task_vm.image_build_failed', 'Unknown recipe image phase.');
        }

        return true;
    }

    public function promote(TaskVmImageBuild $build): void
    {
        DB::transaction(function () use ($build): void {
            $current = DB::table('task_vm_image_recipe')->where('id', 1)->where('identity', $build->recipe_identity)
                ->where('generation', $build->recipe_generation)->update(['identity' => $build->recipe_identity]);
            if ($current !== 1) {
                throw new TaskVmException('task_vm.image_recipe_superseded', 'A newer recipe is desired. This build cannot become current.');
            }
            foreach (['base', ...RecipeImageBuilds::Roles] as $profile) {
                $smoked = array_any($build->steps, static fn (array $entry): bool => $entry['program'] === $profile.':smoke' && $entry['seconds'] !== null && $entry['error'] === null);
                if (! isset($build->images[$profile]) || ! $smoked) {
                    throw new TaskVmException('task_vm.image_build_failed', 'Every recipe image must pass smoke before promotion.');
                }
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
        if ($build->current_step === TaskVmImageBuildStep::Promote) {
            return null;
        }
        [$profile, $phase] = explode(':', (string) $build->current_program, 2) + ['', ''];
        $host = $this->settings->host($build->host_node_id);
        $published = $this->incus->aliasTarget($host, $build->hostNode, RecipeImageBuilds::alias($host->imageAlias, (string) $build->recipe_identity, $profile));
        if (in_array($phase, ['publish', 'smoke'], true) && $published !== null) {
            return null;
        }
        if ($phase === 'launch' || array_key_exists((string) $build->builder_vm_name, $this->image->instances($host, $build->hostNode))) {
            return null;
        }

        return 'The failed recipe build resources no longer exist.';
    }
}
