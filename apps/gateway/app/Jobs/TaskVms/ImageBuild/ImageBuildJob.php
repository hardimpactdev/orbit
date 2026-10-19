<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms\ImageBuild;

use App\Domain\Fleet\RecipeMaintenanceAlerts;
use App\Domain\Releases\ReleaseIncident;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmImageBuildStep;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Infrastructure\TaskVms\RecipeImageBuildSteps;
use App\Models\TaskVmImageBuild;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

abstract class ImageBuildJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    // A lost acknowledgement must re-enter handle to dispatch the successor of a completed step.
    // Execution errors explicitly fail below; queue delivery/continuation errors remain retryable.
    public int $tries = 0;

    public int $buildAttempt = 1;

    public function __construct(public int $buildId) {}

    abstract public function step(): TaskVmImageBuildStep;

    public function program(): ?string
    {
        return null;
    }

    public function handle(RecipeImageBuildSteps $work): void
    {
        // Serialize the submission acknowledgement with recovery's conditional failure write.
        $build = DB::transaction(function (): ?TaskVmImageBuild {
            $claimed = TaskVmImageBuild::query()->whereKey($this->buildId)->where('state', TaskVmImageBuildState::Running)
                ->where('attempt', $this->buildAttempt)->whereNull('cleanup_started_at')->where(function (Builder $query): void {
                    // Redelivery of an older completed step is not acknowledgement of a new resume.
                    $query->whereNull('submission_pending_at')->orWhere(fn (Builder $entry): Builder => $entry
                        ->where('current_step', $this->step())->where('current_program', $this->program()));
                    if ($this instanceof BuildRecipeImageStep && $this->profile === 'base' && $this->phase === 'reset') {
                        $query->orWhereNull('current_step');
                    }
                })->update(['submission_pending_at' => null]);

            return $claimed === 1 ? TaskVmImageBuild::query()->findOrFail($this->buildId) : null;
        });
        if ($build === null) {
            return;
        }
        $steps = $build->steps;
        $index = null;
        foreach ($steps as $key => $entry) {
            if ($entry['step'] === $this->step()->value && $entry['program'] === $this->program()) {
                if ($entry['seconds'] !== null && $entry['error'] === null) {
                    return;
                }
                $index = $key;
                break;
            }
        }
        $index ??= count($steps);
        $steps[$index] = [...($steps[$index] ?? []), 'step' => $this->step()->value, 'program' => $this->program(), 'started_at' => $steps[$index]['started_at'] ?? now()->toISOString(), 'seconds' => null, 'error' => null];
        $build->forceFill(['current_step' => $this->step(), 'current_program' => $this->program(), 'steps' => $steps])->save();
        try {
            if (! $work->run($build, $this->step(), $this->program())) {
                $this->release(5);

                return;
            }
        } catch (Throwable $error) {
            $this->failed($error);
            // Unlimited polling releases do not permit retries of a reported boot or step error.
            $this->fail($error);
            throw $error;
        }
        $steps[$index] = $build->steps[$index] ?? throw new \LogicException('The completed image phase has no persisted build step.');
        $steps[$index]['seconds'] = max(0.0, now()->getTimestampMs() / 1000 - Carbon::parse($steps[$index]['started_at'])->getTimestampMs() / 1000);
        $build->steps = $steps;
        if ($this->step() === TaskVmImageBuildStep::Prune) {
            $build->state = TaskVmImageBuildState::Succeeded;
        }
        $build->save();
    }

    /** Laravel invokes chain catch callbacks before failed(), including on pre-handle failures. */
    public function invokeChainCatchCallbacks(Throwable $error): void
    {
        $this->failed($error);
        foreach ($this->chainCatchCallbacks ?? [] as $callback) {
            $callback($error);
        }
    }

    /** Also called by the worker for failures before handle, including exhausted redeliveries. */
    public function failed(?Throwable $error): void
    {
        if ($error !== null) {
            self::recordFailure($this->buildId, $this->buildAttempt, $this->step(), $this->program(), $error);
        }
    }

    public static function recordFailure(int $id, int $attempt, TaskVmImageBuildStep $step, ?string $program, Throwable $error): void
    {
        DB::transaction(function () use ($id, $attempt, $step, $program, $error): void {
            // Acquire SQLite's writer fence before reading steps, and never regress a terminal or newer attempt.
            $claimed = TaskVmImageBuild::query()->whereKey($id)->where('attempt', $attempt)
                ->where('state', TaskVmImageBuildState::Running)->whereNull('cleanup_started_at')
                ->toBase()->update(['attempt' => $attempt]);
            if ($claimed !== 1) {
                return;
            }
            $build = TaskVmImageBuild::query()->findOrFail($id);
            $steps = $build->steps;
            $index = array_find_key($steps, static fn (array $entry): bool => $entry['step'] === $step->value && $entry['program'] === $program);
            if ($index !== null && $steps[$index]['seconds'] !== null && $steps[$index]['error'] === null) {
                // A lost acknowledgement normally retries continuation. But failOnTimeout deletes
                // the delivery, so a timeout at the current completion boundary must allow resume.
                // A late timeout from an older step must not regress a successor already at work.
                if (! $error instanceof TimeoutExceededException || $build->current_step !== $step || $build->current_program !== $program) {
                    return;
                }
            }
            $index ??= count($steps);
            $started = $steps[$index]['started_at'] ?? now()->toISOString();
            $steps[$index] = [...($steps[$index] ?? []), 'step' => $step->value, 'program' => $program, 'started_at' => $started,
                'seconds' => max(0.0, now()->getTimestampMs() / 1000 - Carbon::parse($started)->getTimestampMs() / 1000),
                'error' => mb_substr(new CommandActivityInputSanitizer()->redactText($error->getMessage()), 0, 4000)];
            $build->forceFill(['steps' => $steps, 'state' => TaskVmImageBuildState::Failed, 'current_step' => $step, 'current_program' => $program])->save();
            if ($build->recipe_identity !== null && ! $build->restartsAutomatically()) {
                // Only the winning failure write alerts, after its outermost transaction commits.
                DB::afterCommit(static function () use ($build, $step, $program): void {
                    $phase = $program ?? $step->value;
                    app(RecipeMaintenanceAlerts::class)->halted(
                        "Image build [{$build->id}] failed on host [{$build->host_node_id}] during [{$phase}]. Inspect task-vms:image-builds, fix the build, then run task-vms:build-recipe --retry.",
                        $build->id,
                        incident: new ReleaseIncident('recipe-image:'.$build->id, (string) $build->attempt, 'build_failed'),
                    );
                });
            }
        });
    }
}
