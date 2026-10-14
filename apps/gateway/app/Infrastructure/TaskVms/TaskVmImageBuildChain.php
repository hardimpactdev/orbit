<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmImageBuildStep;
use App\Domain\TaskVms\TaskVmSettings;
use App\Jobs\TaskVms\ImageBuild\AwaitImageBuilderBoot;
use App\Jobs\TaskVms\ImageBuild\CleanImageBuilder;
use App\Jobs\TaskVms\ImageBuild\CleanupImageBuild;
use App\Jobs\TaskVms\ImageBuild\ImageBuildJob;
use App\Jobs\TaskVms\ImageBuild\LaunchImageBuilder;
use App\Jobs\TaskVms\ImageBuild\PrepareImageBoot;
use App\Jobs\TaskVms\ImageBuild\PromoteImage;
use App\Jobs\TaskVms\ImageBuild\PublishImage;
use App\Jobs\TaskVms\ImageBuild\RunImageProgram;
use App\Jobs\TaskVms\ImageBuild\SmokeImage;
use App\Jobs\TaskVms\ImageBuild\UpgradeImagePackages;
use App\Models\Node;
use App\Models\TaskVmImageBuild;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final readonly class TaskVmImageBuildChain
{
    public function __construct(private TaskVmSettings $settings, private IncusTaskVmBaseImage $image, private TaskVmImageRecipe $recipe, private IncusTaskVmImageSync $sync) {}

    public function start(Node $node, bool $fromStock = false): TaskVmImageBuild
    {
        $this->recoverSubmissions();
        $build = $this->sync->admitBuild($node, fn (): TaskVmImageBuild => TaskVmImageBuild::query()->create(['host_node_id' => $node->id, 'from_stock' => $fromStock, 'submission_pending_at' => now()->toISOString()]));
        $this->submit($build);

        return $build;
    }

    public function resume(Node $node, int $id): TaskVmImageBuild
    {
        $this->recoverSubmissions();
        $build = $this->sync->admitBuild($node, function () use ($node, $id): TaskVmImageBuild {
            $build = TaskVmImageBuild::query()->where('host_node_id', $node->id)->find($id)
                ?? throw new TaskVmException('task_vm.image_build_failed', "Build [{$id}] does not belong to this host.");
            if ($build->state !== TaskVmImageBuildState::Failed || $build->cleanup_started_at !== null || $build->current_step === null || ! array_key_exists((string) $build->builder_vm_name, $this->image->instances($this->settings->host($node->id), $node))) {
                throw new TaskVmException('task_vm.image_build_failed', 'Only an unclaimed failed build whose builder still exists can resume.');
            }
            // A conditional write, not SELECT FOR UPDATE (which SQLite ignores), arbitrates against cleanup.
            DB::transaction(function () use ($build): void {
                $claimed = TaskVmImageBuild::query()->whereKey($build->id)->where('state', TaskVmImageBuildState::Failed)
                    ->whereNull('cleanup_started_at')->update(['submission_pending_at' => now()->toISOString()]);
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

            return $build;
        });
        $this->submit($build, true);

        return $build;
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
                // Recheck after a caller's transaction: its admission lease may have expired
                // before the row became visible. Never enqueue steps beside an active sync.
                $this->sync->admitBuild($build->hostNode, fn () => $this->dispatch($build, $resume));
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
                ->where('submission_pending_at', $build->submission_pending_at)
                ->update(['state' => TaskVmImageBuildState::Failed, 'submission_pending_at' => null]);
            if ($changed === 1) {
                $failed = TaskVmImageBuild::query()->findOrFail($build->id);
                if ($failed->current_step === null) {
                    $failed->forceFill(['current_step' => TaskVmImageBuildStep::Launch, 'steps' => [[
                        'step' => TaskVmImageBuildStep::Launch->value, 'program' => null,
                        'started_at' => $build->submission_pending_at, 'seconds' => null, 'error' => null,
                    ]]])->save();
                }
                ImageBuildJob::recordFailure($build->id, $error);
            }
        });
    }

    /** @return list<ImageBuildJob> */
    public function jobs(TaskVmImageBuild $build): array
    {
        $id = $build->id;
        $programs = array_keys($this->recipe->programs());
        $programs[] = 'agent-binary';
        if ($this->settings->piArtifactPath !== null && $this->settings->piArtifactSha256 !== null) {
            $programs[] = 'pi-artifact';
        }

        return [new LaunchImageBuilder($id), new AwaitImageBuilderBoot($id), new UpgradeImagePackages($id),
            ...array_map(static fn (string $name): RunImageProgram => new RunImageProgram($id, $name), $programs),
            new PrepareImageBoot($id), new CleanImageBuilder($id), new PublishImage($id), new SmokeImage($id), new PromoteImage($id)];
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
        $id = $build->id;
        Bus::chain($jobs)->onConnection('gateway')->onQueue('builds')->catch(static function (Throwable $error) use ($id): void {
            // The error is recorded before dispatch: cleanup carries only the durable build id.
            ImageBuildJob::recordFailure($id, $error);
            CleanupImageBuild::dispatch($id)->onConnection('gateway')->onQueue('builds');
        })->dispatch();
    }
}
