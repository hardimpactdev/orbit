<?php

declare(strict_types=1);

namespace App\Infrastructure\WebSocket;

use App\Domain\WebSocket\WebSocketHostname;
use App\Domain\WebSocket\WebSocketServerCredentials;

/** Renders protected machine credentials for the managed Reverb host. */
final readonly class WebSocketEnvironmentRenderer
{
    public function render(WebSocketServerCredentials $credentials, int $port): string
    {
        $host = WebSocketHostname::Value;
        $lines = [
            '# Managed by Orbit. Do not edit; the websocket role converge overwrites this file.',
            'APP_ENV=production',
            'APP_DEBUG=false',
            "APP_KEY={$credentials->laravelAppKey}",
            'LOG_CHANNEL=stderr',
            // The app has no database, so every store Laravel defaults to `database` is pinned.
            'CACHE_STORE=file',
            'SESSION_DRIVER=file',
            'QUEUE_CONNECTION=sync',
            'REVERB_REGISTRY_URL=https://gateway.orbit/api/v1/websocket-registry',
            "REVERB_PROCESS_ID={$credentials->processId}",
            "REVERB_REGISTRY_TOKEN={$credentials->registryToken}",
            "REVERB_NOTIFICATION_TOKEN={$credentials->notificationToken}",
            "REVERB_HOST={$host}",
            'REVERB_PORT=443',
            'REVERB_SCHEME=https',
            'REVERB_SERVER_HOST=127.0.0.1',
            "REVERB_SERVER_PORT={$port}",
        ];

        return implode("\n", $lines)."\n";
    }
}
