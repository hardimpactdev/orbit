<?php

declare(strict_types=1);

use App\Domain\Executor\SignedExecutorOrder;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\Engine\EngineBus;
use App\Infrastructure\Engine\NatsCredentials;
use App\Models\Node;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/*
 * The signed read's bus side against a local stand-in peer (tests/Fixtures/nats-silent-peer.php): no NATS server, Node or
 * container is contacted. Every wait ends by the read's deadline, and a closed connection is reported, not waited out.
 */

/**
 * The stand-in peers this test started, stopped after each test.
 *
 * @return ArrayObject<int, resource>
 */
function silentPeers(): ArrayObject
{
    static $peers = new ArrayObject;

    return $peers;
}

/** Starts a stand-in peer and points the Gateway's bus at it. */
function silentPeer(string $mode = ''): void
{
    $process = proc_open([PHP_BINARY, base_path('tests/Fixtures/nats-silent-peer.php'), $mode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if ($process === false) {
        throw new RuntimeException('The stand-in peer did not start.');
    }
    silentPeers()->append($process);
    $port = (int) trim((string) fgets($pipes[1]));
    expect($port)->toBeGreaterThan(0);
    $gateway = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '127.0.0.1', 'public_ssh_host' => '127.0.0.1', 'user' => 'orbit',
    ]));
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    config(['orbit.engine.nats.seed' => NatsCredentials::newSeed(), 'orbit.engine.nats.port' => $port]);
}

function silentOrder(): SignedExecutorOrder
{
    return new SignedExecutorOrder('e30=.c2ln', 'read:12', str_repeat('0', 32), 0);
}

afterEach(function (): void {
    foreach (silentPeers() as $process) {
        proc_terminate($process);
        proc_close($process);
    }
    silentPeers()->exchangeArray([]);
});

it('gives up on a silent peer at the deadline, not at the client timeout', function (): void {
    silentPeer();
    $started = hrtime(true) / 1e9;
    $line = (new EngineBus)->read(7, silentOrder(), $started + 0.8);
    $took = hrtime(true) / 1e9 - $started;

    expect($line)->toBeNull()->and($took)->toBeGreaterThan(0.7)->toBeLessThan(1.0);
    $started = hrtime(true) / 1e9;
    expect((new EngineBus)->actions(7, $started + 0.3))->toBe([])
        ->and(hrtime(true) / 1e9 - $started)->toBeLessThan(0.5);
});

it('counts a connection that closed after the order went out as a lost reply, not a failure to send', function (): void {
    silentPeer('close');
    $started = hrtime(true) / 1e9;
    expect((new EngineBus)->read(7, silentOrder(), $started + 2.0))->toBeNull()
        ->and(hrtime(true) / 1e9 - $started)->toBeLessThan(1.5);
});

it('ends a late greeting and a missing PONG by the one deadline, as a request that was never sent', function (): void {
    silentPeer('stall');
    $started = hrtime(true) / 1e9;
    expect(fn () => (new EngineBus)->read(7, silentOrder(), $started + 1.0))->toThrow(RuntimeException::class, 'engine.read_failed');
    expect(hrtime(true) / 1e9 - $started)->toBeGreaterThan(0.9)->toBeLessThan(1.1);
});

it('reports a request nobody subscribes to as not sent', function (): void {
    silentPeer('nobody');
    $started = hrtime(true) / 1e9;
    expect(fn () => (new EngineBus)->read(7, silentOrder(), $started + 2.0))->toThrow(RuntimeException::class, 'engine.read_failed');
    expect(hrtime(true) / 1e9 - $started)->toBeLessThan(1.0);
});

it('returns no acknowledgement for content that went out unanswered', function (): void {
    silentPeer();
    $started = hrtime(true) / 1e9;
    expect((new EngineBus)->content(7, '{"baseline":null}', $started + 0.5))->toBeNull()
        ->and(hrtime(true) / 1e9 - $started)->toBeLessThan(0.7);
});

it('sends nothing once the deadline has passed', function (): void {
    silentPeer();
    expect(fn () => (new EngineBus)->read(7, silentOrder(), hrtime(true) / 1e9 - 0.1))->toThrow(RuntimeException::class, 'engine.read_failed');
});
