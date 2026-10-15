<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmImageBuildStep;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Jobs\TaskVms\ImageBuild\BuildRecipeImageStep;
use App\Jobs\TaskVms\ImageBuild\ImageBuildJob;
use App\Jobs\TaskVms\ImageBuild\PromoteImage;
use App\Jobs\TaskVms\ImageBuild\PruneRecipeImages;
use App\Models\Node;
use App\Models\TaskVmImageBuild;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final readonly class TaskVmImageBuildChain
{
    public function __construct(private TaskVmSettings $settings, private IncusHost $incus) {}

    public function start(Node $node, MachineRecipe $recipe, int $generation): TaskVmImageBuild
    {
        $this->recoverSubmissions();
        $build = TaskVmImageBuild::query()->create(['host_node_id' => $node->id, 'from_stock' => true, 'recipe_identity' => $recipe->identity, 'recipe_json' => $recipe->json, 'recipe_generation' => $generation, 'submission_pending_at' => now()->toISOString()]);
        $this->submit($build);

        return $build;
    }

    /** Retry from stock or reactivate a previously verified immutable set after an explicit recipe revert. */
    public function requeueRecipe(Node $node, TaskVmImageBuild $build, int $generation, bool $restart): TaskVmImageBuild
    {
        $build = DB::transaction(function () use ($node, $build, $generation, $restart): TaskVmImageBuild {
            $changed = TaskVmImageBuild::query()->whereKey($build->id)->where('state', $build->state)
                ->where('attempt', $build->attempt)->update(['attempt' => $build->attempt + 1]);
            if ($changed !== 1) {
                throw new TaskVmException('task_vm.image_build_running', 'Another command has claimed this recipe build.');
            }
            $pruned = false;
            if (! $restart) {
                $images = $this->incus->images($this->settings->host($node->id), $node);
                $pruned = array_any($build->images, static fn (array $image): bool => ! in_array($image['alias'], $images[$image['fingerprint']] ?? [], true));
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
                'current_program' => null, 'cleanup_started_at' => null, 'submission_pending_at' => now()->toISOString()])->save();

            return $build;
        });
        $this->submit($build, $build->current_step === TaskVmImageBuildStep::Promote);

        return $build;
    }

    public function resumeLatest(Node $node): TaskVmImageBuild
    {
        $this->recoverSubmissions();
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
        $this->recoverSubmissions();
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
    }

    private function resumabilityError(TaskVmImageBuild $build): ?string
    {
        if ($build->recipe_identity === null) {
            return 'Legacy image builds cannot resume. Build the current recipe instead.';
        }

        return app(RecipeImageBuildSteps::class)->resumabilityError($build);
    }

    /** Fail an unacknowledged submission after a crash; never expire a build a worker has claimed. */
    public function recoverSubmissions(): void
    {
        foreach (TaskVmImageBuild::query()->where('state', TaskVmImageBuildState::Running)
            ->where('submission_pending_at', '<=', now()->subMinutes(10)->toISOString())->get() as $build) {
            $this->failSubmission($build, new RuntimeException('Image build queue submission was not acknowledged within ten minutes.'));
        }
    }

    private function submit(TaskVmImageBuild $build, bool $resume = false): void
    {
        // Include any caller's transaction: acknowledging before its after-commit enqueue would
        // recreate the crash window. The durable marker remains until this callback actually runs.
        DB::afterCommit(function () use ($build, $resume): void {
            try {
                $this->dispatch($build, $resume);
            } catch (Throwable $error) {
                $this->failSubmission($build, $error);
                throw $error;
            }
            // Match this submission: a late acknowledgement cannot clear a later resume's marker.
            TaskVmImageBuild::query()->whereKey($build->id)->where('submission_pending_at', $build->submission_pending_at)
                ->update(['submission_pending_at' => null]);
            $build->refresh();
        });
    }

    private function failSubmission(TaskVmImageBuild $build, Throwable $error): void
    {
        DB::transaction(function () use ($build, $error): void {
            $changed = TaskVmImageBuild::query()->whereKey($build->id)->where('state', TaskVmImageBuildState::Running)
                ->where('submission_pending_at', $build->submission_pending_at)->where('attempt', $build->attempt)
                ->update(['submission_pending_at' => null]);
            if ($changed === 1) {
                $failed = TaskVmImageBuild::query()->findOrFail($build->id);
                if ($failed->current_step === null) {
                    $failed->forceFill(['current_step' => TaskVmImageBuildStep::Launch, 'steps' => [[
                        'step' => TaskVmImageBuildStep::Launch->value, 'program' => null,
                        'started_at' => $build->submission_pending_at, 'seconds' => null, 'error' => null,
                    ]]])->save();
                }
                ImageBuildJob::recordFailure($build->id, $build->attempt, $failed->current_step ?? TaskVmImageBuildStep::Launch, $failed->current_program, $error);
            }
        });
    }

    /** @return list<ImageBuildJob> */
    public function jobs(TaskVmImageBuild $build): array
    {
        $jobs = [];
        foreach (['reset', 'launch', 'boot', 'packages', 'javascript', 'rust', 'agent', 'pi', 'prepare', 'clean', 'publish', 'smoke'] as $phase) {
            $jobs[] = new BuildRecipeImageStep($build->id, 'base', $phase);
        }
        $jobs[] = new PromoteImage($build->id);
        $jobs[] = new PruneRecipeImages($build->id);
        foreach ($jobs as $job) {
            $job->buildAttempt = $build->attempt;
        }

        return $jobs;
    }

    private function dispatch(TaskVmImageBuild $build, bool $resume = false): void
    {
        $jobs = $this->jobs($build);
        if ($resume) {
            while ($jobs !== [] && ($jobs[0]->step() !== $build->current_step || $jobs[0]->program() !== $build->current_program)) {
                array_shift($jobs);
            }
        }
        if ($jobs === []) {
            throw new TaskVmException('task_vm.image_build_failed', 'The failed step is not in the current image recipe. Start a new build instead.');
        }
        Bus::chain($jobs)->onConnection('gateway')->onQueue('builds')->dispatch();
    }
}
