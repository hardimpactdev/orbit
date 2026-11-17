<?php

declare(strict_types=1);

use App\Domain\Operations\OperationStatus;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Infrastructure\Executor\ExecutorReply;
use App\Infrastructure\Executor\ExecutorResultQueue;
use App\Infrastructure\Executor\ExecutorResults;
use App\Infrastructure\Executor\ExecutorResultWait;
use App\Infrastructure\Executor\ExecutorWaitStatus;
use App\Infrastructure\Executor\ValkeyExecutorResultQueue;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Redis\Connections\Connection;
use Psr\Log\NullLogger;

/*
 * ADR 0266 slice 3: the real Valkey push and BLPOP, against a `valkey-server` that the test starts on a free port
 * with `hz 100`, as the bound needs (docs/reference/executor.md#the-listener-and-the-hand-off). These measure
 * timing and expiry, which no fake can, so only they skip where no `valkey-server` binary is installed, as in CI.
 * The waiter's status and reason mapping runs against an in-memory queue on a fake clock, in CI too.
 */

uses(LazilyRefreshDatabase::class);

beforeAll(function (): void {
    $binary = trim((string) shell_exec('command -v valkey-server 2>/dev/null'));

    if ($binary === '') {
        return;
    }

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $name = (string) stream_socket_get_name($socket, false);
    $port = (int) substr($name, strrpos($name, ':') + 1);
    fclose($socket);
    $GLOBALS['executorValkey'] = proc_open([$binary, '--port', (string) $port, '--bind', '127.0.0.1', '--save', '', '--appendonly', 'no', '--hz', '100'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);

    for ($i = 0; $i < 100; $i++) {
        $probe = @fsockopen('127.0.0.1', $port, $code, $error, 0.05);

        if ($probe !== false) {
            fclose($probe);
            $GLOBALS['executorValkeyPort'] = $port;

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

/** @param array<string, mixed> $line */
function executorHandOff(object $test, array $line): void
{
    $reply = ExecutorReply::fromEvent(['operation' => (string) $test->operation->id, 'line' => json_encode($line)]);
    expect($reply)->not->toBeNull();
    $test->results->receive($test->node->id, $reply);
}

/** An in-memory queue on a fake clock: a pop that finds nothing moves the clock by its whole timeout. */
function executorFakeQueue(): object
{
    return new class implements ExecutorResultQueue
    {
        public float $now = 1000.0;

        /** @var array<string, list<string>> */
        public array $lists = [];

        /** @var list<float> */
        public array $timeouts = [];

        public function push(string $key, string $line, int $expirySeconds): void
        {
            $this->lists[$key][] = $line;
        }

        public function pop(array $keys, float $timeoutSeconds): ?array
        {
            $this->timeouts[] = $timeoutSeconds;

            foreach ($keys as $key) {
                if (($this->lists[$key] ?? []) !== []) {
                    $this->now += 0.01;

                    return [$key, array_shift($this->lists[$key])];
                }
            }

            $this->now += $timeoutSeconds;

            return null;
        }
    };
}

function executorFakeWait(object $queue): ExecutorResultWait
{
    return new ExecutorResultWait($queue, static fn (): float => $queue->now);
}

it('maps each result status and its reason, and skips lines that are no result', function (string $line, ExecutorWaitStatus $status, ?string $reason): void {
    $queue = executorFakeQueue();
    $queue->push(ExecutorResults::resultKey(5), 'not json', 60);
    $queue->push(ExecutorResults::resultKey(5), '{"status":"timeout"}', 60);
    $queue->push(ExecutorResults::resultKey(5), $line, 60);

    $result = executorFakeWait($queue)->wait(5, 0.5);

    expect($result->status)->toBe($status)
        ->and($result->reason())->toBe($reason)
        ->and($result->line)->toBe(json_decode($line, true));
})->with([
    'done' => ['{"status":"done","repeat":true}', ExecutorWaitStatus::Done, null],
    'partial' => ['{"status":"partial"}', ExecutorWaitStatus::Partial, null],
    'failed' => ['{"status":"failed","code":"restore_failed"}', ExecutorWaitStatus::Failed, 'restore_failed'],
    'refused' => ['{"status":"refused","reason":"bad_signature","node_clock_ms":1}', ExecutorWaitStatus::Refused, 'bad_signature'],
]);

it('notes the accepted line, prefers a waiting result, and times out at its bound', function (): void {
    $queue = executorFakeQueue();
    $queue->push(ExecutorResults::acceptedKey(5), '{"status":"accepted"}', 60);

    $timeout = executorFakeWait($queue)->wait(5, 0.5);

    expect($timeout->status)->toBe(ExecutorWaitStatus::Timeout)
        ->and($timeout->acceptedMs)->toBe(10.0)
        ->and($timeout->elapsedMs)->toBe(500.0)
        ->and($queue->timeouts)->toBe([0.5, 0.49]);

    $queue->push(ExecutorResults::acceptedKey(5), '{"status":"accepted"}', 60);
    $queue->push(ExecutorResults::resultKey(5), '{"status":"done"}', 60);

    $done = executorFakeWait($queue)->wait(5, 0.5);

    expect($done->status)->toBe(ExecutorWaitStatus::Done)->and($done->acceptedMs)->toBe($done->elapsedMs)
        ->and($queue->lists[ExecutorResults::acceptedKey(5)])->toHaveCount(1);
});

describe('against a real valkey-server', function (): void {
    beforeEach(function (): void {
        if (! isset($GLOBALS['executorValkeyPort'])) {
            $this->markTestSkipped('Needs a valkey-server binary: these tests measure the real BLPOP bound and key expiry.');
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
        $queue = new ValkeyExecutorResultQueue($this->redis);
        $this->results = new ExecutorResults($queue, new NullLogger, app(CommandActivityInputSanitizer::class));
        $this->wait = new ExecutorResultWait($queue, static fn (): float => microtime(true));
    });

    it('pushes both keys with a 60 s expiry and hands a result over at once', function (): void {
        $id = $this->operation->id;
        $done = ['operation' => (string) $id, 'status' => 'done', 'repeat' => false];
        executorHandOff($this, ['status' => 'accepted']);
        executorHandOff($this, $done);

        expect(($this->redis)()->command('ttl', [ExecutorResults::resultKey($id)]))->toBeGreaterThan(55)->toBeLessThanOrEqual(60)
            ->and(($this->redis)()->command('ttl', [ExecutorResults::acceptedKey($id)]))->toBeGreaterThan(55)->toBeLessThanOrEqual(60);

        $result = $this->wait->wait($id, 0.5);

        expect($result->status)->toBe(ExecutorWaitStatus::Done)
            ->and($result->line)->toBe($done)
            ->and($result->elapsedMs)->toBeLessThan(50.0);
    });

    it('returns a result that arrives while it waits, within the bound', function (): void {
        $id = $this->operation->id;
        // Another process pushes 100 ms into the wait, as the listener does.
        $pusher = proc_open(['sh', '-c', 'sleep 0.1; exec valkey-cli -p "$1" rpush "$2" \'{"status":"partial"}\'', 'sh',
            (string) $GLOBALS['executorValkeyPort'], 'orbit-gateway:'.ExecutorResults::resultKey($id)], [1 => ['file', '/dev/null', 'w']], $pipes);

        $result = $this->wait->wait($id, 0.5);
        proc_close($pusher);

        expect($result->status)->toBe(ExecutorWaitStatus::Partial)
            ->and($result->elapsedMs)->toBeGreaterThan(90.0)->toBeLessThan(400.0);
    });

    it('times out at its bound when no result arrives', function (): void {
        executorHandOff($this, ['status' => 'accepted']);

        $result = $this->wait->wait($this->operation->id, 0.2);

        expect($result->status)->toBe(ExecutorWaitStatus::Timeout)
            ->and($result->acceptedMs)->not->toBeNull()
            ->and($result->elapsedMs)->toBeGreaterThanOrEqual(195.0)->toBeLessThan(240.0);
    });
});
