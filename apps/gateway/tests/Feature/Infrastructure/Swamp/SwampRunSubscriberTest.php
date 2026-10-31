<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationContribution;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\AgentView\WebSocketException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampRunSubscriber;
use App\Jobs\Operations\ProjectSwampTerminal;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Psr\Log\NullLogger;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\FakeSwampEventSocket;
use Tests\Support\ScriptedWebSocketServer;

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
    config(['swamp.run_token' => 'private-native-token', 'swamp.read_token' => 'private-native-read-token', 'swamp.address' => '192.0.2.209', 'swamp.ca_path' => '/public/ca.pem']);
});

it('delivers a large native terminal frame over verified TLS then projects one large history response', function (string $status): void {
    $operation = nativeSubscriberOperation();
    $directory = sys_get_temp_dir().'/orbit-native-terminal-'.bin2hex(random_bytes(8));
    mkdir($directory);
    $fixture = $directory.'/public-run.json';
    $run = [
        'id' => $operation->state['swamp_run_id'], 'workflowName' => 'operation-test-native-events', 'status' => $status,
        'inputs' => ['operation_id' => $operation->id, 'kind' => $operation->kind, 'node_id' => $operation->node_id,
            'intent' => ['public_snapshot' => str_repeat('x', 1_100_000)]],
        'startedAt' => '2026-10-31T14:00:00Z', 'completedAt' => '2026-10-31T14:00:01Z',
        'jobs' => [['name' => 'completion', 'steps' => [['name' => 'result', 'outputs' => [
            'stdout' => json_encode(['ready' => true]), 'stderr' => str_repeat('y', 1_100_000),
        ]]]]],
    ];
    file_put_contents($fixture, json_encode($run, JSON_THROW_ON_ERROR));
    $server = ScriptedWebSocketServer::start('native-terminal', [$fixture]);
    // Redirect only the test endpoint. Production client bounds, TLS verification and protocol stay real.
    $socket = new class(app(WebSocketClient::class), $server->endpoint()) implements WebSocketClient
    {
        public array $sent = [];

        public function __construct(private readonly WebSocketClient $inner, private readonly WebSocketEndpoint $endpoint) {}

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
        {
            $this->inner->connect($this->endpoint, $timeoutSeconds);
        }

        public function send(array $message): void
        {
            $this->sent[] = $message;
            $this->inner->send($message);
        }

        public function receive(float $timeoutSeconds): array
        {
            return $this->inner->receive($timeoutSeconds);
        }

        public function close(): void
        {
            $this->inner->close();
        }

        public function isConnected(): bool
        {
            return $this->inner->isConnected();
        }
    };
    $history = new ($socket::class)(app(WebSocketClient::class), $server->endpoint());
    app()->instance(WebSocketClient::class, $history);
    $subscriber = new SwampRunSubscriber($socket, new NullLogger);

    try {
        $deadline = microtime(true) + 1.5;
        do {
            $subscriber->pass();
            usleep(1_000);
        } while (! $operation->fresh()->status->terminal() && microtime(true) < $deadline);
        $subscriber->close();

        expect($operation->fresh()->status->value)->toBe($status);
        expect($operation->fresh()->result)->toBe(['ready' => true]);
        expect(array_column($socket->sent, 'type'))->toBe(['run.attach']);
        expect(array_column($history->sent, 'type'))->toBe(['workflow.history.get']);
        expect($server->waitFor('native-request='))->toBe('native-request=run.attach');
        expect($server->waitFor('native-request='))->toBe('native-request=workflow.history.get');
    } finally {
        $subscriber->close();
        $server->stop();
        unlink($fixture);
        rmdir($directory);
    }
})->with(['succeeded', 'failed', 'cancelled', 'interrupted']);

it('delivers each terminal frame synchronously for multiple bound streams', function (string $status): void {
    Bus::fake([ProjectSwampTerminal::class]);
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

    Bus::assertDispatchedSync(ProjectSwampTerminal::class, 2);
    Bus::assertDispatchedSync(ProjectSwampTerminal::class, fn ($job): bool => $job->operationId === $first->id
        && $job->runId === $first->state['swamp_run_id'] && $job->status === $status
    );
    expect(array_column($socket->sent, 'type'))->toBe(['run.attach', 'run.attach']);
    expect($first->fresh()->status)->toBe(OperationStatus::Running);
})->with(['succeeded', 'failed', 'cancelled', 'interrupted']);

