<?php

declare(strict_types=1);

use App\Actions\Operations\CancelOperationAction;
use App\Actions\Operations\ExpireOperationAction;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\NativeRunCancellation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOperations;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Swamp\CancelSwampRun;
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
        ->and($operation->state['admission_hold_key'])->toBe(OperationStore::lockKey('task_group', $operation->target_id));
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

it('keeps a superseded non-migrate receipt without applying its family domain projection', function (): void {
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
        ->and($settled->result['superseded_by'])->toBe($new->id)
        ->and($settled->state['projection_superseded_by'])->toBe($new->id)
        ->and($settled->state['native_timeout']['native_terminal_status'])->toBe('failed');
});

it('serializes a retry admission attempted during late domain projection on the same durable row', function (): void {
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
            DB::setDefaultConnection('late_admit');
            try {
                expect(fn () => app(OperationStore::class)->create($operation->kind, $operation->target_type,
                    $operation->target_id, $operation->node_id, $operation->input, null, null))
                    ->toThrow(PDOException::class, 'database is locked');
            } finally {
                DB::setDefaultConnection('late_settle');
            }

            return app(DirectOperation::class)->project($operation, $data, $data['result']);
        }
    });
    reconcileSocket($old, 'failed');
    try {
        $settled = app(SwampCommandStep::class)->projectTerminal(Operation::query()->findOrFail($old->id), ['id' => RUN], $definition['terminal_result']);
        expect($attempted)->toBeTrue()->and($settled->status)->toBe(OperationStatus::Failed);
        // Once settlement releases the transaction, the same admission succeeds and becomes the new owner.
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

it('transfers only the exact admission hold after release, preserving independent scopes of the same target', function (): void {
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

    expect($old->refresh()->state['projection_superseded_by'])->toBe($retry->id)
        ->and($old->state['admission_hold_key'])->toBe('manager:first')
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
