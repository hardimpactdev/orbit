<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationReconcileHealth;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Swamp\SwampConnection;
use App\Jobs\Operations\ReconcileSwampOperations;
use App\Models\Node;
use App\Models\Operation;
use App\Models\ProblemCollectorState;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(new DateTimeImmutable('2026-11-12T12:00:00Z'));
    Queue::fake();
    Http::preventStrayRequests();
    app(SwampConnection::class)->release();
});

afterEach(function (): void {
    app(SwampConnection::class)->release();
    ProblemCollectorState::flushEventListeners();
    Operation::flushEventListeners();
});

function reconcileHealthNode(string $name = 'gateway', int $address = 1): Node
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => $name, 'status' => 'active', 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.'.$address, 'public_ssh_host' => '192.0.2.'.$address, 'user' => 'orbit']));
    if ($name === 'gateway') {
        $node->roles()->create(['role' => 'gateway', 'status' => 'active']);
    }

    return $node;
}

function reconcileHealthOperation(Node $node, int $target = 42): Operation
{
    return Operation::query()->create(['kind' => 'tasks.script', 'target_type' => 'task_group', 'target_id' => $target, 'node_id' => $node->id,
        'input' => ['run_budget_seconds' => 1800], 'status' => OperationStatus::Running, 'attempts' => 1,
        'started_at' => now()->subMinutes(3), 'state' => ['swamp_run_id' => sprintf('ea55ab37-bcbe-4459-81dc-cbb397e7%04d', $target)]]);
}

/** The bound task-script history shape used by NativeRunCancellationTest::reconcileSocket. */
function reconcileHealthRun(Operation $operation, string $status = 'failed'): array
{
    return ['id' => $operation->state['swamp_run_id'], 'workflowName' => app(DirectOperation::class)->definition($operation->kind)['workflow'], 'status' => $status,
        'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => $operation->kind],
        'startedAt' => '2026-11-12T11:57:00.000Z', 'completedAt' => '2026-11-12T12:00:00.000Z', 'jobs' => []];
}

function reconcileHealthSocket(array $runs, bool $fail = false): WebSocketClient
{
    $socket = new class($runs, $fail) implements WebSocketClient
    {
        public array $frames = [];

        private bool $open = false;

        public function __construct(private readonly array $runs, private readonly bool $fail) {}

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
        {
            $this->open = true;
        }

        public function send(array $message): void
        {
            $this->frames[] = $message;
        }

        public function receive(float $timeoutSeconds): array
        {
            if ($this->fail) {
                throw new RuntimeException('Read unavailable');
            }
            $frame = end($this->frames);
            $data = $frame['type'] === 'workflow.history.get' ? ($this->runs[$frame['payload']['workflowIdOrName']] ?? []) : ['results' => []];

            return [['id' => $frame['id'], 'type' => $frame['type'], 'payload' => ['data' => $data]]];
        }

        public function close(): void
        {
            $this->open = false;
        }

        public function isConnected(): bool
        {
            return $this->open;
        }
    };
    app(SwampConnection::class)->release();
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => __FILE__, 'swamp.read_token' => 'fixture-read-token']);

    return $socket;
}

function reconcileHealthPass(): void
{
    app()->call([new ReconcileSwampOperations, 'handle']);
}

function reconcileHealthIssues(Node $peer): array
{
    return test()->withServerVariables(['REMOTE_ADDR' => $peer->wireguard_ip])->getJson('/api/v1/gateway/status')->assertOk()->json('data.doctor.issues');
}

