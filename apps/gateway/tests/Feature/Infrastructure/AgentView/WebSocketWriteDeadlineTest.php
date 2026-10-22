<?php

declare(strict_types=1);

use App\Infrastructure\AgentView\StreamWebSocketClient;
use Tests\Support\ScriptedWebSocketServer;
use Tests\Support\WebSocketWriteClock;

pest()->group('subprocess');

it('closes without throwing when a pong write fails', function (): void {
    $server = ScriptedWebSocketServer::start('ping');

    try {
        $client = new StreamWebSocketClient;
        $client->connect($server->endpoint(), 5.0);
        $stream = new ReflectionProperty($client, 'stream')->getValue($client);
        expect(stream_socket_shutdown($stream, STREAM_SHUT_WR))->toBeTrue();
        $server->waitFor('pinged=');

        // Only a real zero-byte TLS write reads the clock again to check expiry.
        $clock = new WebSocketWriteClock;
        $clock->run(function () use ($client): void {
            expect(ScriptedWebSocketServer::messages($client, 1))->toBe([])
                ->and($client->isConnected())->toBeFalse();
        });
        expect($clock->reads)->toBe([0.0, 5.0, 5.001])
            ->and($clock->pauses)->toBe([1_000]);
    } finally {
        $server->stop();
    }
});
