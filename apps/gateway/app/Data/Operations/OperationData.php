<?php

declare(strict_types=1);

namespace App\Data\Operations;

use App\Domain\Operations\OperationPoll;
use App\Models\Operation;
use DateTimeInterface;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** One operation without its steps (docs/reference/operations.md#the-record). `operation:show` adds `steps`. */
#[MapOutputName(SnakeCaseMapper::class)]
final class OperationData extends Data
{
    public function __construct(
        public int $id,
        public string $kind,
        public string $targetType,
        public int $targetId,
        /** @var array<string, mixed>|list<never> */
        public array $input,
        public string $status,
        public int $sequence,
        public ?string $currentStep,
        public ?string $failedStep,
        public ?string $errorCode,
        /** @var array<string, mixed>|null */
        public ?array $result,
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
    ) {}

    public static function fromModel(Operation $operation, ?string $callerNodeName = null): self
    {
        return new self(
            id: $operation->id,
            kind: $operation->kind,
            targetType: $operation->target_type,
            targetId: $operation->target_id,
            input: $operation->input,
            status: $operation->status->value,
            sequence: $operation->sequence,
            currentStep: $operation->current_step,
            failedStep: $operation->failed_step,
            errorCode: $operation->error_code,
            result: $operation->result,
            cancelRequested: $operation->cancel_requested_at !== null,
            attempts: $operation->attempts,
            triggeredBy: $operation->triggered_by,
            callerNodeId: $operation->callerNodeId(),
            callerNodeName: $operation->callerNodeId() === null ? null : $callerNodeName,
            createdAt: $operation->created_at?->format(DateTimeInterface::ATOM),
            startedAt: $operation->started_at?->format(DateTimeInterface::ATOM),
            finishedAt: $operation->finished_at?->format(DateTimeInterface::ATOM),
            pollAfterSeconds: OperationPoll::afterSeconds($operation),
            assistance: self::assistance($operation),
        );
    }

    /** @return array{error_code:string}|null */
    private static function assistance(Operation $operation): ?array
    {
        $flag = $operation->state['executor_assistance'] ?? null;
        $code = is_array($flag) ? ($flag['error_code'] ?? null) : null;

        return is_string($code) && in_array($code, ['operation.child_unproven', 'operation.kind_unavailable'], true)
            ? ['error_code' => $code] : null;
    }
}