it('records one completed pass that settles everything and preserves Doctor facts', function (): void {
    Log::spy();
    $gateway = reconcileHealthNode();
    $operation = reconcileHealthOperation($gateway);
    $doctor = ['doctor_collected_at' => '2026-11-12T11:59:00Z', 'doctor_started_at' => '2026-11-12T11:58:00Z', 'doctor_duration_ms' => 500];
    ProblemCollectorState::query()->create(['observation' => $doctor]);
    $writes = 0;
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (str_starts_with($query->sql, 'update "problem_collector_state"')) {
            $writes++;
        }
    });
    $socket = reconcileHealthSocket([$operation->state['swamp_run_id'] => reconcileHealthRun($operation)]);

    reconcileHealthPass();

    $state = ProblemCollectorState::query()->sole()->observation;
    expect($state)->toMatchArray([...$doctor, 'operation_reconcile_finished_at' => '2026-11-12T12:00:00.000000Z',
        'operation_settlement_oldest_completed_at' => null, 'operation_settlement_count' => 0])
        ->and($state['operation_reconcile_duration_ms'])->toBeNumeric()->toBeGreaterThanOrEqual(0)
        ->and($writes)->toBe(1)->and($operation->refresh()->status)->toBe(OperationStatus::Failed)
        ->and(count(array_filter($socket->frames, fn (array $frame): bool => $frame['type'] === 'workflow.history.get')))->toBe(1)
        ->and(reconcileHealthIssues($gateway))->toBe([]);
    Log::shouldNotHaveReceived('debug');
    Log::shouldNotHaveReceived('warning');
});

it('records a terminal read despite a settlement exception then clears it after recovery', function (): void {
    Log::spy();
    $gateway = reconcileHealthNode();
    $operation = reconcileHealthOperation($gateway);
    $run = [...reconcileHealthRun($operation), 'completedAt' => '2026-11-12T11:59:00.000Z'];
    $socket = reconcileHealthSocket([$operation->state['swamp_run_id'] => $run]);
    $broken = true;
    Operation::updating(function (Operation $current) use (&$broken): void {
        if ($broken && $current->status->terminal()) {
            throw new RuntimeException('Projection failed');
        }
    });

    reconcileHealthPass();

    expect($operation->refresh()->status)->toBe(OperationStatus::Running)
        ->and(ProblemCollectorState::query()->sole()->observation)->toMatchArray([
            'operation_reconcile_finished_at' => '2026-11-12T12:00:00.000000Z', 'operation_settlement_count' => 1,
            'operation_settlement_oldest_completed_at' => '2026-11-12T11:59:00.000000Z']);
    Log::shouldHaveReceived('warning')->with('Terminal Swamp runs still need Operation settlement.', [
        'count' => 1, 'oldest_completed_at' => '2026-11-12T11:59:00.000000Z'])->once();
    $this->travelTo(new DateTimeImmutable('2026-11-12T12:04:00Z'));
    expect(reconcileHealthIssues($gateway))->toBe([]);
    $this->travelTo(new DateTimeImmutable('2026-11-12T12:05:01Z'));
    $expected = [
        ['node_id' => $gateway->id, 'node_name' => 'gateway', 'code' => 'gateway.operation_reconcile_stalled', 'count' => 1,
            'since' => '2026-11-12T12:00:00.000000Z', 'newest_since' => '2026-11-12T12:00:00.000000Z', 'resource' => null],
        ['node_id' => $gateway->id, 'node_name' => 'gateway', 'code' => 'gateway.operation_settlement_stuck', 'count' => 1,
            'since' => '2026-11-12T11:59:00.000000Z', 'newest_since' => '2026-11-12T11:59:00.000000Z', 'resource' => null],
    ];
    expect(reconcileHealthIssues($gateway))->toBe($expected);
    fwrite(STDOUT, "\nReconcile groups: ".json_encode($expected, JSON_THROW_ON_ERROR)."\n");
    $broken = false;
    reconcileHealthPass();
    expect($operation->refresh()->status)->toBe(OperationStatus::Failed)
        ->and(ProblemCollectorState::query()->sole()->observation)->toMatchArray(['operation_settlement_count' => 0, 'operation_settlement_oldest_completed_at' => null])
        ->and(reconcileHealthIssues($gateway))->toBe([])
        ->and(count(array_filter($socket->frames, fn (array $frame): bool => $frame['type'] === 'workflow.history.get')))->toBe(2);
});