it('projects a terminal notice with no Horizon consumer through the one shared history reader and fixed selector', function (string $status): void {
    config(['queue.default' => 'gateway']);
    $operation = nativeSubscriberOperation();
    $native = new FakeSwampEventSocket;
    $subscriber = new SwampRunSubscriber($native, new NullLogger);
    $subscriber->pass();
    $native->push(nativeSubscriberAttached($native->sent[0]), nativeSubscriberFrame($native->sent[0], $operation, $status));
    $history = new FakeRoutePublicationSocket;
    $history->reply = static fn (array $request): array => [['type' => 'workflow.history.get', 'id' => $request['id'], 'payload' => ['data' => [
        'id' => $operation->state['swamp_run_id'], 'workflowName' => 'operation-test-native-events', 'status' => $status,
        'inputs' => ['operation_id' => $operation->id, 'kind' => $operation->kind, 'node_id' => $operation->node_id],
        'startedAt' => '2026-10-31T14:00:00Z', 'completedAt' => '2026-10-31T14:00:01Z',
        'jobs' => [['name' => 'completion', 'steps' => [['name' => 'result', 'outputs' => ['stdout' => '{"ready":true}']]]]],
    ]]]];
    app()->instance(WebSocketClient::class, $history);

    $subscriber->pass();

    expect($operation->fresh()->status->value)->toBe($status);
    expect($operation->fresh()->result)->toBe(['ready' => true]);
    expect(array_column($history->sent, 'type'))->toBe(['workflow.history.get']);
    expect(DB::table('jobs')->count())->toBe(0);
})->with(['succeeded', 'failed', 'cancelled', 'interrupted']);

it('ignores unrelated child malformed and unattached terminal frames', function (string $change): void {
    Bus::fake([ProjectSwampTerminal::class]);
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

    Bus::assertNothingDispatched();
    expect($operation->fresh()->status)->toBe(OperationStatus::Running);
})->with(['request', 'run', 'workflow', 'child', 'active', 'sequence', 'unattached']);

it('recovers only an explicit late attach miss through the same synchronous reader without a status', function (bool $nested): void {
    Bus::fake([ProjectSwampTerminal::class]);
    $operation = nativeSubscriberOperation();
    $socket = new FakeSwampEventSocket;
    $subscriber = new SwampRunSubscriber($socket, new NullLogger);
    $subscriber->pass();
    $error = ['type' => 'error', 'id' => $socket->sent[0]['id']];
    $error += $nested ? ['error' => ['code' => 'not_found']] : ['code' => 'not_found'];
    $socket->push($error);

    $subscriber->pass();

    Bus::assertDispatchedSync(ProjectSwampTerminal::class, fn ($job): bool => $job->operationId === $operation->id && $job->status === null);
    expect(array_column($socket->sent, 'type'))->toBe(['run.attach']);
})->with([false, true]);

it('leaves refusal and unavailable run locations unchanged without a history request', function (string $type): void {
    Bus::fake([ProjectSwampTerminal::class]);
    $operation = nativeSubscriberOperation();
    $socket = new FakeSwampEventSocket;
    $subscriber = new SwampRunSubscriber($socket, new NullLogger);
    $subscriber->pass();
    $socket->push(['type' => $type, 'id' => $socket->sent[0]['id'], 'code' => 'forbidden', 'message' => 'private-diagnostic',
        'payload' => ['runId' => $operation->state['swamp_run_id']]]);

    $subscriber->pass();
    $subscriber->pass();

    Bus::assertNothingDispatched();
    expect($operation->fresh()->status)->toBe(OperationStatus::Running);
    expect(array_column($socket->sent, 'type'))->toBe(['run.attach']);
})->with(['error', 'run.elsewhere']);

