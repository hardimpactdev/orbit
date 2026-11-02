<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Schedules\NativeSchedules;
use App\Domain\Schedules\ScheduleOperationAdmission;
use App\Domain\Schedules\ScheduleTrigger;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Schedule;
use Illuminate\Console\Command;

/**
 * One-off move of an existing Node timer Schedule to its own Swamp workflow (ADR 0244, 2026-11-02).
 * First the native trigger is published, then the old timer units are removed; the record keeps its UUID.
 * Delete this command once the existing Schedules have moved.
 */
final class MoveScheduleToSwampCommand extends Command
{
    protected $signature = 'schedules:move-to-swamp {schedule : Schedule UUID} {cron : The calendar as a cron expression in UTC}';

    protected $description = 'Move one Node timer Schedule to its own Swamp workflow, then remove its timer units';

    public function handle(NativeSchedules $native, ScheduleOperationAdmission $admission, StartOperationAction $operations): int
    {
        $schedule = Schedule::query()->findOrFail($this->argument('schedule'));
        if ($schedule->status !== LifecycleStatus::Active) {
            $this->error('The Schedule is not active.');

            return self::FAILURE;
        }
        if ($schedule->trigger === ScheduleTrigger::Node) {
            // The timer input is captured before the record changes; its removal reads only the UUID and the target.
            $removal = $admission->inputFor($schedule, 'destroy');
            $schedule->update(['trigger' => ScheduleTrigger::Swamp, 'calendar' => (string) $this->argument('cron')]);
            $native->create($schedule, false, null, null);
            $operation = $operations->execute('schedule.destroy', NativeSchedules::owner($schedule), $schedule->target_id, $schedule->host_node_id,
                [...$removal, 'retire_timer' => true], 'operator', null);
            $this->info("Published schedule-{$schedule->id}; timer removal is Operation {$operation->id}.");
        }

        return self::SUCCESS;
    }
}
