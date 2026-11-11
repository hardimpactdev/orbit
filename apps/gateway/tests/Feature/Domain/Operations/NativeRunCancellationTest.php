<?php

declare(strict_types=1);

use App\Actions\Operations\CancelOperationAction;
use App\Actions\Operations\ExpireOperationAction;
use App\Data\Operations\OperationData;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\NativeRunCancellation;
use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOperations;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Swamp\CancelSwampRun;
use App\Infrastructure\Swamp\NativeRunResult;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampConnection;
use App\Infrastructure\Swamp\SwampRequestDeadline;
use App\Infrastructure\Swamp\SwampTerminalReadUnavailable;
use App\Jobs\Operations\ReconcileSwampOperations;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

const RUN = 'ea55ab37-bcbe-4459-81dc-cbb397e7d870';

/** A tasks.script of a group that started 20 minutes ago with a 360-second budget. */
function runningScript(?string $run = RUN, int $minutes = 20): Operation
{
    $group = Task::query()->create(['title' => 'Proof', 'brief' => 'Prove it', 'status' => TaskGroupStatus::Running]);
    $address = '10.44.0.'.(100 + $group->id);
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'vm-'.$group->id, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => $address, 'wireguard_ip' => $address, 'user' => 'orbit']));

    return Operation::query()->create(['kind' => 'tasks.script', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $node->id,
        'input' => ['script' => 'scripts/dependencies', 'args' => ['routine'], 'run_budget_seconds' => 360], 'status' => OperationStatus::Running,
        'state' => $run === null ? [] : ['swamp_run_id' => $run], 'sequence' => 1, 'attempts' => 1, 'started_at' => now()->subMinutes($minutes)]);
}

it('asks Swamp to cancel the bound run when the Gateway times the Operation out', function (): void {
    Queue::fake();
    $operation = runningScript();

    $expired = app(ExpireOperationAction::class)->execute($operation);

    expect($expired->error_code)->toBe('operation.timed_out')
        ->and($expired->state['native_cancel_requested_at'] ?? null)->not->toBeNull();
    Queue::assertPushed(ReconcileSwampOperations::class);
});

it('does not ask twice for the same expiry, and has nothing to cancel without a bound run', function (): void {
    Queue::fake();
    $expired = app(ExpireOperationAction::class)->execute(runningScript());

    expect(app(NativeRunCancellation::class)->request($expired->refresh()))->toBeFalse()
        ->and(app(NativeRunCancellation::class)->request(runningScript(null), again: true))->toBeFalse();
    Queue::assertPushed(ReconcileSwampOperations::class, 1);
});

it('lets an operator cancel ask again for a run that outlives its timed out Operation', function (): void {
    Queue::fake();
    $expired = app(ExpireOperationAction::class)->execute(runningScript());
    $expired->forceFill(['state' => [...$expired->state, 'native_cancel_confirmed_at' => now()->toISOString()]])->save();

    app(CancelOperationAction::class)->execute($expired->refresh());

    expect($expired->refresh()->state)->toHaveKey('native_cancel_requested_at')->not->toHaveKey('native_cancel_confirmed_at');
});

it('asks Swamp to cancel the timed out runs of a group that ends', function (): void {
    Queue::fake();
    $expired = app(ExpireOperationAction::class)->execute(runningScript());
    $state = $expired->state;
    unset($state['native_cancel_requested_at']);
    $expired->forceFill(['state' => $state])->save();
    $group = Task::topLevel()->findOrFail($expired->target_id);

    app(TaskOperations::class)->cancelOpen($group);

    expect($expired->refresh()->state)->toHaveKey('native_cancel_requested_at');
    $socket = reconcileSocket($expired, 'running');
    reconcile();
    expect(array_column($socket->frames, 'type'))->toContain('workflow.cancel')
        ->and($expired->refresh()->state)->toHaveKey('native_cancel_confirmed_at');
});

