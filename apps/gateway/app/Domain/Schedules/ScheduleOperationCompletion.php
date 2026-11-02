<?php

declare(strict_types=1);

namespace App\Domain\Schedules;

use App\Data\Schedules\ScheduleData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Operation;
use App\Models\Schedule;

/** Record native outcomes only; Swamp owns execution and readiness. */
final readonly class ScheduleOperationCompletion
{
    public function __construct(private RecordEventBroadcaster $broadcaster) {}

    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        $schedule = Schedule::query()->whereKey($operation->input['id'])->first();
        if ($schedule === null) {
            return;
        }
        if ($operation->kind === 'schedule.destroy') {
            if ($outcome->status === OperationStatus::Succeeded) {
                $schedule->delete();
                $this->broadcaster->broadcast(RecordEventType::ScheduleDeleted, $schedule->id, ['id' => $schedule->id, 'name' => $schedule->name]);
            } else {
                $schedule->update(['status' => LifecycleStatus::Removing, 'failed_step' => null, 'error_code' => $outcome->errorCode]);
            }

            return;
        }
        if ($schedule->status === LifecycleStatus::Removing || $outcome->status === OperationStatus::Cancelled) {
            return;
        }
        if ($operation->kind === 'schedule.run') {
            $schedule->update(['last_run_at' => now(), 'last_run_status' => $outcome->status === OperationStatus::Succeeded ? ScheduleRunStatus::Success : ScheduleRunStatus::Error,
                'failed_step' => null, 'error_code' => $outcome->errorCode]);
        } else {
            $schedule->update(['status' => $outcome->status === OperationStatus::Succeeded ? LifecycleStatus::Active : ($operation->kind === 'schedule.create' ? LifecycleStatus::Failed : $schedule->status),
                'failed_step' => null, 'error_code' => $outcome->errorCode,
                ...($operation->kind === 'schedule.enable' && $outcome->status === OperationStatus::Succeeded ? ['desired_timer_state' => DesiredTimerState::Enabled] : [])]);
        }
        $this->broadcaster->broadcast(($operation->input['created'] ?? false) ? RecordEventType::ScheduleCreated : RecordEventType::ScheduleUpdated,
            $schedule->id, ScheduleData::fromModel($schedule)->toArray());
    }
}
