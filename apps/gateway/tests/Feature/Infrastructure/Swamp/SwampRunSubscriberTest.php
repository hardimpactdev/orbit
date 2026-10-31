<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationContribution;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampRunSubscriber;
use App\Jobs\Operations\ProjectSwampTerminal;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Psr\Log\NullLogger;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\FakeSwampEventSocket;

final readonly class NativeSubscriberProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return ['intent' => ['name' => 'public'], 'execution_secrets' => []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return app(DirectOperation::class)->project($operation, $data, $data['result']);
    }
}

function nativeSubscriberOperation(string $run = 'a075eb23-7ac4-4487-80d1-57a038a92676'): Operation
{
    app()->instance(DirectOperation::class, new DirectOperation([new class implements DirectOperationContribution
    {
        public function definitions(): array
        {
            return ['test.native_events' => ['workflow' => 'operation-test-native-events', 'workflow_inputs' => true,
                'terminal_result' => ['job' => 'completion', 'step' => 'result'], 'projection' => NativeSubscriberProjection::class]];
        }
    }]));
    $node = Node::query()->create(['name' => 'native-events-'.substr($run, 0, 8), 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.9']);

    return Operation::query()->create(['kind' => 'test.native_events', 'target_type' => 'node', 'target_id' => $node->id,
        'node_id' => $node->id, 'status' => OperationStatus::Running, 'sequence' => 1, 'input' => ['private' => 'never-queue-this'],
        'state' => ['swamp_run_id' => $run], 'started_at' => '2026-10-31T14:00:00Z']);
}

function nativeSubscriberFrame(array $request, Operation $operation, string $status = 'succeeded'): array
{
    return ['type' => 'event', 'id' => $request['id'], 'event' => ['seq' => 7,
        'kind' => $status === 'cancelled' ? 'cancelled' : 'completed',
        'run' => ['id' => $operation->state['swamp_run_id'], 'workflowName' => 'operation-test-native-events', 'status' => $status]]];
}

function nativeSubscriberAttached(array $request): array
{
    return ['type' => 'run.attached', 'id' => $request['id'], 'payload' => [
        'runId' => $request['payload']['runId'], 'kind' => 'workflow-run', 'startedAt' => '2026-10-31T14:00:00Z']];
}

beforeEach(function (): void {
    config(['swamp.run_token' => 'private-native-token', 'swamp.address' => '192.0.2.209', 'swamp.ca_path' => '/public/ca.pem']);
});

it('keeps multiple bound streams attached and queues each terminal frame without a history read in the observer', function (string $status): void {
    Queue::fake([ProjectSwampTerminal::class]);
    $first = nativeSubscriberOperation();
    $second = nativeSubscriberOperation('25b90c36-1497-45be-ab30-3f1fe650e8f2');
    $socket = new FakeSwampEventSocket;
    $subscriber = new SwampRunSubscriber($socket, new NullLogger);
    $subscriber->pass();
    expect(array_column($socket->sent, 'type'))->toBe(['run.attach', 'run.attach']);
    expect($socket->sent[0]['payload'])->toBe(['runId' => $first->state['swamp_run_id'], 'afterSeq' => 0]);
    expect($socket->endpoints[0]->bearerToken)->toBe('private-native-token');
    expect($socket->endpoints[0]->caPath)->toBe('/public/ca.pem');
    $socket->push(nativeSubscriberAttached($socket->sent[0]), nativeSubscriberAttached($socket->sent[1]),
        nativeSubscriberFrame($socket->sent[0], $first, $status), nativeSubscriberFrame($socket->sent[1], $second, $status));

    $subscriber->pass();

    Queue::assertPushed(ProjectSwampTerminal::class, 2);
    Queue::assertPushed(ProjectSwampTerminal::class, fn ($job): bool => $job->operationId === $first->id
        && $job->runId === $first->state['swamp_run_id'] && $job->status === $status
        && $job->connection === 'gateway' && $job->queue === 'operations' && $job->tries === 1);
    expect(array_column($socket->sent, 'type'))->toBe(['run.attach', 'run.attach']);
    expect($first->fresh()->status)->toBe(OperationStatus::Running);
})->with(['succeeded', 'failed', 'cancelled', 'interrupted']);

it('projects a queued terminal notice through the one shared history reader and fixed selector', function (): void {
    Queue::fake([ProjectSwampTerminal::class]);
    $operation = nativeSubscriberOperation();
    $native = new FakeSwampEventSocket;
    $subscriber = new SwampRunSubscriber($native, new NullLogger);
    $subscriber->pass();
    $native->push(nativeSubscriberAttached($native->sent[0]), nativeSubscriberFrame($native->sent[0], $operation));
    $subscriber->pass();
    $history = new FakeRoutePublicationSocket;
    $history->reply = static fn (array $request): array => [['type' => 'workflow.history.get', 'id' => $request['id'], 'payload' => ['data' => [
        'id' => $operation->state['swamp_run_id'], 'workflowName' => 'operation-test-native-events', 'status' => 'succeeded',
        'inputs' => ['operation_id' => $operation->id, 'kind' => $operation->kind, 'node_id' => $operation->node_id],
        'startedAt' => '2026-10-31T14:00:00Z', 'completedAt' => '2026-10-31T14:00:01Z',
        'jobs' => [['name' => 'completion', 'steps' => [['name' => 'result', 'outputs' => ['stdout' => '{"ready":true}']]]]],
    ]]]];
    app()->instance(WebSocketClient::class, $history);
    $job = Queue::pushed(ProjectSwampTerminal::class)->first();

    $job->handle(app(SwampCommandStep::class));

    expect($operation->fresh()->status)->toBe(OperationStatus::Succeeded);
    expect($operation->fresh()->result)->toBe(['ready' => true]);
    expect(array_column($history->sent, 'type'))->toBe(['workflow.history.get']);
    expect(serialize($job))->not->toContain('never-queue-this')->not->toContain('private-native-token');
});

it('ignores unrelated child malformed and unattached terminal frames', function (string $change): void {
    Queue::fake([ProjectSwampTerminal::class]);
    $operation = nativeSubscriberOperation();
    $socket = new FakeSwampEventSocket;
    $subscriber = new SwampRunSubscriber($socket, new NullLogger);
    $subscriber->pass();
    $frame = nativeSubscriberFrame($socket->sent[0], $operation);
    match ($change) {
        'request' => $frame['id'] = 'another-request',
        'run' => $frame['event']['run']['id'] = '25b90c36-1497-45be-ab30-3f1fe650e8f2',
        'workflow' => $frame['event']['run']['workflowName'] = 'another-workflow',
        'child' => $frame['event']['parentRunId'] = $operation->state['swamp_run_id'],
        'active' => $frame['event']['run']['status'] = 'running',
        'sequence' => $frame['event']['seq'] = 0,
        'unattached' => null,
    };
    if ($change !== 'unattached') {
        $socket->push(nativeSubscriberAttached($socket->sent[0]));
        $subscriber->pass();
    }
    $socket->push($frame);

    $subscriber->pass();

    Queue::assertNothingPushed();
    expect($operation->fresh()->status)->toBe(OperationStatus::Running);
})->with(['request', 'run', 'workflow', 'child', 'active', 'sequence', 'unattached']);

it('recovers only an explicit late attach miss through the same queued reader without a status', function (bool $nested): void {
    Queue::fake([ProjectSwampTerminal::class]);
    $operation = nativeSubscriberOperation();
    $socket = new FakeSwampEventSocket;
    $subscriber = new SwampRunSubscriber($socket, new NullLogger);
    $subscriber->pass();
    $error = ['type' => 'error', 'id' => $socket->sent[0]['id']];
    $error += $nested ? ['error' => ['code' => 'not_found']] : ['code' => 'not_found'];
    $socket->push($error);

    $subscriber->pass();

    Queue::assertPushed(ProjectSwampTerminal::class, fn ($job): bool => $job->operationId === $operation->id && $job->status === null);
    expect(array_column($socket->sent, 'type'))->toBe(['run.attach']);
})->with([false, true]);

it('leaves refusal and unavailable run locations unchanged without a history request', function (string $type): void {
    Queue::fake([ProjectSwampTerminal::class]);
    $operation = nativeSubscriberOperation();
    $socket = new FakeSwampEventSocket;
    $subscriber = new SwampRunSubscriber($socket, new NullLogger);
    $subscriber->pass();
    $socket->push(['type' => $type, 'id' => $socket->sent[0]['id'], 'code' => 'forbidden', 'message' => 'private-diagnostic',
        'payload' => ['runId' => $operation->state['swamp_run_id']]]);

    $subscriber->pass();
    $subscriber->pass();

    Queue::assertNothingPushed();
    expect($operation->fresh()->status)->toBe(OperationStatus::Running);
    expect(array_column($socket->sent, 'type'))->toBe(['run.attach']);
})->with(['error', 'run.elsewhere', 'run.interrupted']);

it('suppresses duplicate terminal frames and delayed binding notices while projection is queued', function (): void {
    Queue::fake([ProjectSwampTerminal::class]);
    $operation = nativeSubscriberOperation();
    $socket = new FakeSwampEventSocket;
    $subscriber = new SwampRunSubscriber($socket, new NullLogger);
    $subscriber->pass();
    $terminal = nativeSubscriberFrame($socket->sent[0], $operation);
    $socket->push(nativeSubscriberAttached($socket->sent[0]), $terminal, $terminal);
    $subscriber->pass();

    $subscriber->follow($operation->id);
    $subscriber->recover();
    $subscriber->pass();

    Queue::assertPushed(ProjectSwampTerminal::class, 1);
    expect(array_column($socket->sent, 'type'))->toBe(['run.attach']);
});

it('reattaches remembered bindings after a disconnected stream without submitting native work', function (): void {
    Queue::fake([ProjectSwampTerminal::class]);
    $operation = nativeSubscriberOperation();
    $clock = (object) ['now' => 100.0];
    $socket = new FakeSwampEventSocket;
    $subscriber = new SwampRunSubscriber($socket, new NullLogger, static fn (): float => $clock->now);
    $subscriber->pass();
    $socket->close();
    $subscriber->close();

    $subscriber->pass();
    $request = $socket->sent[1];
    $socket->push(['type' => 'error', 'id' => $request['id'], 'code' => 'not_found']);
    $subscriber->pass();

    expect(array_column($socket->sent, 'type'))->toBe(['run.attach', 'run.attach']);
    expect($request['payload'])->toBe(['runId' => $operation->state['swamp_run_id'], 'afterSeq' => 0]);
    Queue::assertPushed(ProjectSwampTerminal::class, 1);
});

it('does not attach an undeclared unbound or terminal Operation', function (string $change): void {
    Queue::fake([ProjectSwampTerminal::class]);
    $operation = nativeSubscriberOperation();
    $operation->update(match ($change) {
        'unbound' => ['state' => []],
        'undeclared' => ['kind' => 'route.private_dns_publish'],
        'terminal' => ['status' => OperationStatus::Succeeded],
    });
    $socket = new FakeSwampEventSocket;
    $subscriber = new SwampRunSubscriber($socket, new NullLogger);

    $subscriber->pass();
    $subscriber->follow($operation->id);
    $subscriber->pass();

    expect($socket->sent)->toBeEmpty();
    expect($socket->endpoints)->toBeEmpty();
    Queue::assertNothingPushed();
})->with(['unbound', 'undeclared', 'terminal']);

it('isolates a failed native connection from the existing observer without changing an Operation', function (): void {
    Queue::fake([ProjectSwampTerminal::class]);
    $operation = nativeSubscriberOperation();
    $clock = (object) ['now' => 100.0];
    $socket = new FakeSwampEventSocket;
    $socket->onConnect = static function (): void {
        throw new WebSocketException('private-token-and-input');
    };
    $subscriber = new SwampRunSubscriber($socket, new NullLogger, static fn (): float => $clock->now);

    $subscriber->pass();
    $subscriber->pass();

    expect($operation->fresh()->status)->toBe(OperationStatus::Running);
    expect($operation->fresh()->error_code)->toBeNull();
    expect($socket->sent)->toBeEmpty();
    Queue::assertNothingPushed();
});

it('refuses a stale queued binding before any history read or family projection', function (): void {
    $operation = nativeSubscriberOperation();
    $job = new ProjectSwampTerminal($operation->id, $operation->state['swamp_run_id'], 'succeeded');
    $newRun = '25b90c36-1497-45be-ab30-3f1fe650e8f2';
    $operation->update(['state' => ['swamp_run_id' => $newRun]]);
    $history = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $history);

    expect(fn () => $job->handle(app(SwampCommandStep::class)))->toThrow(ResourceOperationException::class);

    expect($history->sent)->toBeEmpty();
    expect($history->connections)->toBeEmpty();
    expect($operation->fresh()->state['swamp_run_id'])->toBe($newRun);
    expect($operation->fresh()->status)->toBe(OperationStatus::Running);
    expect($operation->fresh()->result)->toBeNull();
});

it('ignores the old subscription after a binding notice replaces its run', function (): void {
    Queue::fake([ProjectSwampTerminal::class]);
    $operation = nativeSubscriberOperation();
    $socket = new FakeSwampEventSocket;
    $subscriber = new SwampRunSubscriber($socket, new NullLogger);
    $subscriber->pass();
    $oldRequest = $socket->sent[0];
    $oldTerminal = nativeSubscriberFrame($oldRequest, $operation);
    $operation->update(['state' => ['swamp_run_id' => '25b90c36-1497-45be-ab30-3f1fe650e8f2']]);
    $subscriber->follow($operation->id);
    $subscriber->pass();
    $newRequest = $socket->sent[1];
    $socket->push(nativeSubscriberAttached($oldRequest), $oldTerminal, nativeSubscriberAttached($newRequest), nativeSubscriberFrame($newRequest, $operation));

    $subscriber->pass();

    Queue::assertPushed(ProjectSwampTerminal::class, 1);
    Queue::assertPushed(ProjectSwampTerminal::class, fn ($job): bool => $job->runId === $operation->state['swamp_run_id']);
    expect(array_column($socket->sent, 'type'))->toBe(['run.attach', 'run.attach']);
});

it('preserves a terminal Operation when an older queued event arrives', function (): void {
    $operation = nativeSubscriberOperation();
    $operation->update(['status' => OperationStatus::Cancelled, 'result' => ['prior' => true], 'finished_at' => '2026-10-31T14:00:01Z']);
    $job = new ProjectSwampTerminal($operation->id, $operation->state['swamp_run_id'], 'succeeded');
    $history = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $history);

    $job->handle(app(SwampCommandStep::class));

    expect($history->sent)->toBeEmpty();
    expect($operation->fresh()->status)->toBe(OperationStatus::Cancelled);
    expect($operation->fresh()->result)->toBe(['prior' => true]);
});

