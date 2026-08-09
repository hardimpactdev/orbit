<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\OperationEvent;
use App\Models\OperationRun;
use App\Services\Operations\DatabaseLockRetry;
use App\Services\Operations\OperationEventRecorder;
use App\Services\Operations\OperationPayloadRejected;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\ResultBoundaryRedactionPolicy;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Orbit\Core\Operations\OperationStreamFrameDraft;
use Orbit\Core\Operations\OperationStreamFrameSource;
use Orbit\Core\Operations\OperationStreamFrameType;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->operationRuns = app(OperationRunRecorder::class);
    $this->recorder = app(OperationEventRecorder::class);
    $this->run = $this->operationRuns->queued((string) Str::uuid(), 'gateway', operationType: 'update:all');
    $this->node = Node::factory()->create(['name' => 'app-dev-1']);
});

it('records complete cursor-bearing operation stream frames', function (): void {
    $draft = OperationStreamFrameDraft::forNode(
        operationUuid: $this->run->id,
        channel: "private-operations.{$this->run->id}",
        sequence: 7,
        emittedAt: '2026-08-09T12:00:00+00:00',
        type: OperationStreamFrameType::Stdout,
        payload: ['data' => "published\n", 'encoding' => 'utf-8'],
    );

    $event = $this->recorder->operationStreamFrame(
        $this->run,
        $draft,
        OperationStreamFrameSource::node($this->node->id, $this->node->name),
    );

    $expected = gateway_operation_stream_fixture('node-durable-frame.json');
    $expected['operation_uuid'] = $this->run->id;
    $expected['channel'] = "private-operations.{$this->run->id}";
    $expected['source_node']['id'] = $this->node->id;
    $expected['durable_replay_cursor'] = [
        'operation_uuid' => $this->run->id,
        'event_sequence' => $event->sequence,
        'event_id' => $event->id,
    ];

    expect($event->event_type)
        ->toBe('operation_stream.frame')
        ->and($event->payload['frame'])
        ->toBe($expected)
        ->and($event->refresh()->payload['frame'])
        ->toBe($expected);
});

it('rolls back the journal insert when completing the durable frame fails', function (): void {
    $draft = OperationStreamFrameDraft::forNode(
        operationUuid: $this->run->id,
        channel: "private-operations.{$this->run->id}",
        sequence: 1,
        emittedAt: '2026-08-09T12:00:00+00:00',
        type: OperationStreamFrameType::Stdout,
        payload: ['data' => "published\n", 'encoding' => 'utf-8'],
    );
    $source = OperationStreamFrameSource::node($this->node->id, $this->node->name);

    Event::listen(
        'eloquent.updating: '.OperationEvent::class,
        static function (): never {
            throw new RuntimeException('forced durable frame save failure');
        },
    );

    expect(fn (): OperationEvent => $this->recorder->operationStreamFrame(
        $this->run,
        $draft,
        $source,
    ))
        ->toThrow(RuntimeException::class, 'forced durable frame save failure');

    expect(
        OperationEvent::query()
            ->where('operation_run_id', $this->run->id)
            ->count(),
    )
        ->toBe(0);
});

it('appends ordered durable operation events', function (): void {
    $tree = $this->recorder->tree($this->run, 'Update all', [
        ['key' => 'gateway', 'label' => 'Update gateway'],
    ]);

    $step = $this->recorder->step($this->run, 'gateway', 'running', 'Updating gateway');
    $complete = $this->recorder->complete($this->run, 0, ['version' => '1.2.3']);

    expect($tree)
        ->toBeInstanceOf(OperationEvent::class)
        ->and($tree->sequence)
        ->toBe(1)
        ->and($step->sequence)
        ->toBe(2)
        ->and($complete->sequence)
        ->toBe(3)
        ->and($complete->payload)
        ->toMatchArray([
            'exit_code' => 0,
            'data' => ['version' => '1.2.3'],
        ])
        ->and($this->run->events()->orderBy('sequence')->pluck('event_type')->all())
        ->toBe(['tree', 'step', 'complete']);
});

it('appends terminal error events with metadata', function (): void {
    $event = $this->recorder->error(
        $this->run,
        message: 'Gateway health failed',
        exitCode: 17,
        data: ['code' => 'gateway_health_failed'],
        metadata: ['phase' => 'gateway'],
    );

    expect($event->event_type)
        ->toBe('error')
        ->and($event->payload)
        ->toMatchArray([
            'exit_code' => 17,
            'message' => 'Gateway health failed',
            'data' => ['code' => 'gateway_health_failed'],
        ])
        ->and($event->metadata)
        ->toMatchArray(['phase' => 'gateway']);
});

