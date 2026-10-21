<?php

declare(strict_types=1);

namespace App\Infrastructure\WebSocket;

use App\Domain\WebSocket\WebSocketCredentials;
use App\Domain\WebSocket\WebSocketHostname;
use App\Domain\WebSocket\WebSocketServerCredentials;

/**
 * Renders the plain Reverb app's own `.env` file. The app is an ordinary Laravel app with no Orbit-specific
 * code. Its `config/reverb.php` builds one Reverb app from each entry of the `REVERB_APPS` JSON list.
 */
final readonly class WebSocketEnvironmentRenderer
{
    public function render(WebSocketServerCredentials $credentials, int $port): string
    {
        $host = WebSocketHostname::Value;
        $apps = json_encode(
            array_map(static fn (WebSocketCredentials $app): array => [
                'app_id' => $app->appId,
                'key' => $app->appKey,
                'secret' => $app->appSecret,
            ], $credentials->apps),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );

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
            // Single quotes keep the JSON literal: the values are random letters and digits.
            "REVERB_APPS='{$apps}'",
            "REVERB_HOST={$host}",
            'REVERB_PORT=443',
            'REVERB_SCHEME=https',
            'REVERB_SERVER_HOST=127.0.0.1',
            "REVERB_SERVER_PORT={$port}",
        ];

        return implode("\n", $lines)."\n";
    }
}