it('reads interrupted history again only on another native notice without replaying the root', function (): void {
    $operation = nativeSubscriberOperation();
    $clock = (object) ['now' => 100.0];
    $socket = new FakeSwampEventSocket;
    $subscriber = new SwampRunSubscriber($socket, new NullLogger, static fn (): float => $clock->now);
    $history = new FakeRoutePublicationSocket;
    $reads = 0;
    $history->reply = static function (array $request) use ($operation, &$reads): array {
        $reads++;

        return [['type' => 'workflow.history.get', 'id' => $request['id'], 'payload' => ['data' => [
            'id' => $operation->state['swamp_run_id'], 'workflowName' => 'operation-test-native-events',
            'status' => $reads === 1 ? 'running' : 'interrupted',
            'inputs' => ['operation_id' => $operation->id, 'kind' => $operation->kind, 'node_id' => $operation->node_id],
            'startedAt' => '2026-10-31T14:00:00Z', 'completedAt' => '2026-10-31T14:00:01Z',
            'jobs' => [['name' => 'completion', 'steps' => [['name' => 'result', 'status' => 'pending']]]],
        ]]]];
    };
    app()->instance(WebSocketClient::class, $history);
    $subscriber->pass();
    $request = $socket->sent[0];
    $socket->push(['type' => 'run.interrupted', 'id' => $request['id'], 'payload' => [
        'runId' => $operation->state['swamp_run_id'], 'instanceId' => 'dead-owner', 'reason' => 'instance_dead',
    ]]);

    $subscriber->pass();

    expect($operation->fresh()->status)->toBe(OperationStatus::Running);
    expect($operation->fresh()->result)->toBeNull();
    $subscriber->recover();
    $clock->now = 104.9;
    $subscriber->pass();
    expect($history->sent)->toHaveCount(1);
    $clock->now = 105.0;
    $subscriber->pass();

    expect($history->sent)->toHaveCount(1);
    $socket->push(['type' => 'run.interrupted', 'id' => $request['id'], 'payload' => ['runId' => $operation->state['swamp_run_id']]]);
    $subscriber->pass();

    expect($operation->fresh()->status)->toBe(OperationStatus::Interrupted);
    expect($operation->fresh()->error_code)->toBe('operation.native_failed');
    expect($operation->fresh()->result)->toBe(['error_code' => 'operation.native_failed']);
    expect($operation->fresh()->state['swamp_run_id'])->toBe($request['payload']['runId']);
    expect(array_column($history->sent, 'type'))->toBe(['workflow.history.get', 'workflow.history.get']);
    expect(array_column($socket->sent, 'type'))->toBe(['run.attach']);
    expect($socket->endpoints)->toHaveCount(1);
    expect($socket->isConnected())->toBeTrue();
    $subscriber->pass();
    $subscriber->recover();
    $subscriber->pass();
    expect($history->sent)->toHaveCount(2);
});

it('ignores an interrupted reply that does not name the bound root', function (mixed $payload): void {
    Bus::fake([ProjectSwampTerminal::class]);
    $operation = nativeSubscriberOperation();
    $socket = new FakeSwampEventSocket;
    $subscriber = new SwampRunSubscriber($socket, new NullLogger);
    $subscriber->pass();
    $request = $socket->sent[0];
    $socket->push(['type' => 'run.interrupted', 'id' => $request['id'], 'payload' => $payload]);

    $subscriber->pass();

    Bus::assertNothingDispatched();
    expect($operation->fresh()->status)->toBe(OperationStatus::Running);
    $socket->push(nativeSubscriberAttached($request), nativeSubscriberFrame($request, $operation));
    $subscriber->pass();
    Bus::assertDispatchedSync(ProjectSwampTerminal::class, 1);
})->with([
    'missing run' => [[]],
    'another run' => [['runId' => '25b90c36-1497-45be-ab30-3f1fe650e8f2']],
    'malformed payload' => ['not-an-object'],
]);