it('appends multiple step events in one ordered batch', function (): void {
    $events = $this->recorder->steps($this->run, [
        [
            'key' => 'check-updates',
            'status' => 'done',
            'message' => 'Done: latest version is 1.2.3',
        ],
        [
            'key' => 'check-fleet-versions',
            'status' => 'running',
            'message' => 'Checking',
        ],
    ]);

    expect($events)
        ->toHaveCount(2)
        ->and($events[0]->sequence)
        ->toBe(1)
        ->and($events[1]->sequence)
        ->toBe(2)
        ->and($events[0]->payload)
        ->toMatchArray([
            'key' => 'check-updates',
            'status' => 'done',
            'message' => 'Done: latest version is 1.2.3',
        ])
        ->and($events[1]->payload)
        ->toMatchArray([
            'key' => 'check-fleet-versions',
            'status' => 'running',
            'message' => 'Checking',
        ])
        ->and($this->run->events()->orderBy('sequence')->pluck('sequence')->all())
        ->toBe([1, 2]);
});

it('rejects event payloads with forbidden secret keys before writing rows', function (): void {
    expect(fn () => $this->recorder->append($this->run, 'step', [
        'key' => 'gateway',
        'status' => 'running',
        'password' => 'secret',
    ]))->toThrow(OperationPayloadRejected::class, 'operation.progress_unsafe');

    expect(OperationEvent::query()->where('operation_run_id', $this->run->id)->count())->toBe(0);
});

it('rejects event payload values that embed PEM blocks before writing rows', function (): void {
    expect(fn () => $this->recorder->append($this->run, 'step', [
        'key' => 'gateway',
        'status' => 'running',
        'message' => "-----BEGIN PRIVATE KEY-----\nsecret\n-----END PRIVATE KEY-----",
    ]))->toThrow(OperationPayloadRejected::class, 'operation.progress_unsafe');

    expect(OperationEvent::query()->where('operation_run_id', $this->run->id)->count())->toBe(0);
});

it('uses SQLite defaults that support concurrent event reads and writes', function (): void {
    expect(config('database.connections.sqlite.busy_timeout'))
        ->toBe(5000)
        ->and(config('database.connections.sqlite.journal_mode'))
        ->toBe('wal')
        ->and(config('database.connections.sqlite.synchronous'))
        ->toBe('NORMAL');
});

it('retries append transactions when SQLite reports the database is locked', function (): void {
    $retry = new class extends DatabaseLockRetry {
        public bool $failNextTransaction = true;

        public int $databaseLockRetries = 0;

        protected function runTransaction(\Closure $callback): mixed
        {
            if ($this->failNextTransaction) {
                $this->failNextTransaction = false;

                throw new QueryException(
                    'sqlite',
                    'insert into "operation_events" ("operation_run_id", "sequence") values (?, ?)',
                    [],
                    new \PDOException('SQLSTATE[HY000]: General error: 5 database is locked', 5),
                );
            }

            return parent::runTransaction($callback);
        }

        protected function beforeDatabaseLockRetry(QueryException $exception, int $attempt): void
        {
            $this->databaseLockRetries++;
        }
    };

    $recorder = new OperationEventRecorder(app(ResultBoundaryRedactionPolicy::class), $retry);

    $event = $recorder->step($this->run, 'workload.mini', 'running', 'Updating workload node mini');

    expect($event->sequence)
        ->toBe(1)
        ->and($event->payload)
        ->toMatchArray([
            'key' => 'workload.mini',
            'status' => 'running',
            'message' => 'Updating workload node mini',
        ])
        ->and($retry->databaseLockRetries)
        ->toBe(1);
});

it('retries append transactions after a sequence unique race and recomputes the next sequence', function (): void {
    $retry = new class($this->run) extends DatabaseLockRetry {
        private bool $failNextTransaction = true;

        public int $databaseLockRetries = 0;

        public function __construct(
            private OperationRun $run,
        ) {}

        protected function runTransaction(\Closure $callback): mixed
        {
            if ($this->failNextTransaction) {
                $this->failNextTransaction = false;

                OperationEvent::query()->create([
                    'operation_run_id' => $this->run->id,
                    'sequence' => 1,
                    'event_type' => 'step',
                    'payload' => ['key' => 'competing', 'status' => 'done'],
                ]);

                throw operation_event_unique_sequence_exception();
            }

            return parent::runTransaction($callback);
        }

        protected function beforeDatabaseLockRetry(QueryException $exception, int $attempt): void
        {
            $this->databaseLockRetries++;
        }
    };

    $recorder = new OperationEventRecorder(app(ResultBoundaryRedactionPolicy::class), $retry);

    $event = $recorder->step($this->run, 'workload.main1', 'running', 'Updating workload node main1');

    expect($event->sequence)
        ->toBe(2)
        ->and($event->payload)
        ->toMatchArray([
            'key' => 'workload.main1',
            'status' => 'running',
            'message' => 'Updating workload node main1',
        ])
        ->and($this->run->events()->orderBy('sequence')->pluck('sequence')->all())
        ->toBe([1, 2])
        ->and($retry->databaseLockRetries)
        ->toBe(1);
});

