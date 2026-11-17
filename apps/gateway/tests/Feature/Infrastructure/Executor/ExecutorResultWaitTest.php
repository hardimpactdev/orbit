<?php

declare(strict_types=1);

use App\Domain\Operations\OperationStatus;
use App\Infrastructure\Executor\ExecutorReply;
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
 * timing and expiry, which no fake can, so they skip where no `valkey-server` binary is installed, as in CI.
 * ExecutorResultListenerTest covers the listener's checks without a server.
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
    $this->results = new ExecutorResults(new ValkeyExecutorResultQueue($this->redis), new NullLogger);
    $this->wait = new ExecutorResultWait($this->redis, static fn (): float => microtime(true));
});

/** @param array<string, mixed> $line */
function executorHandOff(object $test, array $line): void
{
    $reply = ExecutorReply::fromEvent(['operation' => (string) $test->operation->id, 'line' => json_encode($line)]);
    expect($reply)->not->toBeNull();
    $test->results->receive($test->node->id, $reply);
}

it('pushes both keys with a 60 s expiry and hands a result over at once', function (): void {
    $id = $this->operation->id;
    $done = ['operation' => (string) $id, 'status' => 'done', 'repeat' => false];
    executorHandOff($this, ['status' => 'accepted']);
    executorHandOff($this, $done);

    expect(($this->redis)()->command('ttl', [ExecutorResults::resultKey($id)]))->toBeGreaterThan(55)->toBeLessThanOrEqual(60)
        ->and(($this->redis)()->command('ttl', [ExecutorResults::acceptedKey($id)]))->toBeGreaterThan(55)->toBeLessThanOrEqual(60);

    $result = $this->wait->wait($id, 0.5);

    expect($result->status)->toBe(ExecutorWaitStatus::Done)
        ->and($result->acceptedMs)->not->toBeNull()
        ->and($result->line)->toBe($done)
        ->and($result->elapsedMs)->toBeLessThan(50.0);
});

it('returns a refusal with its reason', function (): void {
    executorHandOff($this, ['status' => 'refused', 'reason' => 'expired', 'node_clock_ms' => 1]);

    $result = $this->wait->wait($this->operation->id, 0.5);

    expect($result->status)->toBe(ExecutorWaitStatus::Refused)->and($result->reason())->toBe('expired');
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