/** A run-token connection that answers a `workflow.cancel` request with the frame the test names. */
function cancelSocket(array $reply): WebSocketClient
{
    $socket = new class($reply) implements WebSocketClient
    {
        /** @var list<array<string,mixed>> */
        public array $frames = [];

        public ?WebSocketEndpoint $endpoint = null;

        private bool $open = false;

        /** @param array<string,mixed> $reply */
        public function __construct(private readonly array $reply) {}

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
        {
            $this->endpoint = $endpoint;
            $this->open = true;
        }

        public function send(array $message): void
        {
            $this->frames[] = $message;
        }

        public function receive(float $timeoutSeconds): array
        {
            $frame = end($this->frames);

            return $frame === false ? [] : [['id' => $frame['id'], ...$this->reply]];
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
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'run-token', 'swamp.read_token' => 'read-token', 'swamp.address' => '10.44.0.15', 'swamp.ca_path' => __FILE__]);

    return $socket;
}

it('asks Swamp to cancel the run with a workflow.cancel request on the run token', function (): void {
    $socket = cancelSocket(['type' => 'workflow.cancel', 'payload' => ['data' => ['runId' => RUN, 'workflowName' => 'operation-task-script', 'status' => 'cancellation_requested']]]);
    $operation = runningScript();

    app(CancelSwampRun::class)->execute($operation);

    expect($socket->frames)->toHaveCount(1)
        ->and($socket->frames[0]['type'])->toBe('workflow.cancel')
        ->and($socket->frames[0]['payload']['runId'])->toBe(RUN)
        ->and($socket->endpoint?->bearerToken)->toBe('run-token')
        ->and($socket->isConnected())->toBeFalse();
});

it('accepts a cancel that already settled the run', function (): void {
    cancelSocket(['type' => 'workflow.cancel', 'payload' => ['data' => ['runId' => RUN, 'workflowName' => 'operation-task-script', 'status' => 'cancelled']]]);

    app(CancelSwampRun::class)->execute(runningScript());

    expect(true)->toBeTrue();
});

it('reports swamp.cancel_unavailable when Swamp answers with an error', function (): void {
    cancelSocket(['type' => 'error', 'error' => ['code' => 'workflow_cancel_failed', 'message' => 'No cancellable run']]);

    expect(fn () => app(CancelSwampRun::class)->execute(runningScript()))
        ->toThrow(fn (ResourceOperationException $exception): bool => $exception->errorCode === 'swamp.cancel_unavailable');
});

it('reports swamp.cancel_unavailable without a bound run and sends nothing', function (): void {
    $socket = cancelSocket(['type' => 'workflow.cancel', 'payload' => ['data' => []]]);

    expect(fn () => app(CancelSwampRun::class)->execute(runningScript(null)))
        ->toThrow(fn (ResourceOperationException $exception): bool => $exception->errorCode === 'swamp.cancel_unavailable')
        ->and($socket->frames)->toBe([]);
});

it('releases the target of a timed-out Operation whose run was never proven ended, one hour after the expiry', function (): void {
    Queue::fake();
    $operation = runningScript();
    app(ExpireOperationAction::class)->execute($operation);
    $operation->refresh()->update(['lock_key' => OperationStore::lockKey('task_group', $operation->target_id)]);

    expect(app(ExpireOperationAction::class)->releaseStaleHolds())->toBe(0)
        ->and($operation->refresh()->lock_key)->not->toBeNull();

    $this->travel(61)->minutes();

    expect(app(ExpireOperationAction::class)->releaseStaleHolds())->toBe(1);
    $operation->refresh();
    expect($operation->lock_key)->toBeNull()
        ->and($operation->state['native_timeout']['native_terminal_status'])->toBe('unproven')
        ->and(ExpireOperationAction::awaitsSettlement($operation))->toBeFalse();
});

it('keeps the target of an Operation that is still running', function (): void {
    Queue::fake();
    $operation = runningScript();
    $operation->update(['lock_key' => OperationStore::lockKey('task_group', $operation->target_id)]);
    $this->travel(5)->hours();

    expect(app(ExpireOperationAction::class)->releaseStaleHolds())->toBe(0)
        ->and($operation->refresh()->lock_key)->not->toBeNull();
});

afterEach(fn () => app(SwampConnection::class)->release());

