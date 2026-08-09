<?php

declare(strict_types=1);

namespace Orbit\Core\Operations;

use InvalidArgumentException;

final readonly class DurableOperationStreamFrame
{
    private function __construct(
        private OperationStreamFrameDraft $draft,
        private OperationStreamFrameSource $source,
        private OperationStreamFrameCursor $cursor,
    ) {}

    public static function promote(
        OperationStreamFrameDraft $draft,
        OperationStreamFrameSource $source,
        OperationStreamFrameCursor $cursor,
    ): self {
        if ($cursor->operationUuid !== $draft->operationUuid) {
            throw new InvalidArgumentException(
                'Cursor operation_uuid must match the frame operation_uuid.',
            );
        }

        if ($source->isGateway() && ! $draft->type->isGateway()) {
            throw new InvalidArgumentException(
                "Frame type [{$draft->type->value}] is not valid for a gateway source.",
            );
        }

        if (! $source->isGateway() && ! $draft->type->isNode()) {
            throw new InvalidArgumentException(
                "Frame type [{$draft->type->value}] is not valid for a node source.",
            );
        }

        return new self($draft, $source, $cursor);
    }

    /**
     * @param  array<array-key, mixed>  $frame
     */
    public static function fromArray(array $frame): self
    {
        if (! isset($frame['durable_replay_cursor']) || ! is_array($frame['durable_replay_cursor'])) {
            throw new InvalidArgumentException('Frame durable_replay_cursor must be an object.');
        }

        return self::promote(
            OperationStreamFrameDraft::fromArray($frame),
            OperationStreamFrameSource::fromArray($frame),
            OperationStreamFrameCursor::fromArray($frame['durable_replay_cursor']),
        );
    }

    public function cursor(): OperationStreamFrameCursor
    {
        return $this->cursor;
    }

    public function source(): OperationStreamFrameSource
    {
        return $this->source;
    }

    public function draft(): OperationStreamFrameDraft
    {
        return $this->draft;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'operation_uuid' => $this->draft->operationUuid,
            'channel' => $this->draft->channel,
            'sequence' => $this->draft->sequence,
            'emitted_at' => $this->draft->emittedAt,
            ...$this->source->toArray(),
            'type' => $this->draft->type->value,
            'payload' => $this->draft->payload,
            'durable_replay_cursor' => $this->cursor->toArray(),
        ];
    }
}
