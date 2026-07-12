<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\OperationEvent;
use App\Models\OperationRun;
use Illuminate\Support\Carbon;
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
    public function publish(string $type, array $payload): void
    {
        $frame = [
            'operation_uuid' => $this->operationRun->id,
            'channel' => $this->channel(),
            'sequence' => ++$this->sequence,
            'emitted_at' => Carbon::now()->toIso8601String(),
            'source' => 'gateway',
            'type' => $type,
            'payload' => $payload,
            'durable_replay_cursor' => [
                'operation_uuid' => $this->operationRun->id,
                'event_sequence' => null,
                'event_id' => null,
            ],
        ];

        $event = $this->operationRuns->appendEvent($this->operationRun->id, 'operation_stream.frame', [
            'frame' => $frame,
        ]);
        $frame['durable_replay_cursor'] = [
            'operation_uuid' => $this->operationRun->id,
            'event_sequence' => $event->sequence,
            'event_id' => $event->id,
        ];
        $this->persistCursor($event, $frame);

        try {
            $this->broadcaster->broadcast($this->channel(), $frame);
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }

    /** @param array<string, mixed> $frame */
    private function persistCursor(OperationEvent $event, array $frame): void
    {
        $event->forceFill(['payload' => ['frame' => $frame]])->save();
    }

    private function channel(): string
    {
        return "private-operations.{$this->operationRun->id}";
    }
}
