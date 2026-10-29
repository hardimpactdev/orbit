<?php

declare(strict_types=1);

use App\Actions\Broadcasting\PresenceChannelSigner;
use App\Domain\AgentView\AgentStateView;
use App\Domain\AgentView\AgentViewFreshness;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Infrastructure\AgentView\AgentViewSubscriber;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Infrastructure\AgentView\StreamWebSocketClient;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\AgentView\WebSocketException;
use App\Models\Node;
use Psr\Log\NullLogger;
use Tests\Support\ScriptedWebSocketServer;

describe(StreamWebSocketClient::class, function (): void {
    it('completes the TLS and WebSocket handshake against the pinned certificate', function (): void {
        $server = ScriptedWebSocketServer::start('frames');

        try {
            $client = new StreamWebSocketClient;
            $client->connect($server->endpoint(), 5.0);

            expect($client->isConnected())->toBeTrue()
                ->and($server->waitFor('request='))->toBe('request=GET /app/key?protocol=7 HTTP/1.1');
        } finally {
            $server->stop();
        }
    });

    it('sends reader authorization in a header and accepts a bounded larger reply', function (): void {
        $server = ScriptedWebSocketServer::start('frames');
        try {
            $base = $server->endpoint();
            $endpoint = new WebSocketEndpoint($base->address, $base->port, $base->serverName, '/', $base->caPath, 'fixture-reader-token');
            $client = new StreamWebSocketClient(2_097_152, 2_097_152);
            $client->connect($endpoint, 1.0);
            expect($server->waitFor('reader-authorization='))->toBe('reader-authorization=yes')
                ->and($server->waitFor('request='))->toBe('request=GET / HTTP/1.1');
            $messages = ScriptedWebSocketServer::messages($client, 4, 1.0);
            expect(array_column($messages, 'event'))->toContain('too-big');
            $client->close();
        } finally {
            $server->stop();
        }
    });

    it('refuses a certificate for another name', function (): void {
        $server = ScriptedWebSocketServer::start('frames');

        try {
            expect(fn () => (new StreamWebSocketClient)->connect($server->endpoint('gateway.orbit'), 5.0))
                ->toThrow(WebSocketException::class, 'Could not connect to Reverb');
        } finally {
            $server->stop();
        }
    });

    it('refuses a refused handshake and a wrong accept key', function (string $mode, string $message, int $code): void {
        $server = ScriptedWebSocketServer::start($mode);

        try {
            $client = new StreamWebSocketClient;

            expect(fn () => $client->connect($server->endpoint(), 5.0))->toThrow(function (WebSocketException $exception) use ($message, $code): void {
                expect($exception->getMessage())->toContain($message);
                expect($exception->getCode())->toBe($code);
            })
                ->and($client->isConnected())->toBeFalse();
        } finally {
            $server->stop();
        }
    })->with([
        'refused' => ['refuse', 'refused the WebSocket handshake', 400],
        'wrong accept key' => ['bad-accept', 'unexpected WebSocket accept key', 0],
    ]);

    it('returns whole JSON messages and drops oversized, malformed, and binary ones', function (): void {
        $server = ScriptedWebSocketServer::start('frames');

        try {
            $client = new StreamWebSocketClient;
            $client->connect($server->endpoint(), 5.0);

            $events = array_column(ScriptedWebSocketServer::messages($client, 3), 'event');

            // `too-big` and the fragmented `big-fragment`, including its tail frame, pass 64 KB.
            expect($events)->toBe(['one', 'fragmented', 'two'])
                ->and($server->waitFor('pong='))->toBe('pong=p');

            $client->send(['event' => 'hello', 'data' => ['text' => str_repeat('z', 300)]]);
            $echo = ScriptedWebSocketServer::messages($client, 1)[0];

            expect(json_decode((string) $echo['data'], true))->toBe(['event' => 'hello', 'data' => ['text' => str_repeat('z', 300)]]);

            ScriptedWebSocketServer::messages($client, 1, 3.0);

            expect($client->isConnected())->toBeFalse();
        } finally {
            $server->stop();
        }
    });

    it('closes the connection when a frame claims more than 1 MiB', function (): void {
        $server = ScriptedWebSocketServer::start('huge');

        try {
            $client = new StreamWebSocketClient;
            $client->connect($server->endpoint(), 5.0);

            expect(ScriptedWebSocketServer::messages($client, 1, 3.0))->toBe([])
                ->and($client->isConnected())->toBeFalse()
                ->and($server->waitFor('closed='))->toBe('closed=yes');
        } finally {
            $server->stop();
        }
    });

    it('treats a connection reset as a closed connection', function (): void {
        $server = ScriptedWebSocketServer::start('reset');

        try {
            $client = new StreamWebSocketClient;
            $client->connect($server->endpoint(), 5.0);
            $server->waitFor('reset=');
            $server->waitForExit();

            expect(ScriptedWebSocketServer::messages($client, 1, 3.0))->toBe([])
                ->and($client->isConnected())->toBeFalse();
        } finally {
            $server->stop();
        }
    });

    it('delivers a message that arrived before a connection reset', function (): void {
        $server = ScriptedWebSocketServer::start('reset-after-frame');

        try {
            $client = new StreamWebSocketClient;
            $client->connect($server->endpoint(), 5.0);
            $server->waitFor('reset=');
            $server->waitForExit();

            expect($client->receive(1.0))->toBe([['event' => 'last']])
                ->and($client->isConnected())->toBeFalse();
        } finally {
            $server->stop();
        }
    });

    it('delivers a message that arrived before a read error', function (): void {
        $server = ScriptedWebSocketServer::start('corrupt-after-frame');

        try {
            $client = new StreamWebSocketClient;
            $client->connect($server->endpoint(), 5.0);
            $server->waitFor('corrupted=');
            usleep(100_000);

            expect($client->receive(1.0))->toBe([['event' => 'last']])
                ->and($client->isConnected())->toBeFalse();
        } finally {
            $server->stop();
        }
    });

    it('refuses a handshake that the server resets', function (): void {
        $server = ScriptedWebSocketServer::start('reset-handshake');

        try {
            $client = new StreamWebSocketClient;

            expect(fn () => $client->connect($server->endpoint(), 5.0))
                ->toThrow(WebSocketException::class, 'Reverb refused the WebSocket handshake.')
                ->and($client->isConnected())->toBeFalse()
                ->and($server->waitFor('reset='))->toBe('reset=yes');
        } finally {
            $server->stop();
        }
    });

    it('subscribes with a signed membership, fills the view, and reconnects after Reverb drops it', function (): void {
        [$websocketNode, $credentials] = activate_websocket_role(Node::query()->create([
            'name' => 'websocket-node',
            'status' => LifecycleStatus::Active,
            'platform' => 'darwin',
            'public_ssh_host' => '192.0.2.90',
            'wireguard_ip' => '127.0.0.1',
            'user' => 'orbit',
        ]));
        $node = Node::query()->create([
            'name' => 'app-dev',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.30',
            'user' => 'orbit',
            'wireguard_ip' => '10.44.0.3',
            'ssh_host_fingerprint' => 'SHA256:app-dev',
        ]);
        $server = ScriptedWebSocketServer::start('pusher', [$credentials->appSecret, (string) $node->id]);
        $view = app(AgentStateView::class);

        // The subscriber's clock moves only when it sleeps, so its reconnect backoff is exact however loaded the runner is.
        $now = microtime(true);
        $socket = new class(static function () use (&$now): float {
            return $now;
        }) implements WebSocketClient
        {
            /** @var list<float> the subscriber's time at each connect */
            public array $connectedAt = [];

            /** @var list<float> the subscriber's time when it first saw each connection lost */
            public array $lostAt = [];

            private readonly StreamWebSocketClient $inner;

            private bool $connected = false;

            /** @param Closure(): float $clock */
            public function __construct(private readonly Closure $clock)
            {
                $this->inner = new StreamWebSocketClient;
            }

            public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
            {
                $this->connectedAt[] = ($this->clock)();
                $this->inner->connect($endpoint, $timeoutSeconds);
                $this->connected = true;
            }

            public function send(array $message): void
            {
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
                $connected = $this->inner->isConnected();

                if ($this->connected && ! $connected) {
                    $this->lostAt[] = ($this->clock)();
                }
                $this->connected = $connected;

                return $connected;
            }
        };

        try {
            $subscriber = new AgentViewSubscriber(
                socket: $socket,
                credentials: app(WebSocketCredentialManager::class),
                view: app(CacheAgentStateView::class),
                signer: new PresenceChannelSigner,
                log: new NullLogger,
                caPath: $server->caPath,
                commit: static fn (): ?string => null,
                clock: static function () use (&$now): float {
                    return $now;
                },
                sleep: static function (float $seconds) use (&$now): void {
                    $now += $seconds;
                    usleep(1_000);
                },
                reverbPort: $server->port,
            );
            $pass = static function (Closure $until, float $seconds = 10.0) use ($subscriber): bool {
                $deadline = microtime(true) + $seconds;

                while (microtime(true) < $deadline) {
                    $subscriber->pass();

                    if ($until()) {
                        return true;
                    }
                }

                return false;
            };

            expect($pass(fn (): bool => $view->node($node->id)->isFresh()))->toBeTrue()
                ->and($server->waitFor('subscribe='))->toBe('subscribe=signed member=gateway.100.1')
                ->and($view->node($node->id)->status(ProcessRuntime::Systemd, 'orbit-process-7-web'))->toBe('active')
                ->and($view->node((int) $websocketNode->id)->freshness)->toBe(AgentViewFreshness::Missing);

            $server->dropConnection();
            expect($pass(fn (): bool => ! $view->node($node->id)->isFresh()))->toBeTrue();

            expect($pass(fn (): bool => $view->node($node->id)->isFresh()))->toBeTrue()
                ->and($socket->connectedAt)->toHaveCount(2)
                ->and($socket->lostAt)->toHaveCount(1);

            // The first retry waits the one-second backoff plus at most 25% jitter, give or take float rounding.
            expect($socket->connectedAt[1] - $socket->lostAt[0])->toBeGreaterThan(1.0 - 1e-6)->toBeLessThan(1.25 + 1e-6)
                ->and($server->waitFor('subscribe='))->toBe('subscribe=signed member=gateway.200.2')
                ->and($view->subscriber()?->connected)->toBeTrue();
        } finally {
            $server->stop();
        }
    });
});
