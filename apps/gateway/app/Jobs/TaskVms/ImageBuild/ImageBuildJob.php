<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms\ImageBuild;

use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmImageBuildStep;
use App\Infrastructure\TaskVms\TaskVmImageBuildSteps;
use App\Models\TaskVmImageBuild;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

abstract class ImageBuildJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public function __construct(public int $buildId) {}

    abstract public function step(): TaskVmImageBuildStep;

    public function program(): ?string
    {
        return null;
    }

    public function handle(TaskVmImageBuildSteps $work): void
    {
        // Serialize the submission acknowledgement with recovery's conditional failure write.
        $build = DB::transaction(function (): ?TaskVmImageBuild {
            $claimed = TaskVmImageBuild::query()->whereKey($this->buildId)->where('state', TaskVmImageBuildState::Running)
                ->whereNull('cleanup_started_at')->where(function (Builder $query): void {
                    // Redelivery of an older completed step is not acknowledgement of a new resume.
                    $query->whereNull('submission_pending_at')->orWhere(fn (Builder $entry): Builder => $entry
                        ->where('current_step', $this->step())->where('current_program', $this->program()));
                    if ($this->step() === TaskVmImageBuildStep::Launch) {
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
        $steps[$index] = ['step' => $this->step()->value, 'program' => $this->program(), 'started_at' => $steps[$index]['started_at'] ?? now()->toISOString(), 'seconds' => null, 'error' => null];
        $build->forceFill(['current_step' => $this->step(), 'current_program' => $this->program(), 'steps' => $steps])->save();
        try {
            if (! $work->run($build, $this->step(), $this->program())) {
                $this->release(5);

                return;
            }
        } catch (Throwable $error) {
            self::recordFailure($this->buildId, $error);
            // retryUntil permits polling releases, not retries of a reported boot or step error.
            $this->fail($error);
            throw $error;
        }
        $steps[$index]['seconds'] = max(0.0, now()->getTimestampMs() / 1000 - Carbon::parse($steps[$index]['started_at'])->getTimestampMs() / 1000);
        $build->steps = $steps;
        if ($this->step() === TaskVmImageBuildStep::Promote) {
            $build->state = TaskVmImageBuildState::Succeeded;
        }
        $build->save();
    }

    public static function recordFailure(int $id, Throwable $error): void
    {
        $build = TaskVmImageBuild::query()->findOrFail($id);
        $steps = $build->steps;
        foreach ($steps as &$entry) {
            if ($entry['step'] === $build->current_step?->value && $entry['program'] === $build->current_program) {
                $entry['started_at'] ??= now()->toISOString();
                $entry['error'] = mb_substr($error->getMessage(), 0, 4000);
                $entry['seconds'] = max(0.0, now()->getTimestampMs() / 1000 - Carbon::parse($entry['started_at'])->getTimestampMs() / 1000);
            }
        }
        unset($entry);
        $build->forceFill(['steps' => $steps, 'state' => TaskVmImageBuildState::Failed])->save();
    }
}