it('suppresses duplicate terminal frames and delayed binding notices after synchronous delivery', function (): void {
    $operation = nativeSubscriberOperation();
    $history = new FakeRoutePublicationSocket;
    $history->reply = static fn (array $request): array => [['type' => 'workflow.history.get', 'id' => $request['id'], 'payload' => ['data' => [
        'id' => $operation->state['swamp_run_id'], 'workflowName' => 'operation-test-native-events', 'status' => 'succeeded',
        'inputs' => ['operation_id' => $operation->id, 'kind' => $operation->kind, 'node_id' => $operation->node_id],
        'startedAt' => '2026-10-31T14:00:00Z', 'completedAt' => '2026-10-31T14:00:01Z',
        'jobs' => [['name' => 'completion', 'steps' => [['name' => 'result', 'outputs' => ['stdout' => '{"ready":true}']]]]],
    ]]]];
    app()->instance(WebSocketClient::class, $history);
    $socket = new FakeSwampEventSocket;
    $subscriber = new SwampRunSubscriber($socket, new NullLogger);
    $subscriber->pass();
    $terminal = nativeSubscriberFrame($socket->sent[0], $operation);
    $socket->push(nativeSubscriberAttached($socket->sent[0]), $terminal, $terminal);
    $subscriber->pass();

    $subscriber->follow($operation->id);
    $subscriber->recover();
    $subscriber->pass();

    expect($operation->fresh()->status)->toBe(OperationStatus::Succeeded);
    expect(array_column($history->sent, 'type'))->toBe(['workflow.history.get']);
    expect(array_column($socket->sent, 'type'))->toBe(['run.attach']);
});

it('reattaches remembered bindings after a disconnected stream without submitting native work', function (): void {
    Bus::fake([ProjectSwampTerminal::class]);
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
    Bus::assertDispatchedSync(ProjectSwampTerminal::class, 1);
});

it('does not attach an undeclared unbound or terminal Operation', function (string $change): void {
    Bus::fake([ProjectSwampTerminal::class]);
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
    Bus::assertNothingDispatched();
})->with(['unbound', 'undeclared', 'terminal']);

it('isolates a failed native connection from the existing observer without changing an Operation', function (): void {
    Bus::fake([ProjectSwampTerminal::class]);
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
    Bus::assertNothingDispatched();
});

it('refuses a stale binding before any history read or family projection', function (): void {
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
    Bus::fake([ProjectSwampTerminal::class]);
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

    Bus::assertDispatchedSync(ProjectSwampTerminal::class, 1);
    Bus::assertDispatchedSync(ProjectSwampTerminal::class, fn ($job): bool => $job->runId === $operation->state['swamp_run_id']);
    expect(array_column($socket->sent, 'type'))->toBe(['run.attach', 'run.attach']);
});

