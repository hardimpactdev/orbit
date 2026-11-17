<?php

declare(strict_types=1);

use App\Actions\Broadcasting\PresenceChannelSigner;
use App\Domain\Operations\OperationStatus;
use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Domain\WebSocket\WebSocketCredentials;
use App\Infrastructure\AgentView\AgentViewSubscriber;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Executor\ExecutorResults;
use App\Infrastructure\Executor\ExecutorResultWait;
use App\Infrastructure\Executor\ExecutorWaitStatus;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Cache;
use Psr\Log\AbstractLogger;

/*
 * ADR 0266 slice 3: the agent view subscriber takes `client-executor-result` from a fake Reverb stream and hands it
 * to a real Valkey server, which the test starts on a free port. Without a `valkey-server` binary the tests skip.
 * The server runs with `hz 100`, as the bound needs (docs/reference/executor.md#the-listener-and-the-hand-off).
 */

uses(LazilyRefreshDatabase::class);

beforeAll(function (): void {
    $binary = trim((string) shell_exec('command -v valkey-server 2>/dev/null'));

    if ($binary === '') {
        return;
    }

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) stream_socket_get_name($socket, false), strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
    fclose($socket);
    $GLOBALS['executorValkey'] = proc_open([$binary, '--port', (string) $port, '--bind', '127.0.0.1', '--save', '', '--appendonly', 'no', '--hz', '100'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    $GLOBALS['executorValkeyPort'] = $port;

    for ($i = 0; $i < 100; $i++) {
        $probe = @fsockopen('127.0.0.1', $port, $code, $error, 0.05);

        if ($probe !== false) {
            fclose($probe);

            return;
        }

        usleep(20_000);
    }
});

afterAll(function (): void {
    if (isset($GLOBALS['executorValkey'])) {
        proc_terminate($GLOBALS['executorValkey']);
        proc_close($GLOBALS['executorValkey']);
    }
});

beforeEach(function (): void {
    if (! isset($GLOBALS['executorValkeyPort'])) {
        $this->markTestSkipped('No valkey-server binary to run the hand-off against.');
    }

    config(['database.redis.gateway' => ['host' => '127.0.0.1', 'port' => $GLOBALS['executorValkeyPort'], 'database' => 0, 'options' => ['prefix' => 'orbit-gateway:']]]);
    app()->forgetInstance('redis');
    $this->redis = static fn (): Connection => app('redis')->connection('gateway');
    ($this->redis)()->command('flushdb');

    $this->node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'app-dev-1', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.8',
        'public_ssh_host' => '10.44.0.8', 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:test',
    ]));
    $this->operation = Operation::query()->create(['kind' => 'instance.wake', 'target_type' => 'instance', 'target_id' => 7,
        'node_id' => $this->node->id, 'status' => OperationStatus::Running, 'input' => []]);
    $this->log = new class extends AbstractLogger
    {
        /** @var list<array{string, string, array<string, mixed>}> */
        public array $lines = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->lines[] = [(string) $level, (string) $message, $context];
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
        executor: new ExecutorResults($test->redis, $test->log),
    );
    $subscriber->pass();
}

/** @param array<string, mixed> $line */
function executorEvent(int $nodeId, int|string $operation, array|string $line, ?string $member = null): array
{
    return ['event' => 'client-executor-result', 'channel' => "presence-node.{$nodeId}", 'user_id' => $member ?? "agent.{$nodeId}",
        'data' => json_encode(['operation' => (string) $operation, 'line' => is_string($line) ? $line : json_encode($line)])];
}

function executorWait(object $test): ExecutorResultWait
{
    return new ExecutorResultWait($test->redis, static fn (): float => microtime(true));
}

it('records a result on the Operation, then pushes it to Valkey with a 60 s expiry', function (): void {
    $id = $this->operation->id;
    $done = ['operation' => (string) $id, 'action' => 'proof.noop', 'target' => '7', 'status' => 'done', 'repeat' => false, 'node_clock_ms' => 1];

    executorStream($this, [executorEvent($this->node->id, $id, ['status' => 'accepted']), executorEvent($this->node->id, $id, $done)]);

    expect($this->operation->refresh()->executor_result)->toBe($done)
        ->and(($this->redis)()->command('ttl', [ExecutorResults::resultKey($id)]))->toBeGreaterThan(55)->toBeLessThanOrEqual(60)
        ->and(($this->redis)()->command('ttl', [ExecutorResults::acceptedKey($id)]))->toBeGreaterThan(55)->toBeLessThanOrEqual(60);

    $result = executorWait($this)->wait($id, 0.5);

    expect($result->status)->toBe(ExecutorWaitStatus::Done)
        ->and($result->acceptedMs)->not->toBeNull()
        ->and($result->line)->toBe($done)
        ->and($result->elapsedMs)->toBeLessThan(50.0);
});

