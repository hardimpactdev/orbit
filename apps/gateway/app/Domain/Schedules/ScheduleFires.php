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

/** Records one calendar fire of schedule-<id> as a task with trigger `schedule` when it starts, and its result when it ends (ADR 0244). */
final readonly class ScheduleFires
{
    /** A run's budget is the Schedule's timeout plus this margin for the SSH and step overhead. */
    public const int MarginSeconds = 120;

    public function __construct(private OperationBroadcaster $operations, private RecordEventBroadcaster $records) {}

    /**
     * @param  array<string,mixed>  $run  Exact native history of the root run.
     * @param  array<string,mixed>|null  $result  The public result of a run that ended; null while it runs.
     */
    public function record(string $scheduleId, array $run, ?array $result): void
    {
        $schedule = Schedule::query()->find($scheduleId);
        $tags = is_array($run['tags'] ?? null) ? $run['tags'] : [];
        $tag = static fn (string $key): string => is_string($tags[$key] ?? null) ? $tags[$key] : '';
        // The workflow's tags carry the owner, so a fire that outlived its Schedule is still recorded.
        $nodeId = $schedule instanceof Schedule ? $schedule->host_node_id : (int) $tag('orbit_node_id');
        $targetId = $schedule instanceof Schedule ? $schedule->target_id : (int) $tag('orbit_target_id');
        $owner = $schedule instanceof Schedule ? NativeSchedules::owner($schedule) : $tag('orbit_target_type');
        if ($nodeId < 1 || $targetId < 1 || ! in_array($owner, ['instance_schedules', 'node_schedules'], true)) {
            return;
        }
        $runId = ValidatedData::string($run['id']);
        $key = 'schedule-fire:'.$runId;
        $finished = $result === null ? null : Carbon::parse(ValidatedData::string($run['completedAt']));
        $code = $result === null || ($result['ready'] ?? null) === true ? null
            : (is_string($result['error_code'] ?? null) ? $result['error_code'] : 'schedule.run_failed');
        $state = ['swamp_fire' => ['workflow_id' => $run['workflowId'] ?? null, 'run_id' => $runId, 'native_status' => $run['status'] ?? null]];
        $ended = $finished === null ? [] : ['status' => $code === null ? OperationStatus::Succeeded : OperationStatus::Failed,
            'result' => $code === null ? ['ready' => true] : ['error_code' => $code], 'error_code' => $code, 'finished_at' => $finished];
        $created = false;
        $operation = DB::transaction(function () use ($schedule, $scheduleId, $key, $nodeId, $targetId, $owner, $run, $finished, $code, $state, $ended, &$created): ?Operation {
            $operation = Operation::query()->where('kind', 'schedule.run')->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($operation === null) {
                try {
                    $operation = Operation::query()->create([
                        'kind' => 'schedule.run', 'idempotency_key' => $key, 'target_type' => $owner, 'target_id' => $targetId, 'node_id' => $nodeId,
                        'input' => ['id' => $scheduleId, 'node_id' => $nodeId,
                            'run_budget_seconds' => ($schedule->timeout_seconds ?? 86400) + self::MarginSeconds],
                        'state' => $state, 'status' => OperationStatus::Running, 'sequence' => 1, 'attempts' => 1,
                        'triggered_by' => 'schedule', 'started_at' => Carbon::parse(ValidatedData::string($run['startedAt'])), ...$ended,
                    ]);
                    $created = true;
                } catch (UniqueConstraintViolationException) {
                    return null;
                }
            } elseif ($ended !== [] && ! in_array($operation->status, [OperationStatus::Succeeded, OperationStatus::Failed], true)
                || ($ended !== [] && $operation->error_code === 'operation.timed_out')) {
                // The native end replaces a run-budget mark the expiry set while the fire ran.
                $operation->forceFill([...$ended, 'state' => [...($operation->state ?? []), ...$state], 'sequence' => $operation->sequence + 1])->save();
            } else {
                return null;
            }
            if ($finished !== null && $schedule instanceof Schedule && $schedule->status !== LifecycleStatus::Removing
                && ($schedule->last_run_at === null || $schedule->last_run_at->lessThan($finished))) {
                $schedule->update(['last_run_at' => $finished, 'last_run_status' => $code === null ? ScheduleRunStatus::Success : ScheduleRunStatus::Error]);
            }

            return $operation;
        });
        if ($operation === null) {
            return;
        }
        $created ? $this->operations->created($operation) : $this->operations->changed($operation, true);
        if ($finished !== null && $schedule instanceof Schedule && $schedule->exists) {
            $this->records->broadcast(RecordEventType::ScheduleUpdated, $schedule->id, ScheduleData::fromModel($schedule->refresh())->toArray());
        }
    }
}