it('retries batched step transactions when SQLite reports the database is locked', function (): void {
    $retry = new class extends DatabaseLockRetry {
        public bool $failNextTransaction = true;

        public int $databaseLockRetries = 0;

        protected function runTransaction(\Closure $callback): mixed
        {
            if ($this->failNextTransaction) {
                $this->failNextTransaction = false;

                throw new QueryException(
                    'sqlite',
                    'insert into "operation_events" ("operation_run_id", "sequence") values (?, ?)',
                    [],
                    new \PDOException('SQLSTATE[HY000]: General error: 5 database is locked', 5),
                );
            }

            return parent::runTransaction($callback);
        }

        protected function beforeDatabaseLockRetry(QueryException $exception, int $attempt): void
        {
            $this->databaseLockRetries++;
        }
    };

    $recorder = new OperationEventRecorder(app(ResultBoundaryRedactionPolicy::class), $retry);

    $events = $recorder->steps($this->run, [
        [
            'key' => 'workload.mini',
            'status' => 'running',
            'message' => 'Updating workload node mini',
        ],
        [
            'key' => 'workload.mini',
            'status' => 'done',
            'message' => 'Workload node mini updated',
        ],
    ]);

    expect($events)
        ->toHaveCount(2)
        ->and($events[0]->sequence)
        ->toBe(1)
        ->and($events[1]->sequence)
        ->toBe(2)
        ->and($retry->databaseLockRetries)
        ->toBe(1);
});

it('retries batched step transactions after a sequence unique race and recomputes the next sequence', function (): void {
    $retry = new class($this->run) extends DatabaseLockRetry {
        private bool $failNextTransaction = true;

        public int $databaseLockRetries = 0;

        public function __construct(
            private OperationRun $run,
        ) {}

        protected function runTransaction(\Closure $callback): mixed
        {
            if ($this->failNextTransaction) {
                $this->failNextTransaction = false;

                OperationEvent::query()->create([
                    'operation_run_id' => $this->run->id,
                    'sequence' => 1,
                    'event_type' => 'step',
                    'payload' => ['key' => 'competing', 'status' => 'done'],
                ]);

                throw operation_event_unique_sequence_exception();
            }

            return parent::runTransaction($callback);
        }

        protected function beforeDatabaseLockRetry(QueryException $exception, int $attempt): void
        {
            $this->databaseLockRetries++;
        }
    };

    $recorder = new OperationEventRecorder(app(ResultBoundaryRedactionPolicy::class), $retry);

    $events = $recorder->steps($this->run, [
        [
            'key' => 'workload.main1',
            'status' => 'running',
            'message' => 'Updating workload node main1',
        ],
        [
            'key' => 'workload.main1',
            'status' => 'done',
            'message' => 'Workload node main1 updated',
        ],
    ]);

    expect($events)
        ->toHaveCount(2)
        ->and($events[0]->sequence)
        ->toBe(2)
        ->and($events[1]->sequence)
        ->toBe(3)
        ->and($this->run->events()->orderBy('sequence')->pluck('sequence')->all())
        ->toBe([1, 2, 3])
        ->and($retry->databaseLockRetries)
        ->toBe(1);
});

function operation_event_unique_sequence_exception(): QueryException
{
    $previous = new \PDOException(
        'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: operation_events.operation_run_id, operation_events.sequence',
        19,
    );
    $previous->errorInfo = [
        '23000',
        19,
        'UNIQUE constraint failed: operation_events.operation_run_id, operation_events.sequence',
    ];

    return new QueryException(
        'sqlite',
        'insert into "operation_events" ("operation_run_id", "sequence") values (?, ?)',
        [],
        $previous,
    );
}

/**
 * @return array<string, mixed>
 */
function gateway_operation_stream_fixture(string $name): array
{
    $contents = file_get_contents(
        dirname(__DIR__, 6).'/packages/core/tests/Fixtures/Operations/'.$name,
    );

    if (! is_string($contents)) {
        throw new RuntimeException("Unable to read operation stream fixture [{$name}].");
    }

    $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($decoded)) {
        throw new RuntimeException("Operation stream fixture [{$name}] must be an object.");
    }

    return $decoded;
}