it('drops a result from another member, a malformed line, and an Operation of another Node', function (): void {
    $id = $this->operation->id;
    $otherNode = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'app-dev-2', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.9',
        'public_ssh_host' => '10.44.0.9', 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:other',
    ]));
    $other = Operation::query()->create(['kind' => 'instance.wake', 'target_type' => 'instance', 'target_id' => 8,
        'node_id' => $otherNode->id, 'status' => OperationStatus::Running, 'input' => []]);

    executorStream($this, [
        executorEvent($this->node->id, $id, ['status' => 'done'], member: 'viewer.3'),
        executorEvent($this->node->id, $id, ['status' => 'done'], member: 'agent.'.($this->node->id + 1)),
        ['event' => 'client-executor-result', 'channel' => "presence-node.{$this->node->id}", 'data' => json_encode(['operation' => (string) $id, 'line' => '{"status":"done"}'])],
        executorEvent($this->node->id, $id, '{"status":'),
        executorEvent($this->node->id, $id, ['status' => 'exploded']),
        executorEvent($this->node->id, $id, ['status' => 'done', 'operation' => '999']),
        executorEvent($this->node->id, 'op-1', ['status' => 'done']),
        executorEvent($this->node->id, $other->id, ['status' => 'done', 'operation' => (string) $other->id]),
    ]);

    $messages = array_column($this->log->lines, 1);

    expect($this->operation->refresh()->executor_result)->toBeNull()
        ->and($other->refresh()->executor_result)->toBeNull()
        ->and(($this->redis)()->command('exists', [ExecutorResults::resultKey($id)]))->toBe(0)
        ->and(($this->redis)()->command('exists', [ExecutorResults::resultKey($other->id)]))->toBe(0)
        ->and(array_count_values($messages)['The agent view subscriber dropped an executor result from another member.'])->toBe(3)
        ->and(array_count_values($messages)['The agent view subscriber dropped a malformed executor result.'])->toBe(4)
        ->and($messages)->toContain('The agent view subscriber dropped an executor result for an Operation that is not on its Node.');
});

it('logs a refusal with its reason and leaves an Activity problem', function (): void {
    $id = $this->operation->id;

    executorStream($this, [executorEvent($this->node->id, $id, ['status' => 'refused', 'reason' => 'bad_signature', 'node_clock_ms' => 42])]);

    $refusal = collect($this->log->lines)->firstWhere(1, 'The executor refused an order.');
    $activity = Activity::query()->where('description', 'executor.order_refused')->sole();

    expect($refusal[0])->toBe('warning')
        ->and($refusal[2])->toMatchArray(['node_id' => $this->node->id, 'operation_id' => $id, 'reason' => 'bad_signature', 'node_clock_ms' => 42])
        ->and($activity->error_code)->toBe('executor.order_refused')
        ->and($activity->subject_id)->toBe($this->node->id)
        ->and($this->operation->refresh()->executor_result)->toMatchArray(['status' => 'refused']);

    $result = executorWait($this)->wait($id, 0.5);

    expect($result->status)->toBe(ExecutorWaitStatus::Refused)->and($result->reason())->toBe('bad_signature');
});

it('records a result once and still pushes a repeat', function (): void {
    $id = $this->operation->id;
    $first = ['operation' => (string) $id, 'status' => 'done', 'repeat' => false];

    executorStream($this, [
        executorEvent($this->node->id, $id, $first),
        executorEvent($this->node->id, $id, [...$first, 'repeat' => true]),
    ]);

    expect($this->operation->refresh()->executor_result)->toBe($first)
        ->and(($this->redis)()->command('llen', [ExecutorResults::resultKey($id)]))->toBe(2);
});

it('returns a result that arrives while it waits, within the bound', function (): void {
    $id = $this->operation->id;
    // Another process pushes 100 ms into the wait, as the listener does.
    $pusher = proc_open(['sh', '-c', 'sleep 0.1; exec valkey-cli -p "$1" rpush "$2" \'{"status":"partial"}\'', 'sh',
        (string) $GLOBALS['executorValkeyPort'], 'orbit-gateway:'.ExecutorResults::resultKey($id)], [1 => ['file', '/dev/null', 'w']], $pipes);

    $result = executorWait($this)->wait($id, 0.5);
    proc_close($pusher);

    expect($result->status)->toBe(ExecutorWaitStatus::Partial)
        ->and($result->elapsedMs)->toBeGreaterThan(90.0)->toBeLessThan(400.0);
});

it('times out at its bound when no result arrives', function (): void {
    ($this->redis)()->command('rpush', [ExecutorResults::acceptedKey($this->operation->id), '{"status":"accepted"}']);

    $result = executorWait($this)->wait($this->operation->id, 0.2);

    expect($result->status)->toBe(ExecutorWaitStatus::Timeout)
        ->and($result->acceptedMs)->not->toBeNull()
        ->and($result->elapsedMs)->toBeGreaterThanOrEqual(195.0)->toBeLessThan(240.0);
});
