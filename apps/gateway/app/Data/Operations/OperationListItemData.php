<?php

declare(strict_types=1);

namespace App\Data\Operations;

use App\Data\Tasks\TaskPlaceWaitData;
use App\Models\Operation;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** One row of operation:list: the record without input, result and steps (docs/reference/operations.md#page-the-list). */
#[MapOutputName(SnakeCaseMapper::class)]
final class OperationListItemData extends Data
{
    public function __construct(
        public int $id,
        public string $kind,
        public string $targetType,
        public int $targetId,
        public string $status,
        public int $sequence,
        public ?string $currentStep,
        public ?string $failedStep,
        public ?string $errorCode,
        public bool $cancelRequested,
        public int $attempts,
        public ?string $triggeredBy,
        public ?int $callerNodeId,
        public ?string $callerNodeName,
        public ?string $createdAt,
        public ?string $startedAt,
        public ?string $finishedAt,
        public ?int $pollAfterSeconds,
        /** @var array{error_code:string}|null */
        public ?array $assistance = null,
        /** @var array{code:?string,detail:string,stderr_recorded?:bool}|null */
        public ?array $error = null,
        public ?string $currentStepStartedAt = null,
        public ?string $currentStepObservedAt = null,
        public ?TaskPlaceWaitData $capacityWait = null,
    ) {}

    public static function fromModel(Operation $operation, ?string $callerNodeName = null): self
    {
        $record = OperationData::fromModel($operation, $callerNodeName);

        return new self(
            id: $record->id,
            kind: $record->kind,
            targetType: $record->targetType,
            targetId: $record->targetId,
            status: $record->status,
            sequence: $record->sequence,
            currentStep: $record->currentStep,
            failedStep: $record->failedStep,
            errorCode: $record->errorCode,
            cancelRequested: $record->cancelRequested,
            attempts: $record->attempts,
            triggeredBy: $record->triggeredBy,
            callerNodeId: $record->callerNodeId,
            callerNodeName: $record->callerNodeName,
            createdAt: $record->createdAt,
            startedAt: $record->startedAt,
            finishedAt: $record->finishedAt,
            pollAfterSeconds: $record->pollAfterSeconds,
            assistance: $record->assistance,
            error: $record->error,
            currentStepStartedAt: $record->currentStepStartedAt,
            currentStepObservedAt: $record->currentStepObservedAt,
            capacityWait: $record->capacityWait,
        );
    }
}
