<?php

declare(strict_types=1);

namespace App\Domain\Schedules;

use App\Data\Schedules\ScheduleData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Operation;
use App\Models\Schedule;

final readonly class ScheduleOperationCompletion implements OperationCompletion
{
    public function __construct(private RecordEventBroadcaster $broadcaster) {}

    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        $schedule = Schedule::query()->whereKey($operation->input['id'])->firstOrFail();
        if ($outcome->status !== OperationStatus::Succeeded) {
            $schedule->update(['status' => $operation->kind === 'schedule.destroy' ? LifecycleStatus::Removing : ($operation->kind === 'schedule.create' ? LifecycleStatus::Failed : $schedule->status),
                'error_code' => $outcome->errorCode, 'failed_step' => $operation->steps()->where('status', 'failed')->value('name')]);

            return;
        }
        if ($operation->kind === 'schedule.destroy') {
            $schedule->delete();
            $this->broadcaster->broadcast(RecordEventType::ScheduleDeleted, $schedule->id, ['id' => $schedule->id, 'name' => $schedule->name]);

            return;
        }
        $schedule->update(['status' => LifecycleStatus::Active, 'failed_step' => null, 'error_code' => null,
            ...($operation->kind === 'schedule.enable' ? ['desired_timer_state' => DesiredTimerState::Enabled] : [])]);
        if ($operation->kind !== 'schedule.run') {
            $this->broadcaster->broadcast(($operation->input['created'] ?? false) ? RecordEventType::ScheduleCreated : RecordEventType::ScheduleUpdated,
                $schedule->id, ScheduleData::fromModel($schedule)->toArray());
        }
    }
}
