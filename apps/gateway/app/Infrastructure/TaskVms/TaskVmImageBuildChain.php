<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmImageBuildStep;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Jobs\TaskVms\ImageBuild\AwaitImageBuilderBoot;
use App\Jobs\TaskVms\ImageBuild\BuildRecipeImageStep;
use App\Jobs\TaskVms\ImageBuild\CleanImageBuilder;
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
    public function __construct(private TaskVmSettings $settings, private IncusTaskVmBaseImage $image, private TaskVmImageRecipe $recipe, private IncusTaskVmImageSync $sync, private IncusHost $incus) {}

    public function start(Node $node, bool $fromStock = false, ?MachineRecipe $recipe = null, ?int $generation = null): TaskVmImageBuild
    {
        $this->recoverSubmissions();
        $build = $this->sync->admitBuild($node, fn (): TaskVmImageBuild => TaskVmImageBuild::query()->create(['host_node_id' => $node->id, 'from_stock' => $fromStock, 'recipe_identity' => $recipe?->identity, 'recipe_json' => $recipe?->json, 'recipe_generation' => $generation, 'submission_pending_at' => now()->toISOString()]));
        $this->submit($build);

        return $build;
    }

    /** Retry from stock or reactivate a previously verified immutable set after an explicit recipe revert. */
    public function requeueRecipe(Node $node, TaskVmImageBuild $build, int $generation, bool $restart): TaskVmImageBuild
    {
        $build = $this->sync->admitBuild($node, fn (): TaskVmImageBuild => DB::transaction(function () use ($build, $generation, $restart): TaskVmImageBuild {
            $changed = TaskVmImageBuild::query()->whereKey($build->id)->where('state', $build->state)
                ->where('attempt', $build->attempt)->update(['attempt' => $build->attempt + 1]);
            if ($changed !== 1) {
                throw new TaskVmException('task_vm.image_build_running', 'Another command has claimed this recipe build.');
            }
            $steps = $restart ? [] : array_values(array_filter($build->steps, static fn (array $entry): bool => $entry['step'] !== TaskVmImageBuildStep::Promote->value));
            $build->refresh()->forceFill(['state' => TaskVmImageBuildState::Running, 'recipe_generation' => $generation,
                'steps' => $steps, 'current_step' => $restart ? null : TaskVmImageBuildStep::Promote,
                'current_program' => null, 'cleanup_started_at' => null, 'submission_pending_at' => now()->toISOString()])->save();

            return $build;
        }));
        $this->submit($build, ! $restart);

        return $build;
    }

    public function resumeLatest(Node $node): TaskVmImageBuild
    {
        $this->recoverSubmissions();
        foreach (TaskVmImageBuild::query()->where('host_node_id', $node->id)->where('state', TaskVmImageBuildState::Failed)
            ->whereNull('cleanup_started_at')->whereNotNull('current_step')->orderByDesc('id')->get() as $build) {
            if ($this->resumabilityError($node, $build) === null) {
                return $this->resume($node, $build->id);
            }
        }

        throw new TaskVmException('task_vm.image_build_failed', 'No resumable failed image build exists on this host.');
    }

    public function resume(Node $node, int $id): TaskVmImageBuild
    {
        $this->recoverSubmissions();
        $build = $this->sync->admitBuild($node, function () use ($node, $id): TaskVmImageBuild {
            $build = TaskVmImageBuild::query()->where('host_node_id', $node->id)->find($id)
                ?? throw new TaskVmException('task_vm.image_build_failed', "Build [{$id}] does not belong to this host.");
            if ($build->state !== TaskVmImageBuildState::Failed || $build->cleanup_started_at !== null || $build->current_step === null) {
                throw new TaskVmException('task_vm.image_build_failed', 'Only an unclaimed failed build can resume.');
            }
            $error = $this->resumabilityError($node, $build);
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

            return $build;
        });
        $this->submit($build, true);

        return $build;
    }

    private function resumabilityError(Node $node, TaskVmImageBuild $build): ?string
    {
        if ($build->recipe_identity !== null) {
            return app(RecipeImageBuildSteps::class)->resumabilityError($build);
        }
        $host = $this->settings->host($node->id);
        $needsBuilder = true;
        if (in_array($build->current_step, [TaskVmImageBuildStep::Publish, TaskVmImageBuildStep::Smoke, TaskVmImageBuildStep::Promote], true)) {
            $alias = $host->imageAlias.'-'.$build->created_at?->format('YmdHis').'-'.$build->id;
            $published = $this->incus->aliasTarget($host, $node, $alias);
            $needsBuilder = $published === null || ($published !== $build->image_fingerprint
                && ! ($build->current_step === TaskVmImageBuildStep::Publish && $build->image_fingerprint === null));
            if ($needsBuilder && ($build->current_step !== TaskVmImageBuildStep::Publish || $build->image_fingerprint !== null)) {
                return 'The failed build\'s published image no longer exists.';
            }
        }
        if ($needsBuilder && ! array_key_exists((string) $build->builder_vm_name, $this->image->instances($host, $node))) {
            return 'Only an unclaimed failed build whose builder still exists can resume before publish.';
        }

        return null;
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
        $id = $build->id;
        if ($build->recipe_identity !== null) {
            $jobs = [];
            foreach (['base', ...RecipeImageBuilds::Roles] as $profile) {
                $phases = $profile === 'base'
                    ? ['reset', 'launch', 'boot', 'packages', 'javascript', 'rust', 'agent', 'pi', 'prepare', 'clean', 'publish', 'smoke']
                    : ['launch', 'boot', 'packages', 'javascript', 'rust', 'agent', 'pi', 'clean', 'publish', 'smoke'];
                foreach ($phases as $phase) {
                    $jobs[] = new BuildRecipeImageStep($id, $profile, $phase);
                }
            }
            $jobs[] = new PromoteImage($id);
            foreach ($jobs as $job) {
                $job->buildAttempt = $build->attempt;
            }

            return $jobs;
        }
        $programs = array_keys($this->recipe->programs());
        $programs[] = 'agent-binary';
        if ($this->settings->piArtifactPath !== null && $this->settings->piArtifactSha256 !== null) {
            $programs[] = 'pi-artifact';
        }

        $jobs = [new LaunchImageBuilder($id), new AwaitImageBuilderBoot($id), new UpgradeImagePackages($id),
            ...array_map(static fn (string $name): RunImageProgram => new RunImageProgram($id, $name), $programs),
            new PrepareImageBoot($id), new CleanImageBuilder($id), new PublishImage($id), new SmokeImage($id), new PromoteImage($id)];
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
