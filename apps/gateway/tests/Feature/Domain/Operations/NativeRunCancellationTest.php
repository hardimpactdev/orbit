<?php

declare(strict_types=1);

use App\Actions\Operations\CancelOperationAction;
use App\Actions\Operations\ExpireOperationAction;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\NativeRunCancellation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOperations;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Swamp\CancelSwampRun;
use App\Infrastructure\Swamp\NativeRunProgress;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Infrastructure\Swamp\SwampConnection;
use App\Jobs\Operations\ReconcileSwampOperations;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Yaml\Yaml;

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

it('keeps the state that another writer stored after the caller loaded the Operation', function (): void {
    Queue::fake();
    $stale = runningScript();
    app(NativeRunProgress::class)->apply($stale->id, RUN, ['kind' => 'step_started', 'stepId' => 'clone', 'jobId' => 'main', 'seq' => 1]);
    $fresh = Operation::query()->findOrFail($stale->id);
    app(OperationStore::class)->change($fresh, ['state' => [...$fresh->state, 'native_failure' => ['failed_step' => 'clone']]]);

    expect(app(NativeRunCancellation::class)->request($stale))->toBeTrue();

    $state = $stale->refresh()->state;
    expect($state)->toHaveKey('native_cancel_requested_at')
        ->and($state['native_failure'])->toBe(['failed_step' => 'clone'])
        ->and(array_column($state['native_progress']['steps'], 'name'))->toBe(['clone'])
        ->and($stale->current_step)->toBe('clone');
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

it('keeps a lockless script timeout unchanged and forwards an explicit renewed cancel after an hour', function (): void {
    Queue::fake();
    $operation = runningScript();
    $expired = app(ExpireOperationAction::class)->execute($operation);
    expect($expired->lock_key)->toBeNull();
    $before = $expired->getAttributes();
    $this->travel(61)->minutes();

    expect(app(ExpireOperationAction::class)->releaseStaleHolds())->toBe(0)
        ->and($expired->refresh()->getAttributes())->toBe($before);
    $socket = reconcileSocket($expired, 'running');
    reconcile();
    expect($socket->frames)->toBe([]);

    app(CancelOperationAction::class)->execute($expired);
    $renewed = $expired->refresh()->state['native_cancel_requested_at'];
    reconcile();

    expect(array_column($socket->frames, 'type'))->toBe(['workflow.history.get', 'workflow.cancel'])
        ->and($expired->refresh()->status)->toBe(OperationStatus::Failed)
        ->and($expired->error_code)->toBe('operation.timed_out')
        ->and($expired->state['native_cancel_requested_at'])->toBe($renewed)
        ->and($expired->state)->toHaveKey('native_cancel_confirmed_at')
        ->and($expired->state['native_timeout'])->not->toHaveKey('native_terminal_status');
});

/** A running `instance.complete` or `instance.create` whose run is bound, and whose step `$step` started `$seconds` ago. */
function copyingRun(string $kind = 'instance.complete', ?string $step = 'server-database-fill', int $seconds = 5, ?int $index = null): Operation
{
    $address = '10.44.1.'.(Node::query()->count() + 10);
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'copy-'.uniqid(), 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => $address, 'wireguard_ip' => $address, 'user' => 'orbit']));
    $operation = Operation::query()->create(['kind' => $kind, 'target_type' => 'instance', 'target_id' => 1, 'node_id' => $node->id,
        'input' => ['instance_id' => 1], 'status' => OperationStatus::Running, 'state' => ['swamp_run_id' => RUN], 'sequence' => 1, 'attempts' => 1,
        'started_at' => now()->subMinutes(2)]);
    if ($step !== null) {
        app(NativeRunProgress::class)->apply($operation->id, RUN, ['kind' => 'step_started', 'stepId' => $step, 'jobId' => 'complete', 'seq' => 1,
            'startedAt' => now()->subSeconds($seconds)->toIso8601ZuluString('millisecond'), ...($index === null ? [] : ['forEachIndex' => $index])]);
    }

    return $operation->refresh();
}

