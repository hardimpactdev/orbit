<?php

declare(strict_types=1);

namespace App\Actions\Schedules;

use App\Domain\Schedules\ScheduleErrorCode;
use App\Domain\Schedules\ScheduleOperationAdmission;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Schedule;

final readonly class RunScheduleAction
{
    public function admit(Schedule $schedule, ?Node $caller = null, ?string $requestId = null): Operation
    {
        if ($schedule->status !== LifecycleStatus::Active) {
            throw new ResourceOperationException(
                ScheduleErrorCode::StateInvalid->value,
                'The Schedule cannot run in its current state.',
                409,
            );
        }

        return app(ScheduleOperationAdmission::class)->execute($schedule, 'run', caller: $caller, requestId: $requestId);
    }
}
