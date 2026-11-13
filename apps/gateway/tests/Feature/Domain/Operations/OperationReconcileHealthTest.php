<?php

declare(strict_types=1);

use App\Actions\Operations\ExpireOperationAction;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationReconcileHealth;
use App\Domain\Operations\OperationStatus;
use App\Domain\Proof\ProofBatchPlan;
use App\Domain\Proof\ProofRunIntent;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Swamp\SwampConnection;
use App\Jobs\Operations\ReconcileSwampOperations;
use App\Models\Node;
use App\Models\Operation;
use App\Models\ProblemCollectorState;
use App\Support\ValidatedData;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
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
    Log::shouldHaveReceived('info')->once()->with('Operation reconcile pass ran.', [
        'duration_ms' => (float) $state['operation_reconcile_duration_ms'], 'operations' => 1, 'unsettled' => 0]);
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
    Log::shouldHaveReceived('info')->once()->with('Operation reconcile pass ran.', [
        'duration_ms' => (float) ProblemCollectorState::query()->sole()->observation['operation_reconcile_duration_ms'], 'operations' => 1, 'unsettled' => 1]);
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
    $health->record(hrtime(true), 0);

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

it('shows pass facts only to peers that can see the Gateway Node using one state read', function (string $caller): void {
    $gateway = reconcileHealthNode();
    $peer = reconcileHealthNode('peer', 9);
    if ($caller === 'inactive') {
        $peer->update(['status' => 'failed']);
    }
    if ($caller === 'granted') {
        $peer->accessibleNodes()->attach($gateway);
    }
    ProblemCollectorState::query()->create(['observation' => [
        'operation_reconcile_finished_at' => '2026-11-12T12:00:00.000000Z',
        'operation_reconcile_duration_ms' => 6.125, 'operation_settlement_count' => 2,
    ]]);
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (str_contains($query->sql, 'problem_collector_state')) {
            $queries[] = $query->sql;
        }
    });
    $address = match ($caller) {
        'gateway' => $gateway->wireguard_ip,
        'anonymous' => '203.0.113.10',
        default => $peer->wireguard_ip,
    };

    $response = $this->withServerVariables(['REMOTE_ADDR' => $address])->getJson('/api/v1/gateway/status')->assertOk();

    $expected = in_array($caller, ['gateway', 'granted'], true)
        ? ['finished_at' => '2026-11-12T12:00:00.000000Z', 'last_pass_ms' => 6.125, 'unsettled_count' => 2] : null;
    $response->assertJsonStructure(['data' => ['reconcile']])->assertJsonPath('data.reconcile', $expected);
    expect($queries)->toHaveCount(in_array($caller, ['anonymous', 'inactive'], true) ? 0 : 1);
    if ($caller === 'gateway') {
        fwrite(STDOUT, "\nReconcile facts: ".json_encode($response->json('data.reconcile'), JSON_THROW_ON_ERROR)."\n");
    }
})->with(['gateway', 'granted', 'ungranted', 'anonymous', 'inactive']);

it('returns empty pass facts for absent or malformed observations', function (?array $observation): void {
    $gateway = reconcileHealthNode();
    if ($observation !== null) {
        ProblemCollectorState::query()->create(['observation' => $observation]);
    }

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])->getJson('/api/v1/gateway/status')->assertOk()
        ->assertJsonPath('data.reconcile', ['finished_at' => null, 'last_pass_ms' => null, 'unsettled_count' => 0]);
})->with([
    'no state' => [null],
    'no keys' => [[]],
    'wrong types' => [['operation_reconcile_finished_at' => 12, 'operation_reconcile_duration_ms' => '6.125', 'operation_settlement_count' => '2']],
    'invalid timestamp and duration' => [['operation_reconcile_finished_at' => 'not a timestamp', 'operation_reconcile_duration_ms' => -1, 'operation_settlement_count' => 2.5]],
    'empty timestamp' => [['operation_reconcile_finished_at' => '', 'operation_reconcile_duration_ms' => []]],
    'invalid calendar date' => [['operation_reconcile_finished_at' => '2026-02-30T12:00:00Z']],
    'relative time' => [['operation_reconcile_finished_at' => 'tomorrow']],
]);

