<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;

final class FakeSwampEventSocket implements WebSocketClient
{
    public array $sent = [];

    public array $endpoints = [];

    public array $inbox = [];

    public bool $connected = false;

    public ?\Closure $onConnect = null;

    public ?\Closure $onSend = null;

    public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
    {
        ($this->onConnect ?? static function (): void {})();
        $this->endpoints[] = $endpoint;
        $this->connected = true;
    }

    public function send(array $message): void
    {
        $this->sent[] = $message;
        ($this->onSend ?? static function (): void {})($message);
    }

    public function receive(float $timeoutSeconds): array
    {
        return array_shift($this->inbox) ?? [];
    }

    public function close(): void
    {
        $this->connected = false;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function push(array ...$messages): void
    {
        $this->inbox[] = $messages;
    }
}