it('retains the binding after failed queue delivery and recovers by reattaching without rerunning work', function (): void {
    $operation = nativeSubscriberOperation();
    $clock = (object) ['now' => 100.0];
    $socket = new FakeSwampEventSocket;
    $subscriber = new SwampRunSubscriber($socket, new NullLogger, static fn (): float => $clock->now);
    $subscriber->pass();
    $request = $socket->sent[0];
    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('synthetic-queue-delivery-failed'));
    $socket->push(nativeSubscriberAttached($request), nativeSubscriberFrame($request, $operation));

    $subscriber->pass();

    expect($operation->fresh()->status)->toBe(OperationStatus::Running);
    expect($operation->fresh()->state['swamp_run_id'])->toBe($request['payload']['runId']);
    expect($socket->isConnected())->toBeFalse();
    Bus::fake([ProjectSwampTerminal::class]);
    $clock->now = 101.0;
    $subscriber->pass();
    $socket->push(['type' => 'error', 'id' => $socket->sent[1]['id'], 'code' => 'not_found']);
    $subscriber->pass();

    Bus::assertDispatched(ProjectSwampTerminal::class, fn ($job): bool => $job->operationId === $operation->id && $job->status === null);
    expect(array_column($socket->sent, 'type'))->toBe(['run.attach', 'run.attach']);
});