it('logs one pass with the open Operations read and the stored duration', function (int $count): void {
    Log::spy();
    $gateway = reconcileHealthNode();
    $runs = [];
    for ($index = 0; $index < $count; $index++) {
        $operation = reconcileHealthOperation($gateway, 42 + $index);
        $runs[$operation->state['swamp_run_id']] = reconcileHealthRun($operation);
    }
    $closed = reconcileHealthOperation($gateway, 50);
    $closed->update(['status' => OperationStatus::Failed]);
    reconcileHealthSocket($runs);

    reconcileHealthPass();

    $state = ProblemCollectorState::query()->sole()->observation;
    $context = null;
    Log::shouldHaveReceived('info')->once()->with('Operation reconcile pass ran.', Mockery::on(function (array $cost) use (&$context, $state, $count): bool {
        $context = $cost;

        return $cost === ['duration_ms' => (float) $state['operation_reconcile_duration_ms'], 'operations' => $count, 'unsettled' => 0];
    }));
    expect($context['duration_ms'])->toBeFloat()->toBe(round($context['duration_ms'], 3));
    Log::shouldNotHaveReceived('warning');
    fwrite(STDOUT, "\n".json_encode(['message' => 'Operation reconcile pass ran.', 'context' => $context], JSON_THROW_ON_ERROR)."\n");
})->with([0, 2]);

it('finishes and records the pass when its info logger throws', function (): void {
    Log::shouldReceive('info')->once()->with('Operation reconcile pass ran.', Mockery::type('array'))
        ->andThrow(new RuntimeException('Logger unavailable'));
    $gateway = reconcileHealthNode();
    $operation = reconcileHealthOperation($gateway);
    reconcileHealthSocket([$operation->state['swamp_run_id'] => reconcileHealthRun($operation)]);

    reconcileHealthPass();

    expect($operation->refresh()->status)->toBe(OperationStatus::Failed)
        ->and(ProblemCollectorState::query()->sole()->observation)->toMatchArray([
            'operation_reconcile_finished_at' => '2026-11-12T12:00:00.000000Z', 'operation_settlement_count' => 0]);
});

/** One stranded child whose parent never settled; status must share the admission rule without changing either row. */
function reconcileHealthProofChild(Node $gateway, bool $timedOut = false): Operation
{
    $age = ($timedOut ? 0 : 2700) + ExpireOperationAction::HoldMinutes * 60 + 1;
    $parent = Operation::query()->create(['kind' => 'proof.run', 'target_type' => 'node', 'target_id' => $gateway->id, 'node_id' => $gateway->id,
        'status' => OperationStatus::Running, 'input' => ['runs' => [['operation_id' => 1]]]]);

    return Operation::query()->forceCreate(['kind' => 'proof.command', 'target_type' => 'task_group', 'target_id' => $parent->id, 'node_id' => $gateway->id,
        'status' => $timedOut ? OperationStatus::Failed : OperationStatus::Running, 'attempts' => 1,
        'input' => ['proof_operation_id' => $parent->id, 'run_budget_seconds' => 2700], 'state' => ['swamp_run_id' => 'stranded-proof-child'],
        'lock_key' => 'task_group:'.$parent->id, 'error_code' => $timedOut ? 'operation.timed_out' : null,
        'started_at' => now()->subSeconds($age), 'finished_at' => $timedOut ? now()->subSeconds($age) : null,
        'updated_at' => now()->subSeconds($age)]);
}

it('shows a stale proof child as one Gateway group with its last activity and without changing the row', function (bool $timedOut): void {
    $gateway = reconcileHealthNode();
    ProblemCollectorState::query()->create(['observation' => ['operation_reconcile_finished_at' => now()->toISOString()]]);
    $child = reconcileHealthProofChild($gateway, $timedOut);
    $before = $child->refresh()->getAttributes();
    $expected = [['node_id' => $gateway->id, 'node_name' => 'gateway', 'code' => 'gateway.proof_place_stale_child', 'count' => 1,
        'since' => $child->updated_at?->toISOString(), 'newest_since' => $child->updated_at?->toISOString(), 'resource' => null]];
    expect(reconcileHealthIssues($gateway))->toBe($expected)
        ->and($child->refresh()->getAttributes())->toBe($before);
    fwrite(STDOUT, "\nStale proof group: ".json_encode($expected[0], JSON_THROW_ON_ERROR)."\n");
})->with([false, true]);

it('counts stale children in one bounded read and adds no query when no unresolved proof child exists', function (): void {
    $gateway = reconcileHealthNode();
    ProblemCollectorState::query()->create(['observation' => ['operation_reconcile_finished_at' => now()->toISOString()]]);
    reconcileHealthIssues($gateway); // Warm request-local framework settings before comparing statement counts.
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    expect(reconcileHealthIssues($gateway))->toBe([]);
    $empty = count($queries);
    expect($empty)->toBe(11);
    expect(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'join "operations"')))->toBe([]);
    $children = [reconcileHealthProofChild($gateway), reconcileHealthProofChild($gateway, true), reconcileHealthProofChild($gateway)];
    $oldest = now()->subSeconds(2700 + ExpireOperationAction::HoldMinutes * 60 + 20);
    $children[2]->forceFill(['updated_at' => $oldest])->save();
    $queries = [];
    $groups = reconcileHealthIssues($gateway);
    expect($groups)->toHaveCount(1)->and($groups[0]['count'])->toBe(3)->and($groups[0]['since'])->toBe($oldest->toISOString())
        ->and(count($queries))->toBe($empty + 1)
        ->and(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'join "operations"')))->toHaveCount(1);
    $withChildren = count($queries);
    foreach ($children as $child) {
        $child->update(['status' => OperationStatus::Succeeded, 'lock_key' => null]);
    }
    $queries = [];
    expect(reconcileHealthIssues($gateway))->toBe([])->and(count($queries))->toBe($empty);
    fwrite(STDOUT, "\nProof place status queries: ".json_encode(['empty' => $empty, 'stale' => $withChildren, 'ended' => count($queries)], JSON_THROW_ON_ERROR)."\n");
});

