<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Domain\Broadcasting\RealtimeConnection;
use App\Domain\WebSocket\WebSocketCredentialManager;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

final class BroadcastingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped so the websocket role lookup it performs happens at most
        // once per request, and only when something actually asks for it.
        $this->app->scoped(
            RealtimeConnection::class,
            static fn ($app): RealtimeConnection => new RealtimeConnection(
                credentials: $app->make(WebSocketCredentialManager::class),
                orbitHome: rtrim(string: Config::string('orbit.home'), characters: '/'),
            ),
        );
    }
}
