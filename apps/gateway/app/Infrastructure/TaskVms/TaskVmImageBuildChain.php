<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Actions\TaskVms\StartTaskVmImageOperationAction;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Models\Node;
use App\Models\TaskVmImageBuild;
use Illuminate\Support\Facades\DB;

/** Records image attempts. Swamp owns all host observations and build steps. */
final readonly class TaskVmImageBuildChain
{
    public function __construct(private StartTaskVmImageOperationAction $operations) {}

    public function start(Node $node, MachineRecipe $recipe, int $generation): TaskVmImageBuild
    {
        return DB::transaction(function () use ($node, $recipe, $generation): TaskVmImageBuild {
            $build = TaskVmImageBuild::query()->create(['host_node_id' => $node->id, 'from_stock' => true,
                'recipe_identity' => $recipe->identity, 'recipe_json' => $recipe->json, 'recipe_generation' => $generation]);
            $this->operations->execute($build);

            return $build;
        });
    }

    public function requeueRecipe(Node $node, TaskVmImageBuild $build, int $generation): TaskVmImageBuild
    {
        return DB::transaction(function () use ($node, $build, $generation): TaskVmImageBuild {
            if ($build->host_node_id !== $node->id || $build->state === TaskVmImageBuildState::Running || $build->cleanup_started_at !== null) {
                throw new TaskVmException('task_vm.image_build_running', 'The host already owns a running image attempt.');
            }
            $changed = TaskVmImageBuild::query()->whereKey($build->id)->where('state', $build->state)
                ->where('attempt', $build->attempt)->whereNull('cleanup_started_at')->update(['attempt' => $build->attempt + 1]);
            if ($changed !== 1) {
                throw new TaskVmException('task_vm.image_build_running', 'Another command claimed this image attempt.');
            }
            $build->refresh();
            $build->forceFill(['builder_vm_name' => 'tvm-image-'.$build->id.'-attempt-'.$build->attempt,
                'state' => TaskVmImageBuildState::Running, 'recipe_generation' => $generation, 'from_stock' => true,
                'steps' => [], 'images' => [], 'image_fingerprint' => null, 'source_fingerprint' => null,
                'source_alias' => null, 'promoted_at' => null, 'submission_pending_at' => null,
                'current_step' => null, 'current_program' => null, 'cleanup_started_at' => null])->save();
            $this->operations->execute($build);

            return $build;
        });
    }

    public function resumeLatest(Node $node): TaskVmImageBuild
    {
        $build = TaskVmImageBuild::query()->where('host_node_id', $node->id)->where('state', TaskVmImageBuildState::Failed)
            ->whereNull('cleanup_started_at')->whereNotNull('recipe_identity')->latest('id')->first()
            ?? throw new TaskVmException('task_vm.image_build_failed', 'No failed recipe image exists on this host.');

        return $this->resume($node, $build->id);
    }

    public function resume(Node $node, int $id): TaskVmImageBuild
    {
        $build = TaskVmImageBuild::query()->where('host_node_id', $node->id)->where('state', TaskVmImageBuildState::Failed)
            ->whereNull('cleanup_started_at')->whereNotNull('recipe_identity')->find($id)
            ?? throw new TaskVmException('task_vm.image_build_failed', 'Only an unclaimed failed build can start another native attempt.');
        app(RecipeImageBuildSteps::class)->assertCurrent($build);

        return $this->requeueRecipe($node, $build, (int) $build->recipe_generation);
    }
}
