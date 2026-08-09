<?php

declare(strict_types=1);

namespace Orbit\Core\Operations;

use InvalidArgumentException;

/** @mago-expect lint:cyclomatic-complexity -- This class validates the complete six-field operation stream frame contract. */
final readonly class OperationStreamFrameDraft
{
    /**
     * @param  array<string, mixed>  $payload
     *
     * @mago-expect lint:excessive-parameter-list -- The constructor mirrors the six required wire fields.
     */
    private function __construct(
        public string $operationUuid,
        public string $channel,
        public int $sequence,
        public string $emittedAt,
        public OperationStreamFrameType $type,
        public array $payload,
    ) {
        if ($this->operationUuid === '') {
            throw new InvalidArgumentException('Frame operation_uuid must be a non-empty string.');
        }

        if ($this->channel === '') {
            throw new InvalidArgumentException('Frame channel must be a non-empty string.');
        }

        if ($this->sequence < 1) {
            throw new InvalidArgumentException('Frame sequence must be a positive integer.');
        }

        if ($this->emittedAt === '') {
            throw new InvalidArgumentException('Frame emitted_at must be a non-empty string.');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @mago-expect lint:excessive-parameter-list -- Gateway producers must provide every required wire field explicitly.
     */
    public static function forGateway(
        string $operationUuid,
        string $channel,
        int $sequence,
        string $emittedAt,
        OperationStreamFrameType $type,
        array $payload,
    ): self {
        if (! $type->isGateway()) {
            throw new InvalidArgumentException(
                "Frame type [{$type->value}] is not valid for a gateway producer.",
            );
        }

        return new self(
            $operationUuid,
            $channel,
            $sequence,
            $emittedAt,
            $type,
            self::payloadFrom($payload),
        );
    }

    /**
     * @param  array<array-key, mixed>  $payload
     *
     * @mago-expect lint:excessive-parameter-list -- Node producers must provide every required wire field explicitly.
     */
    public static function forNode(
        string $operationUuid,
        string $channel,
        int $sequence,
        string $emittedAt,
        OperationStreamFrameType $type,
        array $payload,
    ): self {
        if (! $type->isNode()) {
            throw new InvalidArgumentException(
                "Frame type [{$type->value}] is not valid for a node producer.",
            );
        }

        return new self(
            $operationUuid,
            $channel,
            $sequence,
            $emittedAt,
            $type,
            self::payloadFrom($payload),
        );
    }

    /**
     * @param  array<array-key, mixed>  $frame
     */
    public static function fromArray(array $frame): self
    {
        if (
            ! isset($frame['operation_uuid'])
            || ! is_string($frame['operation_uuid'])
            || $frame['operation_uuid'] === ''
        ) {
            throw new InvalidArgumentException('Frame operation_uuid must be a non-empty string.');
        }

        if (! isset($frame['channel']) || ! is_string($frame['channel']) || $frame['channel'] === '') {
            throw new InvalidArgumentException('Frame channel must be a non-empty string.');
        }

        if (! isset($frame['sequence']) || ! is_int($frame['sequence']) || $frame['sequence'] < 1) {
            throw new InvalidArgumentException('Frame sequence must be a positive integer.');
        }

        if (! isset($frame['emitted_at']) || ! is_string($frame['emitted_at']) || $frame['emitted_at'] === '') {
            throw new InvalidArgumentException('Frame emitted_at must be a non-empty string.');
        }

        if (! isset($frame['type']) || ! is_string($frame['type'])) {
            throw new InvalidArgumentException('Frame type is invalid.');
        }

        $type = OperationStreamFrameType::tryFrom($frame['type']);

        if ($type === null) {
            throw new InvalidArgumentException('Frame type is invalid.');
        }

        $payload = self::payloadFrom($frame['payload'] ?? null);

        return new self(
            $frame['operation_uuid'],
            $frame['channel'],
            $frame['sequence'],
            $frame['emitted_at'],
            $type,
            $payload,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function payloadFrom(mixed $payload): array
    {
        if (! is_array($payload)) {
            throw new InvalidArgumentException('Frame payload must be an array.');
        }

        $validated = [];

        foreach ($payload as $key => $value) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Frame payload keys must be strings.');
            }

            $validated[$key] = $value;
        }

        return $validated;
    }

    /**
     * @return array{
     *     operation_uuid: string,
     *     channel: string,
     *     sequence: int,
     *     emitted_at: string,
     *     type: string,
     *     payload: array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        return [
            'operation_uuid' => $this->operationUuid,
            'channel' => $this->channel,
            'sequence' => $this->sequence,
            'emitted_at' => $this->emittedAt,
            'type' => $this->type->value,
            'payload' => $this->payload,
        ];
    }
}