function cancelRecorded(Operation $operation, int $minutesAgo = 0): Operation
{
    $operation->update(['cancel_requested_at' => now()->subMinutes($minutesAgo),
        'state' => [...$operation->state, 'native_cancel_requested_at' => now()->subMinutes($minutesAgo)->toISOString()]]);

    return $operation->refresh();
}

describe('a cancel while a database copy runs', function (): void {
    it('is held while the copy step runs and forwarded at once when the step ends', function (string $end, string $kind, string $step): void {
        Queue::fake();
        $operation = cancelRecorded(copyingRun($kind, $step));
        $cancellation = app(NativeRunCancellation::class);
        expect($cancellation->awaitsForwarding($operation))->toBeFalse();

        app(NativeRunProgress::class)->apply($operation->id, RUN, ['kind' => $end, 'stepId' => $step, 'jobId' => 'complete', 'seq' => 2]);

        expect($cancellation->awaitsForwarding($operation->refresh()))->toBeTrue();
        Queue::assertPushed(ReconcileSwampOperations::class);
    })->with([
        'completion fill succeeded' => ['step_completed', 'instance.complete', 'server-database-fill'],
        'completion fill failed' => ['step_failed', 'instance.complete', 'server-database-fill'],
        'clone create fill succeeded' => ['step_completed', 'instance.create', 'clone-server-database-fill'],
        'host create fill failed' => ['step_failed', 'instance.create', 'server-database-fill'],
    ]);

    it('forwards at once while another step runs, with no progress yet, and for a kind without a copy step', function (): void {
        $cancellation = app(NativeRunCancellation::class);
        expect($cancellation->awaitsForwarding(cancelRecorded(copyingRun(step: 'clone-services'))))->toBeTrue()
            ->and($cancellation->awaitsForwarding(cancelRecorded(copyingRun(step: null))))->toBeTrue()
            ->and($cancellation->awaitsForwarding(cancelRecorded(copyingRun('instance.deploy', 'server-database-fill'))))->toBeTrue();
    });

    it('holds a forEach item of a copy step by its bare step name and lets its end release it', function (): void {
        Queue::fake();
        $operation = cancelRecorded(copyingRun(index: 0));
        expect(app(NativeRunCancellation::class)->awaitsForwarding($operation))->toBeFalse();

        app(NativeRunProgress::class)->apply($operation->id, RUN, ['kind' => 'step_completed', 'stepId' => 'server-database-fill', 'jobId' => 'complete', 'seq' => 2]);

        expect(app(NativeRunCancellation::class)->awaitsForwarding($operation->refresh()))->toBeTrue();
    });

    it('still counts the one-hour forward window from the request', function (): void {
        $operation = cancelRecorded(copyingRun(), minutesAgo: NativeRunCancellation::ForwardMinutes + 1);
        app(NativeRunProgress::class)->apply($operation->id, RUN, ['kind' => 'step_completed', 'stepId' => 'server-database-fill', 'jobId' => 'complete', 'seq' => 2]);

        expect(app(NativeRunCancellation::class)->awaitsForwarding($operation->refresh()))->toBeFalse();
    });

    it('forwards anyway and logs it when the copy step shows running past its bound, as when its end event never arrives', function (): void {
        Log::spy();
        $bound = NativeRunCancellation::CopySteps['instance.complete']['server-database-fill'];
        $operation = cancelRecorded(copyingRun(seconds: $bound + NativeRunCancellation::CopyMarginSeconds + 1));

        expect(app(NativeRunCancellation::class)->awaitsForwarding($operation))->toBeTrue();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'operation.cancel_copy_bound_passed'
            && $context['operation_id'] === $operation->id && $context['step'] === 'server-database-fill')->once();
        expect(app(NativeRunCancellation::class)->awaitsForwarding(cancelRecorded(copyingRun(seconds: $bound))))->toBeFalse();
    });

    it('lets an operator cancel record the request and send nothing to Swamp while the copy runs', function (string $kind, string $step): void {
        Queue::fake();
        $operation = copyingRun($kind, $step);
        $socket = reconcileSocket($operation, 'running');

        app(CancelOperationAction::class)->execute($operation);

        expect($operation->refresh()->cancel_requested_at)->not->toBeNull()
            ->and($operation->state['native_cancel_requested_at'] ?? null)->not->toBeNull()
            ->and(array_column($socket->frames, 'type'))->not->toContain('workflow.cancel')
            ->and(app(NativeRunCancellation::class)->awaitsForwarding($operation))->toBeFalse();
    })->with([['instance.complete', 'server-database-fill'], ['instance.create', 'clone-server-database-fill']]);

    it('forwards a cancel recorded while the start was in flight as soon as the run binds', function (): void {
        Queue::fake();
        $operation = copyingRun(step: null);
        $operation->update(['state' => ['swamp_start' => ['key' => 'operation:'.$operation->id, 'workflow' => 'operation-instance-complete']],
            'cancel_requested_at' => now(), 'attempts' => 0]);
        $bind = new ReflectionMethod(StartSwampRun::class, 'bind');

        $bind->invoke(app(StartSwampRun::class), $operation->refresh(), RUN);

        expect($operation->refresh()->state['native_cancel_requested_at'] ?? null)->not->toBeNull();
        Queue::assertPushed(ReconcileSwampOperations::class);
    });

    it('names, for each kind, steps that exist in its workflow with the bound of their own timeout, and every server copy step', function (): void {
        $kinds = collect(app(DirectOperation::class)->definitions())->filter(fn (array $definition): bool => isset($definition['workflow']))
            ->mapWithKeys(fn (array $definition, string $kind): array => [$definition['workflow'] => $kind]);
        $found = [];
        // Every workflow file, child workflows too: a step that runs the server database fill must belong to a kind and be listed.
        foreach (glob(base_path('../../swamp/workflows/*.yaml')) as $file) {
            if (! str_contains((string) file_get_contents($file), 'server_database.fill')) {
                continue;
            }
            $flow = Yaml::parseFile($file);
            foreach (collect($flow['jobs'] ?? [])->flatMap(fn (array $job): array => $job['steps'] ?? []) as $step) {
                $argv = $step['task']['inputs']['argv'] ?? '';
                if (str_contains(is_string($argv) ? $argv : (string) json_encode($argv), 'server_database.fill')) {
                    $found[$kinds[$flow['name']] ?? $flow['name']][$step['name']] = $step['task']['inputs']['argv'];
                }
            }
        }
        expect(array_keys($found))->toEqualCanonicalizing(array_keys(NativeRunCancellation::CopySteps));
        foreach (NativeRunCancellation::CopySteps as $kind => $steps) {
            expect(array_keys($found[$kind]))->toEqualCanonicalizing(array_keys($steps));
            foreach ($steps as $step => $bound) {
                expect($found[$kind][$step])->toStartWith('${{ ["timeout", "'.$bound.'"]');
            }
        }
    });

    it('sends no cancel that a copy step started to hold after the pass read the Operation', function (): void {
        $operation = cancelRecorded(copyingRun(step: 'server-database-check'));
        expect(app(NativeRunCancellation::class)->awaitsForwarding($operation))->toBeTrue();
        app(NativeRunProgress::class)->apply($operation->id, RUN, ['kind' => 'step_started', 'stepId' => 'server-database-fill', 'jobId' => 'complete', 'seq' => 2]);
        $socket = reconcileSocket($operation, 'running');
        $forward = new ReflectionMethod(ReconcileSwampOperations::class, 'forwardCancel');

        $forward->invoke(new ReconcileSwampOperations, $operation, app(CancelSwampRun::class), app(OperationStore::class));

        expect(array_column($socket->frames, 'type'))->not->toContain('workflow.cancel')
            ->and($operation->refresh()->state['native_cancel_confirmed_at'] ?? null)->toBeNull();
    });

    it('records a held operator cancel again when an earlier request\'s window has closed', function (): void {
        Queue::fake();
        $operation = cancelRecorded(copyingRun(), minutesAgo: NativeRunCancellation::ForwardMinutes + 5);

        app(CancelOperationAction::class)->execute($operation);
        app(NativeRunProgress::class)->apply($operation->id, RUN, ['kind' => 'step_completed', 'stepId' => 'server-database-fill', 'jobId' => 'complete', 'seq' => 2]);

        expect(app(NativeRunCancellation::class)->awaitsForwarding($operation->refresh()))->toBeTrue();
    });
});
