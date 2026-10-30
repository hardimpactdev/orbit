<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmError;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmImageBuildStep;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Models\TaskVmImageBuild;
use Illuminate\Support\Facades\DB;

/**
 * Each invocation is one phase of one image; all artifacts are held until the complete set passes smoke and has its
 * topology seed. `smoke` keeps its VM for `topology-seed`, which builds the seed in it and then deletes it.
 */
final readonly class RecipeImageBuildSteps
{
    public function __construct(private TaskVmSettings $settings, private IncusHost $incus) {}

    public function assertCurrent(TaskVmImageBuild $build): void
    {
        $this->requireServingDelivery($build);
        $desired = DB::table('task_vm_image_recipe')->where('id', 1)->firstOrFail();
        if ($desired->identity !== $build->recipe_identity || (int) $desired->generation !== $build->recipe_generation) {
            throw new TaskVmException('task_vm.image_recipe_superseded', 'A newer recipe is desired. This image attempt cannot continue.');
        }
    }

    public function assertPromotion(TaskVmImageBuild $build): void
    {
        $this->assertCurrent($build);
        $passed = static fn (string $program): bool => array_any($build->steps, static fn (array $entry): bool => $entry['program'] === $program && $entry['seconds'] !== null && $entry['error'] === null);
        if (! is_string($build->image_fingerprint) || preg_match('/\A[a-f0-9]{64}\z/D', $build->image_fingerprint) !== 1
            || ($build->images['base']['fingerprint'] ?? null) !== $build->image_fingerprint || ! $passed('base:smoke') || ! $passed('base:topology-seed')) {
            throw new TaskVmException('task_vm.image_build_failed', 'The recipe base must pass smoke and have its topology seed before promotion.');
        }
        $published = TaskVmImageEvidence::published($build);
        if ($published === null || $published->node_id !== $build->host_node_id) {
            throw new ResourceOperationException('operation.completion_unproven', 'The candidate has no successful publication on its owning host.', 409);
        }
    }

    private function requireServingDelivery(TaskVmImageBuild $build): void
    {
        $recipe = new RecipeLoader()->decode((string) $build->recipe_json);
        if ($recipe->agentDigest((string) $build->hostNode->architecture) !== app(AgentDelivery::class)->pin($build->hostNode)) {
            throw new TaskVmException(TaskVmError::ImageRecipeSuperseded, 'The serving recipe delivery changed. This build cannot continue or promote.');
        }
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
        if ($phase === 'launch' || array_key_exists((string) $build->builder_vm_name, $this->incus->instanceImages($host, $build->hostNode))) {
            return null;
        }

        return 'The failed recipe build resources no longer exist.';
    }
}
