<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\OperationEvent;
use App\Models\OperationRun;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class OperationEventRecorder
{
    public function __construct(
        private ResultBoundaryRedactionPolicy $redaction,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $metadata
     */
    public function append(OperationRun|string $operationRun, string $eventType, array $payload, array $metadata = []): OperationEvent
    {
        return $this->appendMany($operationRun, [[
            'event_type' => $eventType,
            'payload' => $payload,
            'metadata' => $metadata,
        ]])[0];
    }

    /**
     * @param  list<array{event_type: string, payload: array<string, mixed>, metadata?: array<string, mixed>}>  $events
     * @return list<OperationEvent>
     */
    public function appendMany(OperationRun|string $operationRun, array $events): array
    {
        if ($events === []) {
            return [];
        }

        $events = array_map($this->normalizeEvent(...), $events);

        foreach ($events as $event) {
            $this->redaction->assertSafe([
                'payload' => $event['payload'],
                'metadata' => $event['metadata'],
            ], 'progress');
        }

        return DB::transaction(function () use ($operationRun, $events): array {
            $operationRun = $this->findOrFail($operationRun);

            $sequence = (int) OperationEvent::query()
                ->where('operation_run_id', $operationRun->id)
                ->lockForUpdate()
                ->max('sequence') + 1;

            $created = [];

            foreach ($events as $event) {
                $created[] = OperationEvent::query()->create([
                    'operation_run_id' => $operationRun->id,
                    'sequence' => $sequence,
                    'event_type' => $event['event_type'],
                    'payload' => $event['payload'],
                    'metadata' => $event['metadata'] === [] ? null : $event['metadata'],
                ]);

                $sequence++;
            }

            return $created;
        });
    }

    /**
     * @param  list<array{key: string, label: string, doneLabel?: string}>  $steps
     * @param  array<string, mixed>  $metadata
     */
    public function tree(OperationRun|string $operationRun, string $title, array $steps, array $metadata = []): OperationEvent
    {
        return $this->append($operationRun, 'tree', [
            'title' => $title,
            'steps' => $steps,
        ], $metadata);
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
        return $this->append($operationRun, 'step', $this->stepPayload($key, $status, $message, $payloadExtras), $metadata);
    }

    /**
     * @param  list<array{key: string, status: string, message?: string|null, metadata?: array<string, mixed>, payloadExtras?: array<string, mixed>}>  $steps
     * @return list<OperationEvent>
     */
    public function steps(OperationRun|string $operationRun, array $steps): array
    {
        return $this->appendMany($operationRun, array_map(fn (array $step): array => [
            'event_type' => 'step',
            'payload' => $this->stepPayload(
                $step['key'],
                $step['status'],
                $step['message'] ?? null,
                $step['payloadExtras'] ?? [],
            ),
            'metadata' => $step['metadata'] ?? [],
        ], $steps));
    }

    /**
     * @param  array<string, mixed>  $payloadExtras
     * @return array<string, mixed>
     */
    private function stepPayload(string $key, string $status, ?string $message = null, array $payloadExtras = []): array
    {
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

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $metadata
     */
    public function complete(OperationRun|string $operationRun, int $exitCode, array $data = [], array $metadata = []): OperationEvent
    {
        return $this->append($operationRun, 'complete', [
            'exit_code' => $exitCode,
            'data' => $data,
        ], $metadata);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $metadata
     */
    public function error(OperationRun|string $operationRun, string $message, int $exitCode = 1, array $data = [], array $metadata = []): OperationEvent
    {
        return $this->append($operationRun, 'error', [
            'exit_code' => $exitCode,
            'message' => $message,
            'data' => $data,
        ], $metadata);
    }

    /**
     * @param  array{event_type?: mixed, payload?: mixed, metadata?: mixed}  $event
     * @return array{event_type: string, payload: array<string, mixed>, metadata: array<string, mixed>}
     */
    private function normalizeEvent(array $event): array
    {
        $eventType = is_string($event['event_type'] ?? null)
            ? trim($event['event_type'])
            : '';

        if ($eventType === '') {
            throw new RuntimeException('Operation event type cannot be empty.');
        }

        $payload = $event['payload'] ?? null;

        if (! is_array($payload)) {
            throw new RuntimeException('Operation event payload must be an array.');
        }

        $metadata = $event['metadata'] ?? [];

        if (! is_array($metadata)) {
            throw new RuntimeException('Operation event metadata must be an array.');
        }

        return [
            'event_type' => $eventType,
            'payload' => $payload,
            'metadata' => $metadata,
        ];
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