/** One Swamp connection that answers a history read with the run the test names and a cancel with the frame it names. */
function reconcileSocket(?Operation $operation, string $status, string $cancel = 'cancellation_requested', ?Closure $onCancel = null, ?Closure $onHistory = null): WebSocketClient
{
    $history = $operation === null ? [] : ['id' => RUN, 'workflowName' => 'operation-task-script', 'status' => $status,
        'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => 'tasks.script'],
        'startedAt' => now()->subMinutes(3)->toIso8601ZuluString('millisecond'), 'completedAt' => now()->toIso8601ZuluString('millisecond'), 'jobs' => []];
    $socket = new class($history, $cancel, $onCancel, $onHistory) implements WebSocketClient
    {
        /** @var list<array<string,mixed>> */
        public array $frames = [];

        private bool $open = false;

        /** @param array<string,mixed> $history */
        public function __construct(private readonly array $history, private readonly string $cancel, private readonly ?Closure $onCancel, private readonly ?Closure $onHistory) {}

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
            $frame = end($this->frames);
            if ($frame === false) {
                return [];
            }

            if ($frame['type'] === 'workflow.cancel' && $this->onCancel !== null) {
                ($this->onCancel)();
            }

            return [['id' => $frame['id'], ...match ($frame['type']) {
                'workflow.history.get' => ['type' => 'workflow.history.get', 'payload' => ['data' => $this->onHistory === null ? $this->history : ($this->onHistory)($frame['payload'])]],
                'workflow.cancel' => $this->cancel === 'error'
                    ? ['type' => 'error', 'error' => ['code' => 'workflow_cancel_failed']]
                    : ['type' => 'workflow.cancel', 'payload' => ['data' => ['runId' => $frame['payload']['runId'], 'status' => $this->cancel]]],
                default => ['type' => $frame['type'], 'payload' => ['data' => ['results' => []]]],
            }]];
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
    app()->instance(WebSocketClient::class, $socket);
    app(SwampConnection::class)->release();
    config(['swamp.run_token' => 'run-token', 'swamp.read_token' => 'read-token', 'swamp.address' => '10.44.0.15', 'swamp.ca_path' => __FILE__]);

    return $socket;
}

function reconcile(): void
{
    app()->call([new ReconcileSwampOperations, 'handle']);
}

it('projects a run that ended in its history', function (): void {
    $operation = runningScript(minutes: 3);
    reconcileSocket($operation, 'failed');

    reconcile();

    expect($operation->refresh()->status)->toBe(OperationStatus::Failed)
        ->and($operation->error_code)->not->toBe('operation.timed_out');
});

it('leaves a run that is still going within its budget', function (): void {
    $operation = runningScript(minutes: 3);
    reconcileSocket($operation, 'running');

    reconcile();

    expect($operation->refresh()->status)->toBe(OperationStatus::Running);
});

it('expires a run that is still going at its budget and forwards the cancel in the same pass', function (): void {
    $operation = runningScript();
    $socket = reconcileSocket($operation, 'running');

    reconcile();

    $operation->refresh();
    expect($operation->error_code)->toBe('operation.timed_out')
        ->and($operation->state['native_cancel_requested_at'] ?? null)->not->toBeNull()
        ->and($operation->state['native_cancel_confirmed_at'] ?? null)->not->toBeNull()
        ->and(array_column($socket->frames, 'type'))->toContain('workflow.cancel');
});

it('forwards a cancel in the next pass when Swamp did not confirm it', function (): void {
    $operation = runningScript();
    reconcileSocket($operation, 'running', cancel: 'error');
    reconcile();
    expect($operation->refresh()->state['native_cancel_confirmed_at'] ?? null)->toBeNull();

    reconcileSocket($operation, 'running');
    reconcile();

    expect($operation->refresh()->state['native_cancel_confirmed_at'] ?? null)->not->toBeNull();
});

it('settles a timed-out Operation whose run ended and asks for no cancel', function (): void {
    $operation = runningScript();
    app(ExpireOperationAction::class)->execute($operation);
    $operation->refresh()->update(['lock_key' => OperationStore::lockKey('task_group', $operation->target_id)]);
    $socket = reconcileSocket($operation, 'cancelled');

    reconcile();

    $operation->refresh();
    expect($operation->lock_key)->toBeNull()
        ->and($operation->state['native_timeout']['native_terminal_status'])->toBe('cancelled')
        ->and(array_column($socket->frames, 'type'))->not->toContain('workflow.cancel');
});

it('frees the target of a run that nothing proves ended, one hour after the expiry, in the same pass', function (): void {
    Queue::fake();
    $operation = runningScript();
    app(ExpireOperationAction::class)->execute($operation);
    $operation->refresh()->update(['lock_key' => OperationStore::lockKey('task_group', $operation->target_id)]);
    $this->travel(61)->minutes();
    reconcileSocket(null, 'running', cancel: 'error');

    reconcile();

    expect($operation->refresh()->lock_key)->toBeNull()
        ->and($operation->state['native_timeout']['native_terminal_status'])->toBe('unproven')
        ->and($operation->state)->not->toHaveKey('admission_hold_key');
});

it('expires an unbound Operation at its admission deadline', function (): void {
    Queue::fake();
    $operation = runningScript(null, 20);
    $operation->update(['attempts' => 0]);
    reconcileSocket(null, 'running');

    reconcile();

    expect($operation->refresh()->error_code)->toBe('operation.timed_out');
});

it('stops forwarding a cancel after one hour but still reads for a late native terminal', function (): void {
    Queue::fake();
    $operation = runningScript();
    app(ExpireOperationAction::class)->execute($operation);
    $this->travel(61)->minutes();
    $socket = reconcileSocket($operation, 'running', cancel: 'error');

    reconcile();

    expect(array_column($socket->frames, 'type'))->toContain('workflow.history.get')->not->toContain('workflow.cancel')
        ->and($operation->refresh()->state['native_cancel_confirmed_at'] ?? null)->toBeNull();
});

it('does not record a confirmation for a request that was recorded again while Swamp answered', function (): void {
    $operation = runningScript();
    app(ExpireOperationAction::class)->execute($operation);
    $operation->refresh();
    reconcileSocket($operation, 'running', onCancel: fn () => $operation->forceFill(['state' => [...$operation->refresh()->state,
        'native_cancel_requested_at' => now()->addSecond()->toISOString()]])->save());

    reconcile();

    expect($operation->refresh()->state['native_cancel_confirmed_at'] ?? null)->toBeNull();
});

it('forwards a cancellation recorded before native start confirmation once the run binds', function (): void {
    Queue::fake();
    $operation = runningScript(run: null, minutes: 0);
    $operation->update(['state' => ['swamp_start' => ['key' => 'operation:'.$operation->id, 'workflow' => 'operation-task-script']], 'attempts' => 0]);
    app(CancelOperationAction::class)->execute($operation);
    $operation->update(['state' => [...$operation->state, 'swamp_run_id' => RUN]]);
    $socket = reconcileSocket($operation, 'running');

    reconcile();

    expect($operation->refresh()->status)->toBe(OperationStatus::Running)
        ->and($operation->state['native_cancel_requested_at'] ?? null)->not->toBeNull()
        ->and($operation->state['native_cancel_confirmed_at'] ?? null)->not->toBeNull();
    expect(array_column($socket->frames, 'type'))->toContain('workflow.cancel');
});

it('projects a late native terminal after confirmed cancellation without a target lock for a non-migrate kind', function (?string $previousTerminal): void {
    Queue::fake();
    $operation = runningScript();
    app(ExpireOperationAction::class)->execute($operation);
    $operation->refresh()->update(['lock_key' => null, 'state' => [...$operation->state,
        'native_cancel_confirmed_at' => now()->toISOString(),
        'native_timeout' => [...$operation->state['native_timeout'], 'native_terminal_status' => $previousTerminal]]]);
    reconcileSocket($operation, 'failed');

    reconcile();

    expect($operation->refresh()->error_code)->toBe('operation.native_failed')
        ->and($operation->state['native_timeout']['native_terminal_status'])->toBe('failed');
})->with([null, 'unproven']);

it('settles current work and forwards cancel before slow lost runs, then rotates recovery with backoff', function (): void {
    Queue::fake();
    $lost = [];
    for ($i = 0; $i < 8; $i++) {
        $operation = runningScript(run: (string) Str::uuid());
        app(ExpireOperationAction::class)->execute($operation);
        $operation->refresh()->update(['lock_key' => null, 'state' => [...$operation->state,
            'native_cancel_confirmed_at' => now()->toISOString()]]);
        $lost[$operation->state['swamp_run_id']] = $operation;
    }
    $fresh = runningScript(minutes: 1);
    $cancelled = runningScript(run: (string) Str::uuid(), minutes: 1);
    $cancelled->update(['cancel_requested_at' => now()]);
    app(NativeRunCancellation::class)->request($cancelled);
    $checked = [];
    $socket = reconcileSocket($fresh, 'failed', onHistory: function (array $payload) use ($lost, $fresh, $cancelled, &$checked): array {
        $run = $payload['workflowIdOrName'];
        $checked[] = $run;
        $operation = $lost[$run] ?? ($run === RUN ? $fresh : $cancelled);
        if (isset($lost[$run])) {
            // Simulate one history request consuming its full remote timeout, without a real sleep.
            $this->travel(31)->seconds();
        }

        return ['id' => $run, 'workflowName' => 'operation-task-script', 'status' => $operation->is($fresh) ? 'failed' : 'running',
            'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => 'tasks.script'],
            'startedAt' => now()->subMinute()->toISOString(), 'completedAt' => now()->toISOString(), 'jobs' => []];
    });

    reconcile();

    expect($fresh->refresh()->status)->toBe(OperationStatus::Failed)
        ->and($fresh->error_code)->toBe('operation.native_failed')
        ->and($cancelled->refresh()->state)->toHaveKey('native_cancel_confirmed_at')
        ->and($checked)->toHaveCount(3);
    $types = array_column($socket->frames, 'type');
    expect(array_search('workflow.cancel', $types))->toBeLessThan(array_key_last($types));
    $firstLost = array_values($lost)[0];
    expect($firstLost->refresh()->state['native_reconcile']['attempt'])->toBe(1)
        ->and($firstLost->state['native_reconcile']['next_at'])->toBeGreaterThan(now()->toISOString());
    $checked = [];

    reconcile();

    expect($checked)->toContain(array_keys($lost)[1])->not->toContain(array_keys($lost)[0]);
});

it('keeps a late non-migrate receipt without applying its family domain projection', function (): void {
    Queue::fake();
    $old = runningScript();
    app(ExpireOperationAction::class)->execute($old);
    $old->refresh()->update(['lock_key' => null]);
    $new = app(OperationStore::class)->create('tasks.script', $old->target_type, $old->target_id, $old->node_id, $old->input, null, null);
    $direct = app(DirectOperation::class);
    $projection = $direct->definition($old->kind)['projection'];
    app()->instance($projection, new class implements DirectOperationProjection
    {
        public function input(Operation $operation): array
        {
            return [];
        }

        public function apply(Operation $operation, array $data): Operation
        {
            throw new LogicException('A superseded projector must not run.');
        }
    });
    reconcileSocket($old, 'failed');

    $settled = app(SwampCommandStep::class)->projectTerminal($old, ['id' => RUN], $direct->definition($old->kind)['terminal_result']);

    expect($settled->status)->toBe(OperationStatus::Failed)
        ->and($settled->result)->toBe(['late_settlement' => true, 'native_status' => 'failed'])
        ->and($new->refresh()->status)->toBe(OperationStatus::Queued)
        ->and($settled->state)->not->toHaveKey('projection_superseded_by')
        ->and($settled->state['native_timeout']['native_terminal_status'])->toBe('failed');
});

it('records a late receipt and permits retry admission without invoking family projection', function (): void {
    Queue::fake();
    $old = runningScript();
    app(ExpireOperationAction::class)->execute($old);
    $old->refresh()->update(['lock_key' => null]);
    $schema = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'operations'")->sql;
    $originalConnection = DB::getDefaultConnection();
    $file = tempnam(sys_get_temp_dir(), 'orbit-late-owner-');
    $options = [...config('database.connections.sqlite'), 'database' => $file, 'foreign_key_constraints' => false, 'busy_timeout' => 0];
    config(['database.connections.late_settle' => $options, 'database.connections.late_admit' => $options]);
    $settling = DB::connection('late_settle');
    $settling->unprepared($schema);
    $settling->table('operations')->insert($old->getAttributes());
    DB::connection('late_admit')->getPdo()->exec('PRAGMA busy_timeout=0');
    DB::setDefaultConnection('late_settle');
    $direct = app(DirectOperation::class);
    $definition = $direct->definition($old->kind);
    $attempted = false;
    app()->instance($definition['projection'], new class($attempted) implements DirectOperationProjection
    {
        public function __construct(public bool &$attempted) {}

        public function input(Operation $operation): array
        {
            return [];
        }

        public function apply(Operation $operation, array $data): Operation
        {
            $this->attempted = true;
            throw new LogicException('A late family projector must not run.');
        }
    });
    reconcileSocket($old, 'failed');
    try {
        $settled = app(SwampCommandStep::class)->projectTerminal(Operation::query()->findOrFail($old->id), ['id' => RUN], $definition['terminal_result']);
        expect($attempted)->toBeFalse()->and($settled->status)->toBe(OperationStatus::Failed);
        // Settlement releases its transaction; a retry can take the same target.
        $retry = app(OperationStore::class)->create($old->kind, $old->target_type, $old->target_id, $old->node_id, $old->input, null, null);
        expect($retry->id)->toBeGreaterThan($old->id)
            ->and($settled->refresh()->result)->not->toHaveKey('superseded_by');
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::purge('late_settle');
        DB::purge('late_admit');
        unlink($file);
    }
});

it('bounds a native cancel connection and reply by the remaining pass time', function (): void {
    $operation = runningScript();
    $frame = [];
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldReceive('connect')->once()->with(Mockery::type(WebSocketEndpoint::class), Mockery::on(fn (float $seconds): bool => $seconds > 0 && $seconds <= 0.25));
    $socket->shouldReceive('send')->once()->andReturnUsing(function (array $message) use (&$frame): void {
        $frame = $message;
    });
    $socket->shouldReceive('isConnected')->andReturnTrue();
    $socket->shouldReceive('receive')->once()->with(Mockery::on(fn (float $seconds): bool => $seconds > 0 && $seconds <= 0.25))
        ->andReturnUsing(function () use (&$frame): array {
            return [['id' => $frame['id'], 'type' => 'workflow.cancel', 'payload' => ['data' => ['runId' => RUN, 'status' => 'cancellation_requested']]]];
        });
    $socket->shouldReceive('close')->once();
    config(['swamp.run_token' => 'run-token', 'swamp.address' => '10.44.0.15', 'swamp.ca_path' => __FILE__]);

    SwampRequestDeadline::during(now()->addMilliseconds(250), fn () => (new CancelSwampRun($socket))->execute($operation));
});

it('admits independent scopes and retries without changing a released historical receipt', function (): void {
    Queue::fake();
    $template = runningScript();
    $store = app(OperationStore::class);
    $old = $store->create('tasks.script', $template->target_type, $template->target_id, $template->node_id, $template->input, null, null, 'manager:first');
    $old->update(['status' => OperationStatus::Running, 'attempts' => 1, 'started_at' => now()->subHours(2),
        'state' => [...$old->state, 'swamp_run_id' => RUN]]);
    app(ExpireOperationAction::class)->execute($old);
    $old->refresh()->update(['lock_key' => null]);

    $independent = $store->create('tasks.script', $old->target_type, $old->target_id, $old->node_id, $old->input, null, null, 'manager:second');
    expect($old->refresh()->state)->not->toHaveKey('projection_superseded_by');
    $retry = $store->create('tasks.script', $old->target_type, $old->target_id, $old->node_id, $old->input, null, null, 'manager:first');

    expect($old->refresh()->state)->not->toHaveKey('projection_superseded_by')->not->toHaveKey('admission_hold_key')
        ->and($retry->lock_key)->toBe('manager:first')
        ->and($independent->refresh()->state)->not->toHaveKey('projection_superseded_by');
});

it('stops a terminal read before contacting Swamp when the pass deadline ended and restores the outer scope', function (): void {
    $operation = runningScript(minutes: 1);
    $socket = reconcileSocket($operation, 'failed');
    $reader = app(SwampCommandStep::class);
    $selector = app(DirectOperation::class)->definition($operation->kind)['terminal_result'];
    expect(fn () => SwampRequestDeadline::during(now()->subSecond(),
        fn () => $reader->projectTerminal($operation, ['id' => RUN], $selector)))
        ->toThrow(SwampTerminalReadUnavailable::class);
    expect($socket->frames)->toBe([]);

    $settled = $reader->projectTerminal($operation, ['id' => RUN], $selector);
    expect($settled->status)->toBe(OperationStatus::Failed)
        ->and(array_column($socket->frames, 'type'))->toContain('workflow.history.get');
});

it('keeps generic late causality and safe steps without consulting a family end check', function (string $native, bool $userCancel, bool $expired, int $exit, ?string $nativeCode, string $status, ?string $code): void {
    Queue::fake();
    $operation = runningScript(minutes: $expired ? 20 : 1);
    if ($userCancel) {
        $operation->update(['cancel_requested_at' => now()]);
    }
    if ($expired) {
        app(ExpireOperationAction::class)->execute($operation);
    }
    $operation->refresh()->update(['state' => [...$operation->state, 'native_cancel_confirmed_at' => now()->toISOString()]]);
    reconcileSocket($operation, $native, onHistory: function () use ($operation, $native, $exit, $nativeCode): array {
        $history = ['id' => RUN, 'workflowName' => 'operation-task-script', 'status' => $native,
            'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => 'tasks.script'],
            'startedAt' => now()->subMinutes(3)->toISOString(), 'completedAt' => now()->toISOString()];
        $history['jobs'] = [['name' => 'script', 'steps' => [[
            'name' => 'Execute the program', 'status' => $native === 'succeeded' ? 'succeeded' : 'failed',
            'startedAt' => now()->subMinute()->toISOString(), 'completedAt' => now()->toISOString(),
            'error' => $nativeCode ?? ($exit === -1 ? 'Command exited with code -1' : 'Command failed'),
            'outputs' => ['exitCode' => $exit, 'stdout' => 'RAW-OUTPUT', 'stderr' => 'RAW-ERROR'],
        ]]]];

        return $history;
    });
    // The absent family end-check and captures are immaterial to this generic receipt.
    $settled = app(SwampCommandStep::class)->projectTerminal($operation, ['id' => RUN],
        app(DirectOperation::class)->definition($operation->kind)['terminal_result']);

    expect($settled->status->value)->toBe($status)->and($settled->error_code)->toBe($code)
        ->and($settled->result)->toBe(['late_settlement' => true, 'native_status' => $native])
        ->and($settled->state['native_progress']['steps'][0]['exit_code'])->toBe($exit)
        ->and(OperationData::summary($settled)->toArray()['steps'][0]['exit_code'])->toBe($exit)
        ->and(json_encode(OperationData::summary($settled)->toArray(), JSON_THROW_ON_ERROR))
        ->not->toContain('RAW-OUTPUT')->not->toContain('RAW-ERROR');
})->with([
    'success after expiry' => ['succeeded', false, true, 0, null, 'failed', 'operation.timed_out'],
    'success after user cancel' => ['succeeded', true, false, 0, null, 'cancelled', null],
    'deadline abort' => ['cancelled', false, true, -1, null, 'failed', 'operation.timed_out'],
    'user abort' => ['cancelled', true, false, -1, null, 'cancelled', null],
    'pre-deadline user abort' => ['cancelled', true, true, -1, null, 'cancelled', null],
    'independent failure' => ['failed', false, true, 42, 'tasks.script_failed', 'failed', 'tasks.script_failed'],
    'independent timeout' => ['failed', false, true, 124, 'tasks.script_failed', 'failed', 'tasks.script_failed'],
    'failure before cancellation' => ['cancelled', false, true, 42, 'tasks.script_failed', 'failed', 'tasks.script_failed'],
]);

it('preserves independent native failures without a public code in generic late receipts', function (string $mode, bool $expired, bool $userCancel = true): void {
    Queue::fake();
    $operation = runningScript(minutes: $expired ? 20 : 1);
    if ($userCancel) {
        $operation->update(['cancel_requested_at' => now()]);
    }
    if ($expired) {
        app(ExpireOperationAction::class)->execute($operation);
    }
    $operation->refresh()->update(['state' => [...$operation->state, 'native_cancel_confirmed_at' => now()->toISOString()]]);
    $history = json_decode(file_get_contents(base_path('tests/Fixtures/instance-migrate/pinned-'.$mode.'.json')), true, flags: JSON_THROW_ON_ERROR);
    $history['id'] = RUN;
    $history['workflowName'] = 'operation-task-script';
    $history['status'] = 'cancelled'; // Same independent-failure-before-later-cancel interleaving as the migrate regression.
    $history['inputs'] = ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => $operation->kind];
    reconcileSocket($operation, 'cancelled', onHistory: fn () => $history);
    expect(NativeRunResult::cancelledFailure($history))->toBeFalse();
    $settled = app(SwampCommandStep::class)->projectTerminal($operation, ['id' => RUN], app(DirectOperation::class)->definition($operation->kind)['terminal_result']);
    expect([$settled->status->value, $settled->error_code])->toBe(['failed', 'operation.native_failed']);
})->with([
    'independent 42 then user cancel' => ['failure', false],
    'independent 124 then user cancel' => ['timeout', false],
    'independent 42 before user cancel and expiry' => ['failure', true],
    'independent 124 before user cancel and expiry' => ['timeout', true],
    'independent 42 before deadline cancel' => ['failure', true, false],
    'independent 124 before deadline cancel' => ['timeout', true, false],
]);

