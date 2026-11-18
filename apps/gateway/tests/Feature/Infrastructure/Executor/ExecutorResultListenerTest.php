<?php

declare(strict_types=1);

use App\Actions\Broadcasting\PresenceChannelSigner;
use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Instances\Power\ExecutorWake;
use App\Domain\Operations\OperationStatus;
use App\Domain\Problems\ProblemCollector;
use App\Domain\Problems\ProblemSource;
use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Domain\WebSocket\WebSocketCredentials;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Infrastructure\AgentView\AgentViewSubscriber;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Executor\ExecutorResultQueue;
use App\Infrastructure\Executor\ExecutorResults;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Operation;
use App\Models\ProblemCollectorState;
use App\Models\ProblemFingerprint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Psr\Log\AbstractLogger;

/*
 * ADR 0266 slice 3: the agent view subscriber takes `client-executor-result` from a fake Reverb stream. The Valkey
 * push goes to an in-memory queue, so these run without a server. ExecutorResultWaitTest covers the real BLPOP.
 */

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    $this->node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'app-dev-1', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.8',
        'public_ssh_host' => '10.44.0.8', 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:test',
    ]));
    $this->operation = Operation::query()->create(['kind' => 'instance.wake', 'target_type' => 'instance', 'target_id' => 7,
        'node_id' => $this->node->id, 'status' => OperationStatus::Running, 'input' => [], 'state' => [ExecutorWake::State => true]]);
    $this->log = new class extends AbstractLogger
    {
        /** @var list<array{string, string, array<string, mixed>}> */
        public array $lines = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->lines[] = [(string) $level, (string) $message, $context];
        }
    };
    $this->queue = new class implements ExecutorResultQueue
    {
        /** @var list<array{key: string, line: string, expiry: int, recorded: mixed}> */
        public array $pushes = [];

        public bool $fails = false;

        public function push(string $key, string $line, int $expirySeconds): void
        {
            if ($this->fails) {
                throw new RuntimeException('Valkey is down.');
            }

            // What the Operation held at the moment of the push: the record must come first.
            $operation = (int) explode(':', $key)[2];
            $this->pushes[] = ['key' => $key, 'line' => $line, 'expiry' => $expirySeconds,
                'recorded' => Operation::query()->find($operation)?->executor_result];
        }

        public function pop(array $keys, float $timeoutSeconds): ?array
        {
            return null;
        }
    };
    $this->mock(WebSocketCredentialManager::class)->shouldReceive('current')
        ->andReturn(new WebSocketCredentials('test-app', 'test-key', 'test-secret', '10.44.0.2'));
});

/**
 * Drives one subscriber pass that connects, joins the Node's channel, and receives `$events` as client events.
 *
 * @param  list<array<string, mixed>>  $events
 */
function executorStream(object $test, array $events): void
{
    $nodeId = $test->node->id;
    $socket = new class([[['event' => 'pusher:connection_established', 'data' => json_encode(['socket_id' => '1.2'])]], [['event' => 'pusher_internal:subscription_succeeded', 'channel' => "presence-node.{$nodeId}", 'data' => json_encode(['presence' => ['ids' => ["agent.{$nodeId}"]]])], ...$events]]) implements WebSocketClient
    {
        private bool $connected = false;

        /** @param list<list<array<string, mixed>>> $batches */
        public function __construct(private array $batches) {}

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
        {
            $this->connected = true;
        }

        public function send(array $message): void {}

        public function receive(float $timeoutSeconds): array
        {
            return array_shift($this->batches) ?? [];
        }

        public function close(): void
        {
            $this->connected = false;
        }

        public function isConnected(): bool
        {
            return $this->connected;
        }
    };

    $subscriber = new AgentViewSubscriber(
        socket: $socket,
        credentials: app(WebSocketCredentialManager::class),
        view: new CacheAgentStateView(Cache::store('array')),
        signer: app(PresenceChannelSigner::class),
        log: $test->log,
        caPath: __FILE__,
        commit: static fn (): ?string => null,
        clock: static fn (): float => microtime(true),
        sleep: static function (float $seconds): void {},
        executor: new ExecutorResults($test->queue, $test->log, app(CommandActivityInputSanitizer::class)),
    );
    $subscriber->pass();
}

/** @param array<string, mixed> $line */
function executorEvent(int $nodeId, int|string $operation, array|string $line, ?string $member = null): array
{
    return ['event' => 'client-executor-result', 'channel' => "presence-node.{$nodeId}", 'user_id' => $member ?? "agent.{$nodeId}",
        'data' => json_encode(['operation' => (string) $operation, 'line' => is_string($line) ? $line : json_encode($line)])];
}

