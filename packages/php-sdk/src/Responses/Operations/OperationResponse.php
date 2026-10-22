<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Operations;

use InvalidArgumentException;
use Orbit\Sdk\Responses\Instances\InstancePublishResult;
use SensitiveParameter;
use stdClass;

/**
 * One operation that the Gateway runs on its queue, such as an Instance deploy. `steps` holds the steps that the read
 * returned: every step, only those after `after_sequence`, or none for a list or a queued operation.
 */
final readonly class OperationResponse
{
    public const array TerminalStatuses = ['succeeded', 'failed', 'cancelled'];

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>|null  $result
     * @param  list<OperationStepResponse>  $steps
     */
    public function __construct(
        public int $id,
        public string $kind,
        public string $targetType,
        public int $targetId,
        public array $input,
        public string $status,
        public int $sequence,
        public ?string $currentStep,
        public ?string $failedStep,
        public ?string $errorCode,
        public ?array $result,
        public bool $cancelRequested,
        public int $attempts,
        public ?string $triggeredBy,
        public ?string $createdAt,
        public ?string $startedAt,
        public ?string $finishedAt,
        public ?int $pollAfterSeconds,
        public array $steps,
        public string $requestId,
    ) {}

    public function publishResult(): ?InstancePublishResult
    {
        return $this->kind === 'instance.publish' && $this->status === 'succeeded' && $this->result !== null
            ? InstancePublishResult::fromGatewayData($this->result) : null;
    }

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(#[SensitiveParameter] array $data, string $requestId): self
    {
        $id = $data['id'] ?? null;
        $kind = $data['kind'] ?? null;
        $targetType = $data['target_type'] ?? null;
        $targetId = $data['target_id'] ?? null;
        $status = $data['status'] ?? null;
        $sequence = $data['sequence'] ?? null;

        if (! is_int($id) || $id < 1 || ! is_string($kind) || ! is_string($targetType) || ! is_int($targetId)
            || ! is_string($status) || ! is_int($sequence)) {
            throw new InvalidArgumentException('Gateway response contains an invalid operation.');
        }

        $steps = [];

        foreach (is_array($data['steps'] ?? null) ? $data['steps'] : [] as $step) {
            if (! is_array($step)) {
                throw new InvalidArgumentException('Gateway response contains an invalid operation step.');
            }

            $steps[] = OperationStepResponse::fromGatewayData(self::map($step) ?? []);
        }

        return new self(
            id: $id,
            kind: $kind,
            targetType: $targetType,
            targetId: $targetId,
            input: self::map($data['input'] ?? null) ?? [],
            status: $status,
            sequence: $sequence,
            currentStep: self::text($data, 'current_step'),
            failedStep: self::text($data, 'failed_step'),
            errorCode: self::text($data, 'error_code'),
            result: self::map($data['result'] ?? null),
            cancelRequested: ($data['cancel_requested'] ?? false) === true,
            attempts: is_int($data['attempts'] ?? null) ? $data['attempts'] : 0,
            triggeredBy: self::text($data, 'triggered_by'),
            createdAt: self::text($data, 'created_at'),
            startedAt: self::text($data, 'started_at'),
            finishedAt: self::text($data, 'finished_at'),
            pollAfterSeconds: is_int($data['poll_after_seconds'] ?? null) ? $data['poll_after_seconds'] : null,
            steps: $steps,
            requestId: $requestId,
        );
    }

    public function terminal(): bool
    {
        return in_array($this->status, self::TerminalStatuses, true);
    }

    public function succeeded(): bool
    {
        return $this->status === 'succeeded';
    }

    /** @return array<string, mixed> */
    public function toArray(bool $withSteps = true): array
    {
        $data = [
            'id' => $this->id,
            'kind' => $this->kind,
            'target_type' => $this->targetType,
            'target_id' => $this->targetId,
            'input' => $this->input === [] ? new stdClass : $this->input,
            'status' => $this->status,
            'sequence' => $this->sequence,
            'current_step' => $this->currentStep,
            'failed_step' => $this->failedStep,
            'error_code' => $this->errorCode,
            'result' => $this->result,
            'cancel_requested' => $this->cancelRequested,
            'attempts' => $this->attempts,
            'triggered_by' => $this->triggeredBy,
            'created_at' => $this->createdAt,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'poll_after_seconds' => $this->pollAfterSeconds,
        ];

        if ($withSteps) {
            $data['steps'] = array_map(static fn (OperationStepResponse $step): array => $step->toArray(), $this->steps);
        }

        return [...$data, 'request_id' => $this->requestId];
    }

    /** @param array<string, mixed> $data */
    private static function text(array $data, string $key): ?string
    {
        return is_string($data[$key] ?? null) ? $data[$key] : null;
    }

    /** @return array<string, mixed>|null */
    private static function map(#[SensitiveParameter] mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $map = [];

        foreach ($value as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }
}
