<?php

declare(strict_types=1);

namespace App\Domain\Schedules;

use App\Data\Schedules\ScheduleData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Operations\OperationBroadcaster;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Operation;
use App\Models\Schedule;
use App\Support\ValidatedData;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Records one terminal calendar fire of schedule-<id> as a task with trigger `schedule` (ADR 0244). */
final readonly class ScheduleFires
{
    public function __construct(private OperationBroadcaster $operations, private RecordEventBroadcaster $records) {}

    /** @param array<string,mixed> $run Exact native history of the terminal root run. */
    public function record(string $scheduleId, array $run): void
    {
        $schedule = Schedule::query()->find($scheduleId);
        $tags = is_array($run['tags'] ?? null) ? $run['tags'] : [];
        $tag = static fn (string $key): string => is_string($tags[$key] ?? null) ? $tags[$key] : '';
        // The workflow's tags carry the owner, so a fire that outlived its Schedule is still recorded.
        $nodeId = $schedule instanceof Schedule ? $schedule->host_node_id : (int) $tag('orbit_node_id');
        $targetId = $schedule instanceof Schedule ? $schedule->target_id : (int) $tag('orbit_target_id');
        $owner = $schedule instanceof Schedule ? NativeSchedules::owner($schedule) : $tag('orbit_target_type');
        $runId = ValidatedData::string($run['id']);
        if ($nodeId < 1 || $targetId < 1 || ! in_array($owner, ['instance_schedules', 'node_schedules'], true)) {
            return;
        }
        $succeeded = $run['status'] === 'succeeded';
        $started = Carbon::parse(ValidatedData::string($run['startedAt']));
        $finished = Carbon::parse(ValidatedData::string($run['completedAt']));
        try {
            $operation = DB::transaction(function () use ($schedule, $scheduleId, $run, $runId, $nodeId, $targetId, $owner, $succeeded, $started, $finished): Operation {
                $operation = Operation::query()->create([
                    'kind' => 'schedule.run', 'idempotency_key' => 'schedule-fire:'.$runId,
                    'target_type' => $owner, 'target_id' => $targetId, 'node_id' => $nodeId,
                    'input' => ['id' => $scheduleId, 'node_id' => $nodeId],
                    'state' => ['swamp_fire' => ['workflow_id' => $run['workflowId'], 'run_id' => $run['id'], 'native_status' => $run['status'] ?? null]],
                    'status' => $succeeded ? OperationStatus::Succeeded : OperationStatus::Failed, 'sequence' => 1, 'attempts' => 0,
                    'result' => $succeeded ? ['ready' => true] : ['error_code' => 'schedule.run_failed'],
                    'error_code' => $succeeded ? null : 'schedule.run_failed',
                    'triggered_by' => 'schedule', 'started_at' => $started, 'finished_at' => $finished,
                ]);
                if ($schedule instanceof Schedule && $schedule->status !== LifecycleStatus::Removing
                    && ($schedule->last_run_at === null || $schedule->last_run_at->lessThan($finished))) {
                    $schedule->update(['last_run_at' => $finished, 'last_run_status' => $succeeded ? ScheduleRunStatus::Success : ScheduleRunStatus::Error]);
                }

                return $operation;
            });
        } catch (UniqueConstraintViolationException) {
            return;
        }
        $this->operations->created($operation);
        if ($schedule instanceof Schedule && $schedule->exists) {
            $this->records->broadcast(RecordEventType::ScheduleUpdated, $schedule->id, ScheduleData::fromModel($schedule->refresh())->toArray());
        }
    }
}
