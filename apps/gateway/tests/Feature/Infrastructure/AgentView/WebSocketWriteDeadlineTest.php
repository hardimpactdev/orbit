<?php

declare(strict_types=1);

use App\Infrastructure\AgentView\StreamWebSocketClient;
use Tests\Support\ScriptedWebSocketServer;

pest()->group('subprocess');

it('closes without throwing when a pong write fails', function (): void {
    $server = ScriptedWebSocketServer::start('ping');

    try {
        $client = new StreamWebSocketClient;
        $client->connect($server->endpoint(), 5.0);
        $stream = new ReflectionProperty($client, 'stream')->getValue($client);
        expect(stream_socket_shutdown($stream, STREAM_SHUT_WR))->toBeTrue();
        $server->waitFor('pinged=');

        // TLS returns a zero-byte write: exercise the real five-second write deadline.
        expect(ScriptedWebSocketServer::messages($client, 1))->toBe([])
            ->and($client->isConnected())->toBeFalse();
    } finally {
        $server->stop();
    }
});