it('preserves a terminal Operation when an older event arrives', function (): void {
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

it('retains a failed terminal delivery without reconnecting or blocking another run', function (): void {
    $operation = nativeSubscriberOperation();
    $other = nativeSubscriberOperation('b075eb23-7ac4-4487-80d1-57a038a92676');
    $clock = (object) ['now' => 100.0];
    $socket = new FakeSwampEventSocket;
    $subscriber = new SwampRunSubscriber($socket, new NullLogger, static fn (): float => $clock->now);
    $subscriber->pass();
    [$request, $otherRequest] = $socket->sent;
    $attempts = [];
    Bus::shouldReceive('dispatchSync')->andReturnUsing(function ($job) use (&$attempts, $operation): void {
        $attempts[] = $job->operationId;
        if ($job->operationId === $operation->id) {
            throw new RuntimeException('synthetic-projection-failed');
        }
    });
    $socket->push(nativeSubscriberAttached($request), nativeSubscriberFrame($request, $operation),
        nativeSubscriberAttached($otherRequest), nativeSubscriberFrame($otherRequest, $other));

    $subscriber->pass();
    for ($i = 0; $i < 50; $i++) {
        $clock->now = 104.9;
        $subscriber->pass();
    }

    expect($socket->isConnected())->toBeTrue();
    expect($socket->endpoints)->toHaveCount(1);
    expect($socket->sent)->toHaveCount(2);
    expect($attempts)->toBe([$operation->id, $other->id]);
    expect($operation->fresh()->status)->toBe(OperationStatus::Running);
    expect($operation->fresh()->state['swamp_run_id'])->toBe($request['payload']['runId']);
    $clock->now = 105.0;
    $subscriber->pass();
    $clock->now = 114.9;
    $subscriber->pass();
    expect($attempts)->toBe([$operation->id, $other->id]);

    Bus::fake([ProjectSwampTerminal::class]);
    $clock->now = 115.0;
    $subscriber->pass();
    Bus::assertNothingDispatched();
    $socket->push(nativeSubscriberFrame($request, $operation));
    $subscriber->pass();
    Bus::assertDispatchedSync(ProjectSwampTerminal::class, fn ($job): bool => $job->operationId === $operation->id && $job->status === 'succeeded');
    $subscriber->close();
    $subscriber->pass();
    expect($socket->sent)->toHaveCount(2);
});

it('backs off repeated unavailable transport handshakes', function (): void {
    nativeSubscriberOperation();
    $clock = (object) ['now' => 100.0];
    $socket = new FakeSwampEventSocket;
    $attempts = 0;
    $socket->onConnect = function () use (&$attempts): void {
        $attempts++;
        throw new WebSocketException('unavailable');
    };
    $subscriber = new SwampRunSubscriber($socket, new NullLogger, static fn (): float => $clock->now);
    $subscriber->pass();
    $clock->now = 101.0;
    $subscriber->pass();
    $clock->now = 102.0;
    $subscriber->pass();
    expect($attempts)->toBe(2);
    $clock->now = 103.0;
    $subscriber->pass();
    expect($attempts)->toBe(3);
});

it('requires a new native notice after a failed delivery and transport reconnect', function (): void {
    $operation = nativeSubscriberOperation();
    $other = nativeSubscriberOperation('b075eb23-7ac4-4487-80d1-57a038a92676');
    $clock = (object) ['now' => 100.0];
    $socket = new FakeSwampEventSocket;
    $subscriber = new SwampRunSubscriber($socket, new NullLogger, static fn (): float => $clock->now);
    $subscriber->pass();
    $request = $socket->sent[0];
    Bus::shouldReceive('dispatchSync')->once()->andThrow(new RuntimeException('unavailable'));
    $socket->push(nativeSubscriberAttached($request), nativeSubscriberFrame($request, $operation));
    $subscriber->pass();
    $subscriber->close();
    $subscriber->pass();
    expect(array_column(array_column($socket->sent, 'payload'), 'runId'))->toBe([
        $operation->state['swamp_run_id'], $other->state['swamp_run_id'], $operation->state['swamp_run_id'], $other->state['swamp_run_id'],
    ]);
    Bus::fake([ProjectSwampTerminal::class]);
    $clock->now = 105.0;
    $subscriber->pass();
    Bus::assertNothingDispatched();
    $newRequest = $socket->sent[2];
    $socket->push(nativeSubscriberAttached($newRequest), nativeSubscriberFrame($newRequest, $operation));
    $subscriber->pass();
    Bus::assertDispatchedSync(ProjectSwampTerminal::class, fn ($job): bool => $job->operationId === $operation->id && $job->status === 'succeeded');
});

it('does not reset reconnect backoff for a handshake followed by immediate disconnect', function (): void {
    nativeSubscriberOperation();
    $clock = (object) ['now' => 100.0];
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldReceive('isConnected')->andReturnFalse();
    $socket->shouldReceive('connect')->times(3);
    $socket->shouldReceive('send')->times(3);
    $socket->shouldReceive('receive')->times(3)->andReturn([]);
    $socket->shouldReceive('close')->times(3);
    $subscriber = new SwampRunSubscriber($socket, new NullLogger, static fn (): float => $clock->now);
    $subscriber->pass();
    $clock->now = 101.0;
    $subscriber->pass();
    $clock->now = 102.0;
    $subscriber->pass();
    $clock->now = 103.0;
    $subscriber->pass();
});

it('retains run authority for active bound attachments when the separate read credential cannot attach', function (): void {
    Bus::fake([ProjectSwampTerminal::class]);
    $operation = nativeSubscriberOperation();
    $socket = new FakeSwampEventSocket;
    $socket->onSend = static function (array $request) use ($socket, $operation): void {
        if ($request['type'] !== 'run.attach') {
            throw new RuntimeException('Bound attachment socket must not perform read discovery.');
        }
        if (array_last($socket->endpoints)->bearerToken !== 'private-native-token') {
            $socket->push(['type' => 'error', 'id' => $request['id'], 'error' => ['code' => 'forbidden']]);

            return;
        }
        $socket->push(nativeSubscriberAttached($request), nativeSubscriberFrame($request, $operation));
    };
    (new SwampRunSubscriber($socket, new NullLogger))->pass();
    Bus::assertDispatchedSync(ProjectSwampTerminal::class, fn ($job): bool => $job->operationId === $operation->id);
});
