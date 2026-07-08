<?php

declare(strict_types=1);

namespace App\Services\Operations;

interface OperationStreamWebSocketTransport
{
    public function connect(OperationStreamWebSocketConnection $connection): void;

    /**
     * @param  array<string, mixed>  $message
     */
    public function send(array $message): void;

    /**
     * @return array<string, mixed>|null
     */
    public function receive(): ?array;

    public function close(): void;
}