it('records a result on the Operation first, then pushes it after the accepted line, with a 60 s expiry', function (): void {
    $id = $this->operation->id;
    $done = ['operation' => (string) $id, 'action' => 'wake', 'target' => '7', 'status' => 'done', 'repeat' => false, 'node_clock_ms' => 1];

    executorStream($this, [executorEvent($this->node->id, $id, ['status' => 'accepted']), executorEvent($this->node->id, $id, $done)]);

    expect($this->operation->refresh()->executor_result)->toBe($done)
        ->and($this->queue->pushes)->toBe([
            ['key' => ExecutorResults::resultKey($id), 'line' => '{"status":"accepted"}', 'expiry' => 60, 'recorded' => null],
            ['key' => ExecutorResults::resultKey($id), 'line' => json_encode($done), 'expiry' => 60, 'recorded' => $done],
        ]);
});

it('drops a result from another member, a malformed event, and an Operation of another Node', function (): void {
    $id = $this->operation->id;
    $otherNode = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'app-dev-2', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.9',
        'public_ssh_host' => '10.44.0.9', 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:other',
    ]));
    $other = Operation::query()->create(['kind' => 'instance.wake', 'target_type' => 'instance', 'target_id' => 8,
        'node_id' => $otherNode->id, 'status' => OperationStatus::Running, 'input' => []]);
    $channel = "presence-node.{$this->node->id}";
    $agent = "agent.{$this->node->id}";

    executorStream($this, [
        executorEvent($this->node->id, $id, ['status' => 'done'], member: 'viewer.3'),
        executorEvent($this->node->id, $id, ['status' => 'done'], member: 'agent.'.($this->node->id + 1)),
        ['event' => 'client-executor-result', 'channel' => $channel, 'data' => json_encode(['operation' => (string) $id, 'line' => '{"status":"done"}'])],
        executorEvent($this->node->id, $id, '{"status":'),
        executorEvent($this->node->id, $id, ['status' => 'exploded']),
        executorEvent($this->node->id, $id, ['status' => 'done', 'operation' => '999']),
        executorEvent($this->node->id, 'op-1', ['status' => 'done']),
        // The contract is exact: a numeric string, and no other key.
        ['event' => 'client-executor-result', 'channel' => $channel, 'user_id' => $agent, 'data' => json_encode(['operation' => $id, 'line' => '{"status":"done"}'])],
        ['event' => 'client-executor-result', 'channel' => $channel, 'user_id' => $agent, 'data' => json_encode(['operation' => (string) $id, 'line' => '{"status":"done"}', 'order' => 'x'])],
        executorEvent($this->node->id, $other->id, ['status' => 'accepted']),
        executorEvent($this->node->id, $id + 1000, ['status' => 'accepted']),
        executorEvent($this->node->id, $other->id, ['status' => 'done', 'operation' => (string) $other->id]),
    ]);

    $messages = array_count_values(array_column($this->log->lines, 1));

    expect($this->operation->refresh()->executor_result)->toBeNull()
        ->and($other->refresh()->executor_result)->toBeNull()
        ->and($this->queue->pushes)->toBe([])
        ->and($messages['The agent view subscriber dropped an executor result from another member.'])->toBe(3)
        ->and($messages['The agent view subscriber dropped a malformed executor result.'])->toBe(6)
        ->and($messages['The agent view subscriber dropped an executor reply for an Operation that is not on its Node.'])->toBe(3);
});

it('logs a refusal and raises a Problem through the collector, with no broadcast from the loop', function (): void {
    Event::fake([RecordBroadcast::class]);
    ProblemCollectorState::query()->create(['activity_cursor' => 0]);
    $id = $this->operation->id;

    executorStream($this, [executorEvent($this->node->id, $id, ['status' => 'refused', 'reason' => 'bad_signature', 'node_clock_ms' => 42])]);

    $refusal = collect($this->log->lines)->firstWhere(1, 'The executor refused an order.');
    $activity = Activity::query()->where('description', 'executor.order_refused')->sole();

    expect($refusal[0])->toBe('warning')
        ->and($refusal[2])->toMatchArray(['node_id' => $this->node->id, 'operation_id' => $id, 'reason' => 'bad_signature', 'node_clock_ms' => 42])
        ->and($activity->error_code)->toBe(ExecutorResults::RefusalCode)
        ->and($activity->subject_id)->toBe($this->node->id)
        ->and($this->operation->refresh()->executor_result)->toMatchArray(['status' => 'refused'])
        ->and(array_column($this->queue->pushes, 'key'))->toBe([ExecutorResults::resultKey($id)]);
    Event::assertNotDispatched(RecordBroadcast::class);

    // The existing alert path: the Problem collector selects the row and records its fingerprint.
    $collector = app(ProblemCollector::class);
    $signals = $collector->signals();

    expect(array_column($signals['activities'], 'key'))->toBe(['orbit:agent-view|'.ExecutorResults::RefusalCode])
        ->and($signals['activities'][0]['observation']['error_message'])->toContain('bad_signature');

    $collector->recordSignals($signals);

    expect(ProblemFingerprint::query()->where('source', ProblemSource::Activity)->count())->toBe(1);
});

