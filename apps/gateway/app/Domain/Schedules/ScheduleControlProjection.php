<?php

declare(strict_types=1);

namespace App\Domain\Schedules;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Safe admitted input and bounded Schedule domain projection only. */
final readonly class ScheduleControlProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct, private ScheduleOperationCompletion $completion) {}

    public function input(Operation $operation): array
    {
        return Arr::only($operation->input, ['id', 'user', 'working_directory']);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        $code = ValidatedData::nullableString($data['error_code'] ?? null);
        $reported = ValidatedData::object($data['result'] ?? []);
        $result = match ($status) {
            OperationStatus::Succeeded => ['ready' => true],
            OperationStatus::Failed => ['error_code' => $code],
            default => [],
        };
        if (($status === OperationStatus::Succeeded && ($reported['ready'] ?? null) !== true)
            || ($status === OperationStatus::Failed && ($code === null || ($reported['error_code'] ?? null) !== $code))
            || ($status !== OperationStatus::Failed && $code !== null)) {
            throw new ResourceOperationException('operation.result_invalid', 'The Schedule needs a bounded native outcome.', 422);
        }

        return DB::transaction(function () use ($operation, $data, $status, $code, $result): Operation {
            $operation->refresh();
            $terminal = $operation->status->terminal();
            $projected = $this->direct->project($operation, $data, $result, 'schedule-control');
            if (! $terminal && $status->terminal()) {
                $outcome = match ($status) {
                    OperationStatus::Succeeded => OperationOutcome::succeeded($result),
                    OperationStatus::Failed => OperationOutcome::failed(ValidatedData::string($code), $result),
                    OperationStatus::Cancelled => OperationOutcome::cancelled(),
                    default => throw new \LogicException('A terminal Schedule status is required.'),
                };
                $this->completion->complete($projected, $outcome);
            }

            return $projected;
        });
    }
}
