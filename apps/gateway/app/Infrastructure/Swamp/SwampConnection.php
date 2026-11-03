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
        $key = hash('sha256', $endpoint->address."\0".$endpoint->port."\0".$endpoint->serverName."\0".$endpoint->path."\0".$endpoint->caPath."\0".$endpoint->bearerToken);
        if ($this->shared === null || $this->sharedKey !== $key || ! $this->shared->isConnected()) {
            $this->release();
            $own->connect($endpoint, $timeoutSeconds);
            [$this->shared, $this->sharedKey] = [$own, $key];
        }

        return $this->shared;
    }

    public function release(): void
    {
        $this->shared?->close();
        $this->shared = $this->sharedKey = null;
    }
}
