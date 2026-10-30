<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;

final class FakeRoutePublicationSocket implements WebSocketClient
{
    public array $sent = [];

    public bool $closed = false;

    public ?\Closure $reply = null;

    public ?WebSocketEndpoint $endpoint = null;

    private bool $connected = false;

    public function __construct(private bool $accepted = true) {}

    public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
    {
        $this->endpoint = $endpoint;
        $this->connected = true;
    }

    public function send(array $message): void
    {
        $this->sent[] = $message;
    }

    public function receive(float $timeoutSeconds): array
    {
        $this->connected = false;

        if ($this->reply !== null) {
            return ($this->reply)(array_last($this->sent));
        }

        return $this->accepted ? [['type' => 'event', 'id' => array_last($this->sent)['id'], 'event' => [
            'kind' => 'started', 'workflowName' => 'operation-route-publication', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676',
        ]]] : [];
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }
}