it('does not turn an unavailable or nonterminal root read into terminal evidence', function (string $case): void {
    $gateway = reconcileHealthNode();
    $operation = reconcileHealthOperation($gateway);
    $run = reconcileHealthRun($operation, $case === 'running' ? 'running' : 'failed');
    if ($case === 'mismatch') {
        $run['inputs']['operation_id'] = 98765;
    }
    if ($case === 'unbound') {
        $operation->update(['state' => []]);
    }
    reconcileHealthSocket([$run['id'] => $run], fail: $case === 'unavailable');

    reconcileHealthPass();

    expect(ProblemCollectorState::query()->sole()->observation)->toMatchArray([
        'operation_reconcile_finished_at' => '2026-11-12T12:00:00.000000Z',
        'operation_settlement_count' => 0, 'operation_settlement_oldest_completed_at' => null]);
})->with(['unavailable', 'running', 'mismatch', 'unbound']);

it('requires an old open native Operation before reporting a pass that never ran', function (): void {
    $gateway = reconcileHealthNode();
    expect(reconcileHealthIssues($gateway))->toBe([]);
    $operation = reconcileHealthOperation($gateway);
    $operation->update(['started_at' => '2026-11-12T11:55:00Z']);
    expect(reconcileHealthIssues($gateway))->toBe([]);
    $operation->update(['started_at' => '2026-11-12T11:54:59Z']);
    expect(reconcileHealthIssues($gateway))->toBe([
        ['node_id' => $gateway->id, 'node_name' => 'gateway', 'code' => 'gateway.operation_reconcile_stalled', 'count' => 1,
            'since' => null, 'newest_since' => null, 'resource' => null],
    ]);
    $operation->update(['status' => OperationStatus::Failed]);
    expect(reconcileHealthIssues($gateway))->toBe([]);
});

it('records empty passes but does not move the heartbeat when the scheduler only dispatches', function (): void {
    $gateway = reconcileHealthNode();
    reconcileHealthPass();
    $recorded = ProblemCollectorState::query()->sole()->observation;
    config(['orbit.gateway_queue.connection' => 'fixture']);
    Artisan::call('schedule:list');
    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => $event->description === ReconcileSwampOperations::class);
    expect($event)->toBeInstanceOf(CallbackEvent::class);
    $this->travelTo(new DateTimeImmutable('2026-11-12T12:06:00Z'));

    $event->run(app());

    Queue::assertPushedOn('operations', ReconcileSwampOperations::class);
    expect(ProblemCollectorState::query()->sole()->observation)->toBe($recorded)
        ->and(array_column(reconcileHealthIssues($gateway), 'code'))->toBe(['gateway.operation_reconcile_stalled']);
});

it('hides both Gateway groups from an ungranted peer and counts that Node once', function (): void {
    $gateway = reconcileHealthNode();
    $peer = reconcileHealthNode('peer', 9);
    ProblemCollectorState::query()->create(['observation' => [
        'operation_reconcile_finished_at' => '2026-11-12T11:54:00.000000Z',
        'operation_settlement_oldest_completed_at' => '2026-11-12T11:53:00.000000Z', 'operation_settlement_count' => 2,
        'doctor_duration_ms' => 1500, 'doctor_collected_at' => '2026-11-12T11:59:00.000000Z',
    ]]);
    $this->withServerVariables(['REMOTE_ADDR' => $peer->wireguard_ip])->getJson('/api/v1/gateway/status')->assertOk()
        ->assertJsonPath('data.doctor.issues', [])->assertJsonPath('data.doctor.hidden_nodes', 1);
    $peer->accessibleNodes()->attach($gateway);
    $visible = reconcileHealthIssues($peer);
    expect(array_column($visible, 'code'))->toBe(['gateway.doctor_collection_slow', 'gateway.operation_reconcile_stalled', 'gateway.operation_settlement_stuck'])
        ->and($visible[2]['count'])->toBe(2);
});