it('does not infer an independent native failure from cancellation without failed phases', function (bool $expired): void {
    Queue::fake();
    $operation = runningScript(minutes: $expired ? 20 : 1);
    if ($expired) {
        app(ExpireOperationAction::class)->execute($operation);
    } else {
        $operation->update(['cancel_requested_at' => now()]);
    }
    $operation->refresh()->update(['state' => [...$operation->state, 'native_cancel_confirmed_at' => now()->toISOString()]]);
    reconcileSocket($operation, 'cancelled');

    $settled = app(SwampCommandStep::class)->projectTerminal($operation, ['id' => RUN], app(DirectOperation::class)->definition($operation->kind)['terminal_result']);

    expect([$settled->status->value, $settled->error_code])->toBe($expired ? ['failed', 'operation.timed_out'] : ['cancelled', null]);
})->with(['deadline cancel' => true, 'user cancel' => false]);

it('reopens provisional expiry for an in-budget native cancellation without confirmed cancel', function (): void {
    Queue::fake();
    $operation = runningScript();
    $completed = OperationRunBudgets::deadline($operation)->subSecond()->toISOString();
    reconcileSocket($operation, 'cancelled', onHistory: function () use ($operation, $completed): array {
        app(ExpireOperationAction::class)->execute(Operation::query()->findOrFail($operation->id));

        return ['id' => RUN, 'workflowName' => 'operation-task-script', 'status' => 'cancelled',
            'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => $operation->kind],
            'startedAt' => $operation->started_at->toISOString(), 'completedAt' => $completed, 'jobs' => []];
    });

    $settled = app(SwampCommandStep::class)->projectTerminal($operation, ['id' => RUN], app(DirectOperation::class)->definition($operation->kind)['terminal_result']);

    expect([$settled->status->value, $settled->error_code, $settled->result])->toBe(['cancelled', null, []])
        ->and($settled->state['native_timeout']['native_terminal_status'])->toBe('cancelled');
});
