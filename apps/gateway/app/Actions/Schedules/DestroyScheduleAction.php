<?php

declare(strict_types=1);

namespace App\Actions\Schedules;

use App\Domain\Operations\AwaitOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Schedules\ScheduleOperationAdmission;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Schedule;
use SensitiveParameter;

final readonly class DestroyScheduleAction
{
    public function execute(#[SensitiveParameter] Schedule $schedule, bool $cascade = false): Schedule
    {
        $operation = $this->admit($schedule, $cascade);
        try {
            $operation = app(AwaitOperation::class)->execute($operation);
            if ($operation->status !== OperationStatus::Succeeded) {
                throw new ResourceOperationException($operation->error_code ?? 'operation.failed', 'The runtime Operation did not succeed.', 409);
            }
        } catch (ResourceOperationException $exception) {
            if ($exception->errorCode !== 'schedule.run_active') {
                throw $exception;
            }
        }

        return $schedule;
    }

    public function admit(Schedule $schedule, bool $cascade = false, ?Node $caller = null, ?string $requestId = null): Operation
    {
        return app(ScheduleOperationAdmission::class)->execute($schedule, 'destroy', $cascade, $caller, $requestId);
    }
}
