<?php

declare(strict_types=1);

namespace App\Domain\WebSocket;

use App\Models\WebSocketApp;
use App\Models\WebSocketAppTarget;

/**
 * The stored environment keys that point an Instance's Laravel broadcaster at one Reverb app on
 * the selected public app domain or `wss://reverb.orbit`, with the names Laravel's own `reverb` connection reads.
 */
final readonly class WebSocketAppEnvProjection
{
    /** @var list<string> */
    public const array Keys = [
        'BROADCAST_CONNECTION',
        'REVERB_APP_ID',
        'REVERB_APP_KEY',
        'REVERB_APP_SECRET',
        'REVERB_HOST',
        'REVERB_PORT',
        'REVERB_SCHEME',
    ];

    /** @return array<string, string> */
    public function project(WebSocketApp $app, ?WebSocketAppTarget $target = null): array
    {
        return [
            'BROADCAST_CONNECTION' => 'reverb',
            'REVERB_APP_ID' => $app->app_id,
            'REVERB_APP_KEY' => $app->key,
            'REVERB_APP_SECRET' => $app->secret,
            'REVERB_HOST' => $target?->route->domain ?? WebSocketHostname::Value,
            'REVERB_PORT' => '443',
            'REVERB_SCHEME' => 'https',
        ];
    }
}
