<?php

declare(strict_types=1);

namespace App\Actions\Schedules;

use App\Domain\Schedules\ScheduleErrorCode;
use App\Domain\Schedules\ScheduleOperationAdmission;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Schedule;

final readonly class ActivateScheduleAction
{
    public function admit(Schedule $schedule, ?Node $caller = null, ?string $requestId = null): Operation
    {
        if (! Instance::isMorphType($schedule->target_type)) {
            throw new ResourceOperationException(
                ScheduleErrorCode::TargetInvalid->value,
                'Only an Instance Schedule can be activated.',
                422,
            );
        }

        if ($schedule->status !== LifecycleStatus::Active) {
            throw new ResourceOperationException(
                ScheduleErrorCode::StateInvalid->value,
                'The Schedule cannot be activated in its current state.',
                409,
            );
        }

        return app(ScheduleOperationAdmission::class)->execute($schedule, 'enable', caller: $caller, requestId: $requestId);
    }
}