it('protects a child inside its budget and a held child of a young parent before showing them stale', function (bool $held): void {
    $gateway = reconcileHealthNode();
    ProblemCollectorState::query()->create(['observation' => ['operation_reconcile_finished_at' => now()->toISOString()]]);
    $child = reconcileHealthProofChild($gateway);
    $child->forceFill(['started_at' => now(), 'updated_at' => now()->subSeconds(ProofBatchPlan::MaxShardSeconds + ExpireOperationAction::HoldMinutes * 60 + 1),
        'state' => $held ? ['proof_child_start' => ['released_at' => null]] : ['swamp_run_id' => 'legitimate-child']])->save();
    expect(reconcileHealthIssues($gateway))->toBe([]);
    $seconds = $held ? ProofRunIntent::WaitSeconds + 1 : 2700 + ExpireOperationAction::HoldMinutes * 60 + 1;
    Carbon::setTestNow(now()->addSeconds($seconds));
    ProblemCollectorState::query()->sole()->update(['observation' => ['operation_reconcile_finished_at' => now()->toISOString()]]);
    expect(array_column(reconcileHealthIssues($gateway), 'code'))->toContain('gateway.proof_place_stale_child');
})->with([false, true]);

it('reports a stranded proof child even before reconciliation has created its state row', function (): void {
    $gateway = reconcileHealthNode();
    reconcileHealthProofChild($gateway);
    expect(ProblemCollectorState::query()->count())->toBe(0)
        ->and(array_column(reconcileHealthIssues($gateway), 'code'))->toContain('gateway.proof_place_stale_child')
        ->and(ProblemCollectorState::query()->count())->toBe(0);
});

it('holds a child at its exact stale bound and reports it only after that bound', function (bool $timedOut): void {
    $gateway = reconcileHealthNode();
    ProblemCollectorState::query()->create(['observation' => ['operation_reconcile_finished_at' => now()->toISOString()]]);
    $child = reconcileHealthProofChild($gateway, $timedOut);
    $age = ($timedOut ? 0 : 2700) + ExpireOperationAction::HoldMinutes * 60;
    $child->forceFill(['started_at' => now()->subSeconds($age), 'updated_at' => now()->subSeconds($age),
        'finished_at' => $timedOut ? now()->subSeconds($age) : null])->save();
    expect(reconcileHealthIssues($gateway))->toBe([]);
    Carbon::setTestNow(now()->addSecond());
    expect(array_column(reconcileHealthIssues($gateway), 'code'))->toContain('gateway.proof_place_stale_child');
})->with([false, true]);

it('reports a held child of an ended or missing parent after sixty minutes without running the watchdog', function (bool $missing): void {
    $gateway = reconcileHealthNode();
    ProblemCollectorState::query()->create(['observation' => ['operation_reconcile_finished_at' => now()->toISOString()]]);
    $child = reconcileHealthProofChild($gateway);
    $child->forceFill(['status' => OperationStatus::Queued, 'attempts' => 0, 'finished_at' => null,
        'updated_at' => now()->subMinutes(ExpireOperationAction::HoldMinutes),
        'state' => ['proof_child_start' => ['released_at' => null]]])->save();
    $parent = Operation::query()->findOrFail(ValidatedData::integer($child->input['proof_operation_id']));
    if ($missing) {
        $parent->delete();
    } else {
        $parent->update(['status' => OperationStatus::Cancelled]);
    }
    expect(reconcileHealthIssues($gateway))->toBe([]);
    Carbon::setTestNow(now()->addSecond());
    $before = $child->refresh()->getAttributes();
    $groups = reconcileHealthIssues($gateway);
    expect($groups)->toHaveCount(1)->and($groups[0])->toBe(['node_id' => $gateway->id, 'node_name' => 'gateway',
        'code' => 'gateway.proof_place_stale_child', 'count' => 1, 'since' => $child->updated_at?->toISOString(),
        'newest_since' => $child->updated_at?->toISOString(), 'resource' => null])
        ->and($child->refresh()->getAttributes())->toBe($before);
    fwrite(STDOUT, "\nHeld child stale group: ".json_encode($groups[0], JSON_THROW_ON_ERROR)."\n");
})->with([false, true]);
