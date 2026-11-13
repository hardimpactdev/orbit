<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Date;

/**
 * One sequential request connection per PHP process, matched by endpoint and authority. Jobs that follow each other
 * share it; before a job starts, `releaseIdle()` drops it once it has been idle for longer than `IdleSeconds`.
 */
#[Singleton]
final class SwampConnection
{
    /** A kept socket left idle for about a minute is found closed; jobs in a burst follow within seconds. */
    public const int IdleSeconds = 10;

    private ?WebSocketClient $shared = null;

    private ?string $sharedKey = null;

    private ?CarbonImmutable $lastActive = null;

    public function socket(WebSocketEndpoint $endpoint, float $timeoutSeconds, WebSocketClient $own): WebSocketClient
    {
        $key = $this->key($endpoint);
        if (! $this->holds($endpoint)) {
            $this->release();
            $own->connect($endpoint, $timeoutSeconds);
            [$this->shared, $this->sharedKey] = [$this->tracked($own), $key];
        }
        $this->lastActive = Date::now()->toImmutable();

        return $this->shared ?? $own;
    }

    /** Whether a connection from an earlier request is still held for this endpoint. It may have been closed by serve while idle, which PHP cannot see until the connection is used. */
    public function holds(WebSocketEndpoint $endpoint): bool
    {
        return $this->shared !== null && $this->sharedKey === $this->key($endpoint) && $this->shared->isConnected();
    }

    /** Drops the kept connection when no request was sent and no frame arrived on it for longer than `IdleSeconds`. */
    public function releaseIdle(): void
    {
        if ($this->lastActive?->addSeconds(self::IdleSeconds)->isPast() === true) {
            $this->release();
        }
    }

    public function release(): void
    {
        $this->shared?->close();
        $this->shared = $this->sharedKey = $this->lastActive = null;
    }

    /** Wraps the kept client so that each send and each received frame counts as activity. */
    private function tracked(WebSocketClient $client): WebSocketClient
    {
        $touch = function (): void {
            $this->lastActive = Date::now()->toImmutable();
        };

        return new readonly class($client, $touch) implements WebSocketClient
        {
            public function __construct(private WebSocketClient $client, private \Closure $touch) {}

            public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
            {
                $this->client->connect($endpoint, $timeoutSeconds);
            }

            public function send(array $message): void
            {
                $this->client->send($message);
                ($this->touch)();
            }

            public function receive(float $timeoutSeconds): array
            {
                $messages = $this->client->receive($timeoutSeconds);
                if ($messages !== []) {
                    ($this->touch)();
                }

                return $messages;
            }

            public function close(): void
            {
                $this->client->close();
            }

            public function isConnected(): bool
            {
                return $this->client->isConnected();
            }
        };
    }

    private function key(WebSocketEndpoint $endpoint): string
    {
        return hash('sha256', $endpoint->address."\0".$endpoint->port."\0".$endpoint->serverName."\0".$endpoint->path."\0".$endpoint->caPath."\0".$endpoint->bearerToken);
    }
}
