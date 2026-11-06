<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use Illuminate\Container\Attributes\Singleton;

/** One sequential request connection per PHP process, reused across terminal jobs and matched by endpoint and authority. */
#[Singleton]
final class SwampConnection
{
    private ?WebSocketClient $shared = null;

    private ?string $sharedKey = null;

    public function socket(WebSocketEndpoint $endpoint, float $timeoutSeconds, WebSocketClient $own): WebSocketClient
    {
        $key = $this->key($endpoint);
        if (! $this->holds($endpoint)) {
            $this->release();
            $own->connect($endpoint, $timeoutSeconds);
            [$this->shared, $this->sharedKey] = [$own, $key];
        }

        return $this->shared ?? $own;
    }

    /** Whether a connection from an earlier request is still held for this endpoint. It may have been closed by serve while idle, which PHP cannot see until the connection is used. */
    public function holds(WebSocketEndpoint $endpoint): bool
    {
        return $this->shared !== null && $this->sharedKey === $this->key($endpoint) && $this->shared->isConnected();
    }

    public function release(): void
    {
        $this->shared?->close();
        $this->shared = $this->sharedKey = null;
    }

    private function key(WebSocketEndpoint $endpoint): string
    {
        return hash('sha256', $endpoint->address."\0".$endpoint->port."\0".$endpoint->serverName."\0".$endpoint->path."\0".$endpoint->caPath."\0".$endpoint->bearerToken);
    }
}
