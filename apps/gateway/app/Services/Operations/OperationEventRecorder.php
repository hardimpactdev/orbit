<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\OperationEvent;
use App\Models\OperationRun;
use Orbit\Core\Operations\DurableOperationStreamFrame;
use Orbit\Core\Operations\OperationStreamFrameCursor;
use Orbit\Core\Operations\OperationStreamFrameDraft;
use Orbit\Core\Operations\OperationStreamFrameEvents;
use Orbit\Core\Operations\OperationStreamFrameSource;
use RuntimeException;

final readonly class OperationEventRecorder
{
    public function __construct(
        private ResultBoundaryRedactionPolicy $redaction,
        private DatabaseLockRetry $databaseLockRetry,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $metadata
     */
    public function append(
        OperationRun|string $operationRun,
        string $eventType,
        array $payload,
        array $metadata = [],
    ): OperationEvent {
        $eventType = trim($eventType);

        if ($eventType === '') {
            throw new RuntimeException('Operation event type cannot be empty.');
        }

        $this->redaction->assertSafe([
            'payload' => $payload,
            'metadata' => $metadata,
        ], 'progress');

        return $this->databaseLockRetry->transactionRetryingUniqueConstraints(function () use (
            $operationRun,
            $eventType,
            $payload,
            $metadata,
        ): OperationEvent {
            $operationRun = $this->findOrFail($operationRun);

            $sequence =
                (int) OperationEvent::query()
                    ->where('operation_run_id', $operationRun->id)
                    ->lockForUpdate()
                    ->max('sequence') + 1;

            return OperationEvent::query()->create([
                'operation_run_id' => $operationRun->id,
                'sequence' => $sequence,
                'event_type' => $eventType,
                'payload' => $payload,
                'metadata' => $metadata === [] ? null : $metadata,
            ]);
        });
    }

    public function operationStreamFrame(
        OperationRun|string $operationRun,
        OperationStreamFrameDraft $draft,
        OperationStreamFrameSource $source,
    ): OperationEvent {
        $this->redaction->assertSafe([
            'payload' => [
                'frame' => [
                    ...$draft->toArray(),
                    ...$source->toArray(),
                ],
            ],
            'metadata' => [],
        ], 'progress');

        return $this->databaseLockRetry->transactionRetryingUniqueConstraints(function () use (
            $operationRun,
            $draft,
            $source,
        ): OperationEvent {
            $operationRun = $this->findOrFail($operationRun);

            $sequence =
                (int) OperationEvent::query()
                    ->where('operation_run_id', $operationRun->id)
                    ->lockForUpdate()
                    ->max('sequence') + 1;

            $event = OperationEvent::query()->create([
                'operation_run_id' => $operationRun->id,
                'sequence' => $sequence,
                'event_type' => OperationStreamFrameEvents::Journal,
                'payload' => ['frame' => $draft->toArray()],
                'metadata' => null,
            ]);

            $frame = DurableOperationStreamFrame::promote(
                draft: $draft,
                source: $source,
                cursor: new OperationStreamFrameCursor(
                    operationUuid: $operationRun->id,
                    eventSequence: $event->sequence,
                    eventId: $event->id,
                ),
            );
            $payload = ['frame' => $frame->toArray()];

            $this->redaction->assertSafe([
                'payload' => $payload,
                'metadata' => [],
            ], 'progress');

            $event->forceFill(['payload' => $payload])->save();

            return $event->refresh();
        });
    }

    /**
     * @param  list<array{key: string, label: string, doneLabel?: string}>  $steps
     * @param  array<string, mixed>  $metadata
     */
    public function tree(
        OperationRun|string $operationRun,
        string $title,
        array $steps,
        array $metadata = [],
    ): OperationEvent {
        return $this->append(
            $operationRun,
            'tree',
            [
                'title' => $title,
                'steps' => $steps,
            ],
            $metadata,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $payloadExtras
     */
    public function step(
        OperationRun|string $operationRun,
        string $key,
        string $status,
        ?string $message = null,
        array $metadata = [],
        array $payloadExtras = [],
    ): OperationEvent {
        $payload = [
            'key' => $key,
            'status' => $status,
        ];

        if ($message !== null) {
            $payload['message'] = $message;
        }

        if ($payloadExtras !== []) {
            $payload = array_merge($payload, $payloadExtras);
        }

        return $this->append($operationRun, 'step', $payload, $metadata);
    }

    /**
     * @param  list<array{
     *     key: string,
     *     status: string,
     *     message?: string|null,
     *     metadata?: array<string, mixed>,
     *     payload_extras?: array<string, mixed>
     * }>  $steps
     * @return list<OperationEvent>
     */
    public function steps(OperationRun|string $operationRun, array $steps): array
    {
        $records = [];

        foreach ($steps as $step) {
            $payload = [
                'key' => $step['key'],
                'status' => $step['status'],
            ];

            if (array_key_exists('message', $step) && $step['message'] !== null) {
                $payload['message'] = $step['message'];
            }

            $payloadExtras = $step['payload_extras'] ?? [];

            if ($payloadExtras !== []) {
                $payload = array_merge($payload, $payloadExtras);
            }

            $metadata = $step['metadata'] ?? [];

            $this->redaction->assertSafe([
                'payload' => $payload,
                'metadata' => $metadata,
            ], 'progress');

            $records[] = [
                'event_type' => 'step',
                'payload' => $payload,
                'metadata' => $metadata,
            ];
        }

        return $this->databaseLockRetry->transactionRetryingUniqueConstraints(function () use (
            $operationRun,
            $records,
        ): array {
            $operationRun = $this->findOrFail($operationRun);

            $sequence =
                (int) OperationEvent::query()
                    ->where('operation_run_id', $operationRun->id)
                    ->lockForUpdate()
                    ->max('sequence') + 1;

            $events = [];

            foreach ($records as $record) {
                $events[] = OperationEvent::query()->create([
                    'operation_run_id' => $operationRun->id,
                    'sequence' => $sequence,
                    'event_type' => $record['event_type'],
                    'payload' => $record['payload'],
                    'metadata' => $record['metadata'] === [] ? null : $record['metadata'],
                ]);

                $sequence++;
            }

            return $events;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $metadata
     */
    public function complete(
        OperationRun|string $operationRun,
        int $exitCode,
        array $data = [],
        array $metadata = [],
    ): OperationEvent {
        return $this->append(
            $operationRun,
            'complete',
            [
                'exit_code' => $exitCode,
                'data' => $data,
            ],
            $metadata,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $metadata
     */
    public function error(
        OperationRun|string $operationRun,
        string $message,
        int $exitCode = 1,
        array $data = [],
        array $metadata = [],
    ): OperationEvent {
        return $this->append(
            $operationRun,
            'error',
            [
                'exit_code' => $exitCode,
                'message' => $message,
                'data' => $data,
            ],
            $metadata,
        );
    }

    private function findOrFail(OperationRun|string $operationRun): OperationRun
    {
        if ($operationRun instanceof OperationRun) {
            return $operationRun;
        }

        $run = OperationRun::query()->find($operationRun);

        if ($run === null) {
            throw new RuntimeException("OperationRun {$operationRun} not found.");
        }

        return $run;
    }
}
