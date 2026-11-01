<?php

declare(strict_types=1);

use App\Actions\Operations\ExpireOperationAction;
use App\Actions\Operations\ReadOperationAction;
use App\Data\Operations\OperationData;
use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampRunSubscriber;
use App\Jobs\Operations\RecoverUnboundSwampRun;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Psr\Log\NullLogger;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\FakeSwampEventSocket;

function budgetOperation(string $kind = 'instance.create', array $attributes = []): Operation
{
    $node = Node::query()->create(['name' => 'budget-target', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.9']);

    return Operation::query()->create([
        'kind' => $kind, 'target_type' => 'node', 'target_id' => $node->id, 'node_id' => $node->id,
        'status' => OperationStatus::Running, 'sequence' => 1, 'started_at' => now(),
        'input' => ['run_budget_seconds' => 60], 'state' => ['swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676'],
        'lock_key' => 'budget:'.$node->id, 'current_step' => 'source', ...$attributes,
    ]);
}

beforeEach(function (): void {
    $this->freezeSecond();
    Http::preventStrayRequests();
});

it('expires create at its captured budget while retaining F1 evidence and requesting operator cancellation', function (): void {
    $operation = budgetOperation(attributes: ['state' => ['swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676',
        'native_failure' => ['failed_step' => 'source', 'detail' => 'Safe native diagnostic.', 'stderr_recorded' => true],
        'retained' => true], 'result' => ['public_observation' => 'retained']]);
    $deadline = OperationRunBudgets::deadline($operation);
    $this->travel(59)->seconds();
    app(ExpireOperationAction::class)->execute($operation);
    expect($operation->fresh()->status)->toBe(OperationStatus::Running);
    expect($operation->fresh()->cancel_requested_at)->toBeNull();
    $this->travel(1)->seconds();

    $expired = app(ExpireOperationAction::class)->execute($operation);
    $public = OperationData::fromModel($expired)->toArray();

    expect($expired->status)->toBe(OperationStatus::Failed);
    expect($expired->error_code)->toBe('operation.timed_out');
    expect($expired->cancel_requested_at?->equalTo(now()))->toBeTrue();
    expect($expired->failed_step)->toBe('source');
    expect($expired->current_step)->toBeNull();
    expect($expired->lock_key)->toBe($operation->lock_key);
    expect($expired->state['swamp_run_id'])->toBe('a075eb23-7ac4-4487-80d1-57a038a92676');
    expect($expired->state['retained'])->toBeTrue();
    expect($expired->state['native_timeout'])->toBe(['budget_seconds' => 60, 'deadline' => $deadline->toISOString()]);
    expect($expired->result)->toBe(['public_observation' => 'retained', 'error_code' => 'operation.timed_out']);
    expect($public['error'])->toBe(['code' => 'operation.timed_out', 'detail' => 'Safe native diagnostic.']);
    $sequence = $expired->sequence;
    app(ExpireOperationAction::class)->execute($expired);
    expect($expired->fresh()->sequence)->toBe($sequence);
    Http::assertNothingSent();
});

it('uses the default three hundred second budget for other native kinds', function (): void {
    $operation = budgetOperation('instance.rename', ['input' => []]);
    expect(OperationRunBudgets::seconds($operation))->toBe(300);
    $this->travel(299)->seconds();
    app(ExpireOperationAction::class)->execute($operation);
    expect($operation->fresh()->status)->toBe(OperationStatus::Running);
    $this->travel(1)->seconds();
    $expired = app(ExpireOperationAction::class)->execute($operation);

    expect($expired->error_code)->toBe('operation.timed_out');
    expect(OperationData::fromModel($expired)->toArray()['error']['detail'])->toContain('300-second');
});

it('leaves Gateway-only jobs outside the native run deadline', function (string $kind, bool $started): void {
    $operation = budgetOperation($kind, ['status' => $started ? OperationStatus::Running : OperationStatus::Queued,
        'started_at' => $started ? now() : null]);
    $this->travel(600)->seconds();
    app(ExpireOperationAction::class)->execute($operation);

    expect($operation->fresh()->sequence)->toBe(1);
    expect($operation->fresh()->cancel_requested_at)->toBeNull();
})->with([['test.gateway_only', true]]);

it('preserves a winning native terminal result even when expiry has a stale Operation object', function (string $status): void {
    $operation = budgetOperation();
    $this->travel(60)->seconds();
    Operation::query()->whereKey($operation->id)->update(['status' => $status, 'result' => ['native' => 'retained']]);

    $result = app(ExpireOperationAction::class)->execute($operation);

    expect($result->status->value)->toBe($status);
    expect($result->result)->toBe(['native' => 'retained']);
    expect($result->sequence)->toBe(1);
    expect($result->cancel_requested_at)->toBeNull();
})->with(['succeeded', 'failed', 'cancelled', 'interrupted']);

it('applies overdue expiry before a native read even when history and the observer are unavailable', function (): void {
    $operation = budgetOperation();
    $this->travel(60)->seconds();
    $socket = new FakeSwampEventSocket;
    $socket->onConnect = static function (): void {
        throw new RuntimeException('No history read is allowed.');
    };
    app()->instance(WebSocketClient::class, $socket);

    $result = app(ReadOperationAction::class)->execute($operation);

    expect($result->error_code)->toBe('operation.timed_out');
    expect($socket->sent)->toBeEmpty();
    expect($socket->endpoints)->toBeEmpty();
});

it('expires tracked roots through the existing observer while the native socket is disconnected', function (): void {
    $operation = budgetOperation();
    config(['swamp.run_token' => null]);
    $socket = new FakeSwampEventSocket;
    $subscriber = new SwampRunSubscriber($socket, new NullLogger, static fn (): float => now()->getTimestamp());
    $subscriber->pass();
    $this->travel(60)->seconds();

    $subscriber->pass();

    expect($operation->fresh()->error_code)->toBe('operation.timed_out');
    expect($operation->fresh()->cancel_requested_at)->not->toBeNull();
    expect($socket->sent)->toBeEmpty();
});

it('recovers overdue unbound and waiting roots without waiting for history or overwriting a prior cancel', function (): void {
    $requested = now()->toImmutable();
    $operation = budgetOperation(attributes: ['status' => OperationStatus::Waiting, 'state' => [], 'cancel_requested_at' => $requested]);
    $this->travel(60)->seconds();
    $socket = new FakeSwampEventSocket;

    (new SwampRunSubscriber($socket, new NullLogger))->recover();

    expect($operation->fresh()->error_code)->toBe('operation.timed_out');
    expect($operation->fresh()->cancel_requested_at?->equalTo($requested))->toBeTrue();
    expect($socket->sent)->toBeEmpty();
});

it('uses a captured heavy create phase plan instead of the superseded fixed sixty seconds', function (): void {
    $operation = budgetOperation(attributes: ['input' => ['run_budget_seconds' => 180,
        'phase_budgets' => ['source' => 30, 'environment' => 30, 'setup' => 120]]]);
    $this->travel(179)->seconds();
    app(ExpireOperationAction::class)->execute($operation);
    expect($operation->fresh()->status)->toBe(OperationStatus::Running);
    $this->travel(1)->seconds();
    $expired = app(ExpireOperationAction::class)->execute($operation);
    expect($expired->state['native_timeout']['budget_seconds'])->toBe(180);
    expect($expired->error_code)->toBe('operation.timed_out');
});

it('uses the provisional default for a create without a captured phase plan', function (): void {
    $operation = budgetOperation(attributes: ['input' => []]);
    expect(OperationRunBudgets::seconds($operation))->toBe(300);
});

it('retains target exclusion until exact native terminal history releases it without reopening timeout', function (string $status): void {
    $operation = budgetOperation('node.bootstrap_probe');
    $this->travel(300)->seconds();
    $expired = app(ExpireOperationAction::class)->execute($operation);
    $competing = ['kind' => 'instance.rename', 'target_type' => 'node', 'target_id' => $operation->target_id,
        'node_id' => $operation->node_id, 'status' => 'queued', 'sequence' => 1, 'input' => [], 'state' => [], 'lock_key' => $operation->lock_key];
    expect(fn () => Operation::query()->create($competing))->toThrow(UniqueConstraintViolationException::class);
    $history = new FakeRoutePublicationSocket;
    $history->reply = static fn (array $request): array => [['type' => 'workflow.history.get', 'id' => $request['id'], 'payload' => ['data' => [
        'id' => $operation->state['swamp_run_id'], 'workflowName' => 'operation-node-bootstrap-probe', 'status' => $status,
        'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => $operation->kind],
    ]]]];
    app()->instance(WebSocketClient::class, $history);
    config(['swamp.read_token' => 'fixture.read', 'swamp.address' => '192.0.2.209', 'swamp.ca_path' => '/public/ca.pem']);

    $settled = app(SwampCommandStep::class)->projectTerminal($expired,
        ['id' => $operation->state['swamp_run_id']], ['job' => 'bootstrap', 'step' => 'probe-host']);

    expect($settled->lock_key)->toBeNull();
    expect($settled->status)->toBe(OperationStatus::Failed);
    expect($settled->error_code)->toBe('operation.timed_out');
    expect($settled->state['native_timeout']['native_terminal_status'])->toBe($status);
    expect($settled->result)->toBe($expired->result);
    expect(Operation::query()->create($competing)->id)->not->toBe($operation->id);
})->with(['succeeded', 'failed', 'cancelled', 'interrupted']);

it('keeps budget reception live while an uncertain-start history lookup waits on the operations queue', function (): void {
    Bus::fake([RecoverUnboundSwampRun::class]);
    $operation = budgetOperation('node.bootstrap_probe', ['state' => [], 'error_code' => 'swamp.start_uncertain']);
    $operation->update(['state' => ['swamp_start' => ['key' => 'operation:'.$operation->id, 'workflow' => 'operation-node-bootstrap-probe']]]);
    $history = new FakeSwampEventSocket;
    $history->onConnect = static function (): void {
        throw new RuntimeException('The observer must not read history.');
    };
    app()->instance(WebSocketClient::class, $history);
    $subscriber = new SwampRunSubscriber(new FakeSwampEventSocket, new NullLogger, static fn (): float => now()->getTimestamp());
    $subscriber->pass();
    Bus::assertDispatched(RecoverUnboundSwampRun::class,
        fn ($job): bool => $job->operationId === $operation->id && $job->queue === 'operations');
    $this->travel(300)->seconds();

    $subscriber->pass();

    expect($operation->fresh()->error_code)->toBe('operation.timed_out');
    expect($operation->fresh()->lock_key)->toBe($operation->lock_key);
    expect($history->sent)->toBeEmpty();
});

it('keeps the timed out root attached until a verified native terminal receipt releases its hold', function (): void {
    config(['queue.connections.gateway.driver' => 'sync', 'swamp.run_token' => 'fixture.run',
        'swamp.read_token' => 'fixture.read', 'swamp.address' => '192.0.2.209', 'swamp.ca_path' => '/public/ca.pem']);
    $operation = budgetOperation('node.bootstrap_probe');
    $events = new FakeSwampEventSocket;
    $history = new FakeRoutePublicationSocket;
    $history->reply = static fn (array $request): array => [['type' => 'workflow.history.get', 'id' => $request['id'], 'payload' => ['data' => [
        'id' => $operation->state['swamp_run_id'], 'workflowName' => 'operation-node-bootstrap-probe', 'status' => 'cancelled',
        'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => $operation->kind],
    ]]]];
    app()->instance(WebSocketClient::class, $history);
    $subscriber = new SwampRunSubscriber($events, new NullLogger, static fn (): float => now()->getTimestamp());
    $subscriber->pass();
    $request = $events->sent[0];
    $events->push(['type' => 'run.attached', 'id' => $request['id'], 'payload' => ['runId' => $operation->state['swamp_run_id'], 'kind' => 'workflow-run']]);
    $subscriber->pass();
    $this->travel(300)->seconds();
    $subscriber->pass();
    expect($operation->fresh()->lock_key)->not->toBeNull();
    $events->push(['type' => 'event', 'id' => $request['id'], 'event' => ['seq' => 5, 'kind' => 'completed', 'run' => [
        'id' => $operation->state['swamp_run_id'], 'workflowName' => 'operation-node-bootstrap-probe', 'status' => 'cancelled',
    ]]]);

    $subscriber->pass();

    expect($operation->fresh()->error_code)->toBe('operation.timed_out');
    expect($operation->fresh()->lock_key)->toBeNull();
    expect($operation->fresh()->state['native_timeout']['native_terminal_status'])->toBe('cancelled');
    expect($history->sent)->toHaveCount(1);
});

it('expires never-started native admissions on the first observer startup with no run or attempts', function (string $kind, string $status, bool $started, bool $requested): void {
    $cancelled = $requested ? now()->subSeconds(500)->toImmutable() : null;
    $operation = budgetOperation($kind, ['status' => $status, 'attempts' => 0, 'started_at' => $started ? now()->subSeconds(600) : null,
        'cancel_requested_at' => $cancelled, 'state' => ['retained' => true], 'input' => [],
        'error_code' => $kind === 'doctor.inspect' ? 'swamp.start_uncertain' : null]);
    $operation->forceFill(['created_at' => now()->subSeconds(600)])->save();
    $socket = new FakeSwampEventSocket;
    $subscriber = new SwampRunSubscriber($socket, new NullLogger);

    $subscriber->pass();

    $expired = $operation->fresh();
    expect($expired->status)->toBe(OperationStatus::Failed);
    expect($expired->error_code)->toBe('operation.timed_out');
    expect($expired->attempts)->toBe(0);
    expect($expired->state)->not->toHaveKey('swamp_run_id');
    expect($expired->state['retained'])->toBeTrue();
    expect($expired->cancel_requested_at?->equalTo($cancelled ?? now()))->toBeTrue();
    expect($socket->sent)->toBeEmpty();
    expect($socket->endpoints)->toBeEmpty();
})->with([
    ['vault.sync', 'running', true, true],
    ['doctor.inspect', 'running', true, false],
    ['doctor.inspect', 'queued', false, true],
    ['vault.sync', 'queued', false, false],
]);

it('keeps a long native kind running past the default budget until its measured budget', function (): void {
    $operation = budgetOperation('task_vm.image_build', ['input' => []]);
    $this->travel(301)->seconds();
    app(ExpireOperationAction::class)->execute($operation);
    expect($operation->fresh()->status)->toBe(OperationStatus::Running);
    $this->travel(2400 - 301)->seconds();

    expect(app(ExpireOperationAction::class)->execute($operation)->error_code)->toBe('operation.timed_out');
});

it('reads the measured budget of each long native kind', function (string $kind, int $seconds): void {
    expect(OperationRunBudgets::seconds(budgetOperation($kind, ['input' => []])))->toBe($seconds);
})->with([
    ['certificate.converge', 1200], ['cluster.router_set', 2700], ['node.add', 4500], ['node.converge', 3900],
    ['node.role_add', 2700], ['task_vm.destroy', 2700], ['task_vm.image_build', 2400], ['task_vm.sweep_host', 600],
    ['tasks.provision', 3900],
]);
