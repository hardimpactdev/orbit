<?php

declare(strict_types=1);

use App\Actions\Operations\CancelOperationAction;
use App\Actions\Operations\ExpireOperationAction;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\NativeOperationProjection;
use App\Domain\Operations\NativeRunCancellation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOperations;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Swamp\CancelSwampRun;
use App\Infrastructure\Swamp\SwampConnection;
use App\Jobs\Operations\ProjectSwampOperation;
use App\Jobs\Operations\ReconcileSwampOperations;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Task;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

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
function reconcileSocket(?Operation $operation, string $status, string $cancel = 'cancellation_requested', ?Closure $onCancel = null): WebSocketClient
{
    $history = $operation === null ? [] : ['id' => RUN, 'workflowName' => 'operation-task-script', 'status' => $status,
        'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => 'tasks.script'],
        'startedAt' => now()->subMinutes(3)->toIso8601ZuluString('millisecond'), 'completedAt' => now()->toIso8601ZuluString('millisecond'), 'jobs' => []];
    $socket = new class($history, $cancel, $onCancel) implements WebSocketClient
    {
        /** @var list<array<string,mixed>> */
        public array $frames = [];

        private bool $open = false;

        /** @param array<string,mixed> $history */
        public function __construct(private readonly array $history, private readonly string $cancel, private readonly ?Closure $onCancel) {}

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
                'workflow.history.get' => ['type' => 'workflow.history.get', 'payload' => ['data' => $this->history]],
                'workflow.cancel' => $this->cancel === 'error'
                    ? ['type' => 'error', 'error' => ['code' => 'workflow_cancel_failed']]
                    : ['type' => 'workflow.cancel', 'payload' => ['data' => ['runId' => RUN, 'status' => $this->cancel]]],
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
        ->and($operation->state['native_timeout']['native_terminal_status'])->toBe('unproven');
});

it('expires an unbound Operation at its admission deadline', function (): void {
    Queue::fake();
    $operation = runningScript(null, 20);
    $operation->update(['attempts' => 0]);
    reconcileSocket(null, 'running');

    reconcile();

    expect($operation->refresh()->error_code)->toBe('operation.timed_out');
});

it('stops forwarding a cancel one hour after it was requested', function (): void {
    Queue::fake();
    $operation = runningScript();
    app(ExpireOperationAction::class)->execute($operation);
    $this->travel(61)->minutes();
    $socket = reconcileSocket($operation, 'running', cancel: 'error');

    reconcile();

    expect($socket->frames)->toBe([])
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

function projectOne(Operation $operation, int $attempt = 1, ?int $releasedFor = null): void
{
    $queued = Mockery::mock(Job::class);
    $queued->shouldReceive('attempts')->andReturn($attempt);
    $releasedFor === null ? $queued->shouldNotReceive('release') : $queued->shouldReceive('release')->once()->with($releasedFor);
    $job = new ProjectSwampOperation($operation->id);
    $job->setJob($queued);
    app()->call([$job, 'handle']);
}

it('projects one ended run in its own job, with no reconcile pass', function (): void {
    $operation = runningScript(minutes: 3);
    reconcileSocket($operation, 'failed');

    projectOne($operation);

    expect($operation->refresh()->status)->toBe(OperationStatus::Failed);
});

it('reads the history again a second later while the run is not terminal there, and stops after its last try', function (): void {
    $operation = runningScript(minutes: 3);
    reconcileSocket($operation, 'running');

    projectOne($operation, releasedFor: 1);
    projectOne($operation, attempt: (new ProjectSwampOperation($operation->id))->tries);

    expect($operation->refresh()->status)->toBe(OperationStatus::Running);
});

it('does not project, or read the history of, an Operation that does not use the native terminal', function (): void {
    $operation = runningScript(minutes: 3);
    $projection = app(DirectOperation::class)->definition('tasks.script')['projection'];
    app()->instance($projection, new class implements DirectOperationProjection, NativeOperationProjection
    {
        public function native(Operation $operation): bool
        {
            return false;
        }

        public function input(Operation $operation): array
        {
            return [];
        }

        public function apply(Operation $operation, array $data): Operation
        {
            throw new LogicException('Projected.');
        }
    });
    $socket = reconcileSocket($operation, 'failed');

    projectOne($operation);

    expect($operation->refresh()->status)->toBe(OperationStatus::Running)
        ->and($socket->frames)->toBe([]);
});

it('logs the Operation and the type of an exception that fails the projection job, and nothing else', function (): void {
    Log::spy();

    (new ProjectSwampOperation(7))->failed(new RuntimeException('text that may hold an input'));

    Log::shouldHaveReceived('warning')->once()->with('The Operation projection job failed; the next reconcile pass settles it.', ['operation_id' => 7, 'exception_type' => RuntimeException::class]);
});
