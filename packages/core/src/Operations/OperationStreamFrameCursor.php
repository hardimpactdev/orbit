<?php

declare(strict_types=1);

namespace Orbit\Core\Operations;

use InvalidArgumentException;

final readonly class OperationStreamFrameCursor
{
    public function __construct(
        public string $operationUuid,
        public int $eventSequence,
        public int $eventId,
    ) {
        if ($this->operationUuid === '') {
            throw new InvalidArgumentException('Cursor operation_uuid must be a non-empty string.');
        }

        if ($this->eventSequence < 1) {
            throw new InvalidArgumentException('Cursor event_sequence must be a positive integer.');
        }

        if ($this->eventId < 1) {
            throw new InvalidArgumentException('Cursor event_id must be a positive integer.');
        }
    }

    /**
     * @param  array<array-key, mixed>  $cursor
     */
    public static function fromArray(array $cursor): self
    {
        if (
            ! isset($cursor['operation_uuid'])
            || ! is_string($cursor['operation_uuid'])
            || $cursor['operation_uuid'] === ''
        ) {
            throw new InvalidArgumentException('Cursor operation_uuid must be a non-empty string.');
        }

        if (
            ! isset($cursor['event_sequence'])
            || ! is_int($cursor['event_sequence'])
            || $cursor['event_sequence'] < 1
        ) {
            throw new InvalidArgumentException('Cursor event_sequence must be a positive integer.');
        }

        if (! isset($cursor['event_id']) || ! is_int($cursor['event_id']) || $cursor['event_id'] < 1) {
            throw new InvalidArgumentException('Cursor event_id must be a positive integer.');
        }

        return new self(
            $cursor['operation_uuid'],
            $cursor['event_sequence'],
            $cursor['event_id'],
        );
    }

    public function resumeSequence(): int
    {
        return $this->eventSequence;
    }

    /**
     * @return array{operation_uuid: string, event_sequence: int, event_id: int}
     */
    public function toArray(): array
    {
        return [
            'operation_uuid' => $this->operationUuid,
            'event_sequence' => $this->eventSequence,
            'event_id' => $this->eventId,
        ];
    }
}