it('does not fail a pass when its single state write throws and logs the failure once', function (): void {
    Log::spy();
    $gateway = reconcileHealthNode();
    $operation = reconcileHealthOperation($gateway);
    reconcileHealthSocket([$operation->state['swamp_run_id'] => reconcileHealthRun($operation)]);
    ProblemCollectorState::saving(function (): void {
        throw new RuntimeException('State unavailable');
    });

    reconcileHealthPass();

    expect(ProblemCollectorState::query()->count())->toBe(0)->and($operation->refresh()->status)->toBe(OperationStatus::Failed);
    Log::shouldHaveReceived('warning')->with('The Operation reconcile pass facts could not be recorded.', ['exception_type' => RuntimeException::class])->once();
});

it('keeps proof of every terminal status when projection throws and counts the oldest run once per Operation', function (string $status): void {
    $gateway = reconcileHealthNode();
    $first = reconcileHealthOperation($gateway);
    $second = reconcileHealthOperation($gateway, 43);
    $run = reconcileHealthRun($first, $status);
    // The same named-step and stdout shape as PassiveOperationReadsTest::passiveRun.
    $run['jobs'] = [['name' => 'script', 'steps' => [['name' => 'outcome', 'status' => 'succeeded', 'outputs' => ['stdout' => '{}']]]]];
    $older = [...reconcileHealthRun($second), 'completedAt' => '2026-11-12T11:58:00.000Z'];
    reconcileHealthSocket([$run['id'] => $run, $older['id'] => $older]);
    Operation::updating(function (Operation $operation): void {
        if ($operation->status->terminal()) {
            throw new RuntimeException('Settlement failed');
        }
    });

    reconcileHealthPass();

    expect(ProblemCollectorState::query()->sole()->observation)->toMatchArray([
        'operation_settlement_count' => 2, 'operation_settlement_oldest_completed_at' => '2026-11-12T11:58:00.000000Z']);
})->with(['succeeded', 'failed', 'cancelled', 'interrupted']);

it('observes the selector-less Fleet reader without changing its generic settlement behavior', function (string $status): void {
    $gateway = reconcileHealthNode();
    $operation = reconcileHealthOperation($gateway);
    $operation->update(['kind' => 'fleet.rollout', 'target_type' => 'fleet']);
    $run = reconcileHealthRun($operation, $status);
    reconcileHealthSocket([$run['id'] => $run]);
    Operation::updating(function (Operation $operation): void {
        if ($operation->status->terminal()) {
            throw new RuntimeException('Settlement failed');
        }
    });

    reconcileHealthPass();

    expect(ProblemCollectorState::query()->sole()->observation)->toMatchArray([
        'operation_settlement_count' => 1, 'operation_settlement_oldest_completed_at' => '2026-11-12T12:00:00.000000Z'])
        ->and($operation->refresh()->status)->toBe(OperationStatus::Running);
})->with(['succeeded', 'failed', 'cancelled', 'interrupted']);

it('does not count a migration settlement wait or a Gateway timeout without terminal proof', function (): void {
    $gateway = reconcileHealthNode();
    $migration = reconcileHealthOperation($gateway);
    $migration->update(['kind' => 'instance.migrate', 'target_type' => 'instance', 'status' => OperationStatus::Failed,
        'error_code' => 'operation.timed_out', 'finished_at' => now(), 'lock_key' => 'instance:42',
        'state' => [...$migration->state, 'native_timeout' => ['deadline' => now()->toISOString()]]]);
    $timeout = reconcileHealthOperation($gateway, 43);
    $timeout->update(['status' => OperationStatus::Failed, 'error_code' => 'operation.timed_out', 'finished_at' => now(),
        'lock_key' => 'task_group:43', 'state' => [...$timeout->state, 'native_timeout' => ['deadline' => now()->toISOString()]]]);
    $runs = [reconcileHealthRun($migration, 'running'), reconcileHealthRun($timeout, 'running')];
    reconcileHealthSocket(array_column($runs, null, 'id'));

    reconcileHealthPass();

    expect(ProblemCollectorState::query()->sole()->observation)->toMatchArray([
        'operation_settlement_count' => 0, 'operation_settlement_oldest_completed_at' => null])
        ->and($migration->refresh()->state)->toHaveKey('migrate_settlement_hold_limit')
        ->and($migration->error_code)->toBe('operation.timed_out')->and($timeout->refresh()->lock_key)->toBe('task_group:43');
});

