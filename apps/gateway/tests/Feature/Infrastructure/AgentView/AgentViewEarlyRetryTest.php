<?php

declare(strict_types=1);

use App\Actions\Broadcasting\PresenceChannelSigner;
use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Domain\WebSocket\WebSocketCredentials;
use App\Infrastructure\AgentView\AgentViewSubscriber;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\AgentView\WebSocketException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Psr\Log\NullLogger;

/*
 * A subscriber that boots with its Reverb, as in a nested fleet, connects right after Reverb starts: while nothing answers
 * yet, it asks again every half second instead of backing off.
 */

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    $this->mock(WebSocketCredentialManager::class)->shouldReceive('current')
        ->andReturn(new WebSocketCredentials('test-app', 'test-key', 'test-secret', '10.44.0.2'));
});

/**
 * The times of each connect, over `$seconds` of passes every 100 ms, against a Reverb whose connects fail with `$failure`.
 *
 * @return list<float>
 */
function earlyRetryConnects(WebSocketException $failure, float $seconds): array
{
    $clock = new class
    {
        public float $now = 1000.0;
    };
    $socket = new class($failure, $clock) implements WebSocketClient
    {
        /** @var list<float> */
        public array $at = [];

        public function __construct(private WebSocketException $failure, private object $clock) {}

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
        {
            $this->at[] = $this->clock->now;

            throw $this->failure;
        }

        public function send(array $message): void {}

        public function receive(float $timeoutSeconds): array
        {
            return [];
        }

        public function close(): void {}

        public function isConnected(): bool
        {
            return false;
        }
    };
    $subscriber = new AgentViewSubscriber(
        socket: $socket,
        credentials: app(WebSocketCredentialManager::class),
        view: new CacheAgentStateView(Cache::store('array')),
        signer: app(PresenceChannelSigner::class),
        log: new NullLogger,
        caPath: __FILE__,
        commit: static fn (): ?string => null,
        clock: static fn (): float => $clock->now,
        sleep: static function (float $seconds): void {},
        sockets: static fn (): WebSocketClient => $socket,
    );
    $start = $clock->now;
    $subscriber->pass();
    while ($clock->now - $start < $seconds - 0.05) {
        $clock->now += 0.1;
        $subscriber->pass();
    }

    return array_map(static fn (float $at): float => round($at - $start, 1), $socket->at);
}

it('asks again every half second while the proxy says Reverb is not up yet', function (): void {
    $connects = earlyRetryConnects(new WebSocketException('Reverb refused the WebSocket handshake.', 502, notUp: true), 2.0);

    expect($connects)->toBe([0.0, 0.5, 1.0, 1.5, 2.0]);
});

it('asks again every half second while nothing listens yet', function (): void {
    expect(count(earlyRetryConnects(new WebSocketException('Could not connect to Reverb: Connection refused (111).', notUp: true), 2.0)))->toBe(5);
});

it('backs off as before when Reverb answered, such as a refused handshake', function (): void {
    $connects = earlyRetryConnects(new WebSocketException('Reverb refused the WebSocket handshake.', 401), 2.0);

    expect($connects[0])->toBe(0.0)
        ->and(count($connects))->toBeLessThanOrEqual(2)
        ->and(($connects[1] ?? 99.0) - $connects[0])->toBeGreaterThanOrEqual(1.0);
});

it('gives a link that never connected a short connect limit, and one that connected the full one', function (): void {
    expect(AgentViewSubscriber::FirstConnectTimeoutSeconds)->toBeLessThan(AgentViewSubscriber::ConnectTimeoutSeconds);
    $timeouts = [];
    $socket = new class($timeouts) implements WebSocketClient
    {
        /** @param list<float> $timeouts */
        public function __construct(public array &$timeouts) {}

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
        {
            $this->timeouts[] = $timeoutSeconds;

            throw new WebSocketException('Reverb refused the WebSocket handshake.', 502, notUp: true);
        }

        public function send(array $message): void {}

        public function receive(float $timeoutSeconds): array
        {
            return [];
        }

        public function close(): void {}

        public function isConnected(): bool
        {
            return false;
        }
    };
    $subscriber = new AgentViewSubscriber(socket: $socket, credentials: app(WebSocketCredentialManager::class),
        view: new CacheAgentStateView(Cache::store('array')), signer: app(PresenceChannelSigner::class), log: new NullLogger,
        caPath: __FILE__, commit: static fn (): ?string => null, clock: static fn (): float => 1000.0, sleep: static function (float $seconds): void {},
        sockets: static fn (): WebSocketClient => $socket);

    $subscriber->pass();

    expect($timeouts)->toBe([(float) AgentViewSubscriber::FirstConnectTimeoutSeconds]);
});
