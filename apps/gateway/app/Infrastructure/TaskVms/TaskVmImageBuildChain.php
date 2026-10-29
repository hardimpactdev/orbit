<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Actions\TaskVms\StartTaskVmImageOperationAction;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmImageBuildStep;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Models\Node;
use App\Models\TaskVmImageBuild;
use Illuminate\Support\Facades\DB;

final readonly class TaskVmImageBuildChain
{
    public function __construct(private TaskVmSettings $settings, private IncusHost $incus, private StartTaskVmImageOperationAction $operations) {}

    public function start(Node $node, MachineRecipe $recipe, int $generation): TaskVmImageBuild
    {
        return DB::transaction(function () use ($node, $recipe, $generation): TaskVmImageBuild {
            $build = TaskVmImageBuild::query()->create(['host_node_id' => $node->id, 'from_stock' => true, 'recipe_identity' => $recipe->identity, 'recipe_json' => $recipe->json, 'recipe_generation' => $generation]);
            $this->submit($build);

            return $build;
        });
    }

    /** Retry from stock or reactivate a previously verified immutable set after an explicit recipe revert. */
    public function requeueRecipe(Node $node, TaskVmImageBuild $build, int $generation, bool $restart): TaskVmImageBuild
    {
        return DB::transaction(function () use ($node, $build, $generation, $restart): TaskVmImageBuild {
            $build = DB::transaction(function () use ($node, $build, $generation, $restart): TaskVmImageBuild {
                $changed = TaskVmImageBuild::query()->whereKey($build->id)->where('state', $build->state)
                    ->where('attempt', $build->attempt)->update(['attempt' => $build->attempt + 1]);
                if ($changed !== 1) {
                    throw new TaskVmException('task_vm.image_build_running', 'Another command has claimed this recipe build.');
                }
                $pruned = false;
                if (! $restart) {
                    $images = $this->incus->images($this->settings->host($node->id), $node);
                    $base = $build->images['base'] ?? null;
                    $pruned = $base === null || ! in_array($base['alias'], $images[$base['fingerprint']] ?? [], true);
                    $restart = $pruned;
                }
                $steps = $restart ? [] : array_values(array_filter($build->steps, static fn (array $entry): bool => ! in_array($entry['step'], [TaskVmImageBuildStep::Promote->value, TaskVmImageBuildStep::Prune->value], true)));
                $build->refresh();
                $builder = $restart ? 'tvm-image-'.$build->id.'-attempt-'.$build->attempt : $build->builder_vm_name;
                $build->forceFill(['builder_vm_name' => $builder, 'state' => TaskVmImageBuildState::Running, 'recipe_generation' => $generation,
                    'steps' => $steps, 'images' => $pruned ? [] : $build->images,
                    'image_fingerprint' => $pruned ? null : $build->image_fingerprint,
                    'source_fingerprint' => $pruned ? null : $build->source_fingerprint,
                    'current_step' => $restart ? null : TaskVmImageBuildStep::Promote,
                    'current_program' => null, 'cleanup_started_at' => null])->save();

                return $build;
            });
            $this->submit($build, $build->current_step === TaskVmImageBuildStep::Promote);

            return $build;
        });
    }

    public function resumeLatest(Node $node): TaskVmImageBuild
    {
        foreach (TaskVmImageBuild::query()->where('host_node_id', $node->id)->where('state', TaskVmImageBuildState::Failed)
            ->whereNull('cleanup_started_at')->whereNotNull('current_step')->orderByDesc('id')->get() as $build) {
            if ($this->resumabilityError($build) === null) {
                return $this->resume($node, $build->id);
            }
        }

        throw new TaskVmException('task_vm.image_build_failed', 'No resumable failed image build exists on this host.');
    }

    public function resume(Node $node, int $id): TaskVmImageBuild
    {
        return DB::transaction(function () use ($node, $id): TaskVmImageBuild {
            $build = TaskVmImageBuild::query()->where('host_node_id', $node->id)->find($id)
                ?? throw new TaskVmException('task_vm.image_build_failed', "Build [{$id}] does not belong to this host.");
            if ($build->state !== TaskVmImageBuildState::Failed || $build->cleanup_started_at !== null || $build->current_step === null) {
                throw new TaskVmException('task_vm.image_build_failed', 'Only an unclaimed failed build can resume.');
            }
            $error = $this->resumabilityError($build);
            if ($error !== null) {
                throw new TaskVmException('task_vm.image_build_failed', $error);
            }
            // A conditional write, not SELECT FOR UPDATE (which SQLite ignores), arbitrates against cleanup.
            DB::transaction(function () use ($build): void {
                $claimed = TaskVmImageBuild::query()->whereKey($build->id)->where('state', TaskVmImageBuildState::Failed)
                    ->whereNull('cleanup_started_at')->where('attempt', $build->attempt)->update(['submission_pending_at' => now()->toISOString(), 'attempt' => $build->attempt + 1]);
                if ($claimed !== 1) {
                    throw new TaskVmException('task_vm.image_build_failed', 'Cleanup or another resume has already claimed this build.');
                }
                $build->refresh();
                $steps = $build->steps;
                foreach ($steps as &$entry) {
                    if ($entry['step'] === $build->current_step?->value && $entry['program'] === $build->current_program) {
                        unset($entry['phase_state']['observed_at']);
                        $entry['started_at'] = null;
                        $entry['seconds'] = null;
                        $entry['error'] = null;
                    }
                }
                unset($entry);
                // The conditional write holds the transaction's SQLite writer fence until this save.
                // Use the model for the state change so its running-host uniqueness error stays a domain error.
                $build->forceFill(['steps' => $steps, 'state' => TaskVmImageBuildState::Running])->save();
            });

            $this->submit($build, true);

            return $build;
        });
    }

    private function resumabilityError(TaskVmImageBuild $build): ?string
    {
        if ($build->recipe_identity === null) {
            return 'Legacy image builds cannot resume. Build the current recipe instead.';
        }

        return app(RecipeImageBuildSteps::class)->resumabilityError($build);
    }

    private function submit(TaskVmImageBuild $build, bool $resume = false): void
    {
        $this->operations->execute($build, $resume);
    }
}