it('reports a refusal even when the push fails', function (): void {
    $this->queue->fails = true;

    executorStream($this, [executorEvent($this->node->id, $this->operation->id, ['status' => 'refused', 'reason' => 'expired', 'node_clock_ms' => 1])]);

    $messages = array_column($this->log->lines, 1);

    expect($messages)->toContain('The executor refused an order.')
        ->and($messages)->toContain('The agent view subscriber could not hand off an executor result.')
        ->and(Activity::query()->where('error_code', ExecutorResults::RefusalCode)->count())->toBe(1)
        ->and($this->operation->refresh()->executor_result)->toMatchArray(['status' => 'refused']);
});

it('records a result once and still pushes a repeat', function (): void {
    $id = $this->operation->id;
    $first = ['operation' => (string) $id, 'action' => 'wake', 'target' => '7', 'status' => 'done', 'repeat' => false];

    executorStream($this, [
        executorEvent($this->node->id, $id, $first),
        executorEvent($this->node->id, $id, [...$first, 'repeat' => true]),
    ]);

    expect($this->operation->refresh()->executor_result)->toBe($first)
        ->and(array_column($this->queue->pushes, 'line'))->toBe([json_encode($first), json_encode([...$first, 'repeat' => true])]);
});

it('logs a failed push and keeps the loop going', function (): void {
    $this->queue->fails = true;

    $line = ['status' => 'done', 'operation' => (string) $this->operation->id, 'action' => 'wake', 'target' => '7'];
    executorStream($this, [executorEvent($this->node->id, $this->operation->id, $line)]);

    expect(array_column($this->log->lines, 1))->toContain('The agent view subscriber could not hand off an executor result.')
        ->and($this->operation->refresh()->executor_result)->toBe($line);
});

it('keeps bare replay evidence ordered without letting it replace the first result', function (bool $replayFirst): void {
    $id = $this->operation->id;
    $done = ['operation' => (string) $id, 'action' => 'wake', 'target' => '7', 'status' => 'done', 'repeat' => false];
    $replay = ['operation' => (string) $id, 'status' => 'refused', 'reason' => 'replayed_nonce', 'first' => null];
    $lines = $replayFirst ? [$replay, $done] : [$done, $replay];

    executorStream($this, array_map(fn (array $line): array => executorEvent($this->node->id, $id, $line), $lines));

    expect($this->operation->refresh()->executor_result)->toBe($done)
        ->and(array_column($this->queue->pushes, 'line'))->toBe(array_map(json_encode(...), $lines));
})->with([true, false]);

it('records a matching nested first result but no mismatched replay result', function (array $mismatch): void {
    $id = $this->operation->id;
    $done = ['operation' => (string) $id, 'action' => 'wake', 'target' => '7', 'status' => 'done', 'repeat' => false];
    $replay = ['operation' => (string) $id, 'status' => 'refused', 'reason' => 'replayed_nonce', 'first' => [...$done, ...$mismatch]];

    executorStream($this, [executorEvent($this->node->id, $id, $replay)]);

    expect($this->operation->refresh()->executor_result)->toBe($mismatch === [] ? $done : null);
})->with(['matching' => [[]], 'other operation' => [['operation' => '123456']], 'other action' => [['action' => 'sleep']], 'other target' => [['target' => '8']]]);

it('does not replace the first genuine action failure with a later wrapped success', function (): void {
    $id = $this->operation->id;
    $fields = ['operation' => (string) $id, 'action' => 'wake', 'target' => '7', 'repeat' => false];
    $failed = [...$fields, 'status' => 'failed', 'code' => 'instance.web_unready'];
    $replay = ['operation' => (string) $id, 'status' => 'refused', 'reason' => 'replayed_nonce', 'first' => [...$fields, 'status' => 'done']];

    executorStream($this, [executorEvent($this->node->id, $id, $failed), executorEvent($this->node->id, $id, $replay)]);

    expect($this->operation->refresh()->executor_result)->toBe($failed)
        ->and(array_column($this->queue->pushes, 'line'))->toBe([json_encode($failed), json_encode($replay)]);
});
