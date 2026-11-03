<?php

declare(strict_types=1);

namespace App\Data\Operations;

use App\Models\Operation;
use DateTimeInterface;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\LaravelData\Optional;

/** The public Operation record. Reads and cancellation use a compact input and observed native steps. */
#[MapOutputName(SnakeCaseMapper::class)]
final class OperationData extends Data
{
    /** @param list<OperationStepData>|Optional $steps */
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
        /** @var array{code:?string,detail:string,stderr_recorded?:bool}|null */
        public ?array $error = null,
        public ?string $currentStepStartedAt = null,
        public ?string $currentStepObservedAt = null,
        public array|Optional $steps = new Optional,
    ) {}

    /** Public reads retain domain results without returning the execution plan. */
    public static function summary(Operation $operation, ?string $callerNodeName = null): self
    {
        $data = self::fromModel($operation, $callerNodeName);
        $data->input = array_filter($operation->input, static fn (mixed $value): bool => $value === null || is_bool($value) || is_int($value) || is_float($value)
            || (is_string($value) && strlen($value) <= 512));
        $data->steps = self::recordedSteps($operation);

        return $data;
    }

    /** @return list<OperationStepData> */
    private static function recordedSteps(Operation $operation): array
    {
        $progress = $operation->state['native_progress'] ?? [];
        $rows = is_array($progress) && is_array($progress['steps'] ?? null) ? $progress['steps'] : [];
        $steps = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['name'] ?? null) || ! is_string($row['job'] ?? null) || ! is_string($row['status'] ?? null)) {
                continue;
            }
            $steps[] = new OperationStepData($row['name'], $row['job'], $row['status'],
                is_string($row['started_at'] ?? null) ? $row['started_at'] : null,
                is_string($row['finished_at'] ?? null) ? $row['finished_at'] : null);
        }

        return $steps;
    }

    private static function progressTime(Operation $operation, string $field): ?string
    {
        $progress = $operation->state['native_progress'] ?? null;
        $active = is_array($progress) && is_array($progress['active'] ?? null) ? $progress['active'] : [];
        $current = $active === [] ? null : end($active);

        return $operation->current_step !== null && is_array($current) && is_string($current[$field] ?? null) ? $current[$field] : null;
    }

    public static function fromModel(Operation $operation, ?string $callerNodeName = null): self
    {
        $failure = $operation->state['native_failure'] ?? null;

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
            pollAfterSeconds: null,
            assistance: null,
            currentStepStartedAt: self::progressTime($operation, 'started_at'),
            currentStepObservedAt: self::progressTime($operation, 'observed_at'),
            error: is_array($failure) && is_string($failure['detail'] ?? null)
                ? ['code' => $operation->error_code, 'detail' => $failure['detail'],
                    ...($operation->error_code !== 'operation.timed_out' ? ['stderr_recorded' => ($failure['stderr_recorded'] ?? false) === true] : [])] : null,
        );
    }
}