it('keeps the last completed pass time when a later pass aborts outside per-Operation recovery', function (): void {
    $gateway = reconcileHealthNode();
    reconcileHealthPass();
    $recorded = ProblemCollectorState::query()->sole()->observation;
    reconcileHealthOperation($gateway);
    Operation::retrieved(function (): void {
        throw new RuntimeException('Could not load Operations');
    });
    $this->travelTo(new DateTimeImmutable('2026-11-12T12:06:00Z'));

    expect(fn () => reconcileHealthPass())->toThrow(RuntimeException::class, 'Could not load Operations');

    expect(ProblemCollectorState::query()->sole()->observation)->toBe($recorded)
        ->and(array_column(reconcileHealthIssues($gateway), 'code'))->toBe(['gateway.operation_reconcile_stalled']);
});

it('does not count a check that stays running for hosted CI unless its projection failed', function (bool $pending, bool $projected, int $count): void {
    $operation = reconcileHealthOperation(reconcileHealthNode());
    $operation->update(['kind' => 'tasks.check', 'result' => ['pending' => $pending, 'ci' => ['state' => 'pending']]]);
    $health = new OperationReconcileHealth;

    $health->observe($operation, CarbonImmutable::parse('2026-11-12T11:50:00Z'), $projected);
    $health->record(hrtime(true));

    expect(ProblemCollectorState::query()->sole()->observation['operation_settlement_count'])->toBe($count);
    $this->travelTo(new DateTimeImmutable('2026-11-12T12:01:00Z'));
    expect(array_column($health->issues(ProblemCollectorState::query()->sole()->observation), 'code'))
        ->toBe($count === 0 ? [] : ['gateway.operation_settlement_stuck']);
})->with(['a current wait' => [true, true, 0], 'a wait whose result could not be applied' => [true, false, 1], 'no wait' => [false, true, 1]]);

it('finishes the pass and counts an Operation whose settlement cannot be read', function (): void {
    Log::spy();
    $gateway = reconcileHealthNode();
    ProblemCollectorState::query()->create(['observation' => ['operation_settlement_oldest_completed_at' => null, 'operation_settlement_count' => 0]]);
    $first = reconcileHealthOperation($gateway);
    $second = reconcileHealthOperation($gateway, 43);
    reconcileHealthSocket([$first->state['swamp_run_id'] => reconcileHealthRun($first), $second->state['swamp_run_id'] => reconcileHealthRun($second)]);
    $armed = false;
    Operation::updating(function (Operation $current) use ($first, &$armed): void {
        if ($current->id === $first->id && $current->status->terminal()) {
            $armed = true;
            throw new RuntimeException('Projection failed');
        }
    });
    Operation::retrieved(function () use (&$armed): void {
        if ($armed) {
            $armed = false;
            throw new RuntimeException('Could not read the Operation');
        }
    });

    reconcileHealthPass();

    expect($second->refresh()->status)->toBe(OperationStatus::Failed)->and($first->refresh()->status)->toBe(OperationStatus::Running)
        ->and(ProblemCollectorState::query()->sole()->observation)->toMatchArray(['operation_reconcile_finished_at' => '2026-11-12T12:00:00.000000Z',
            'operation_settlement_oldest_completed_at' => '2026-11-12T12:00:00.000000Z', 'operation_settlement_count' => 1]);
});
