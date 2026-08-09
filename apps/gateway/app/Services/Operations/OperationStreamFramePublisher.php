<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\OperationEvent;
use App\Models\OperationRun;
use Illuminate\Support\Carbon;
use Orbit\Core\Operations\DurableOperationStreamFrame;
use Orbit\Core\Operations\OperationStreamFrameDraft;
use Orbit\Core\Operations\OperationStreamFrameSource;
use Orbit\Core\Operations\OperationStreamFrameType;
use RuntimeException;
use Throwable;

final class OperationStreamFramePublisher
{
    private int $sequence = 0;

    public function __construct(
        private readonly OperationRun $operationRun,
        private readonly OperationRunRecorder $operationRuns,
        private readonly OperationStreamFrameBroadcaster $broadcaster,
    ) {}

    /** @param array<string, mixed> $payload */
    public function publish(OperationStreamFrameType $type, array $payload): void
    {
        $draft = OperationStreamFrameDraft::forGateway(
            operationUuid: $this->operationRun->id,
            channel: $this->channel(),
            sequence: ++$this->sequence,
            emittedAt: Carbon::now()->toIso8601String(),
            type: $type,
            payload: $payload,
        );
        $event = $this->operationRuns->appendOperationStreamFrame(
            $this->operationRun->id,
            $draft,
            OperationStreamFrameSource::gateway(),
        );
        $frame = DurableOperationStreamFrame::fromArray($this->frameArray($event));

        try {
            $this->broadcaster->broadcast($this->channel(), $frame);
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }

    private function channel(): string
    {
        return "private-operations.{$this->operationRun->id}";
    }

    /**
     * @return array<array-key, mixed>
     */
    private function frameArray(OperationEvent $event): array
    {
        $frame = $event->payload['frame'] ?? null;

        if (! is_array($frame)) {
            throw new RuntimeException('Recorded operation stream frame payload is invalid.');
        }

        return $frame;
    }
}
