<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Domain\WebSocket\WebSocketRouteInspector;
use App\Domain\WebSocket\WebSocketRoutePublisher;
use App\Infrastructure\Caddy\Build\NodeCaddySiteSource;
use App\Infrastructure\Caddy\Build\Sources\WebSocketCaddySiteSource;
use App\Infrastructure\WebSocket\NativeWebSocketCredentialManager;
use App\Infrastructure\WebSocket\NativeWebSocketRouteInspector;
use App\Infrastructure\WebSocket\NativeWebSocketRoutePublisher;
use Illuminate\Support\ServiceProvider;

final class WebSocketServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        WebSocketCredentialManager::class => NativeWebSocketCredentialManager::class,
        WebSocketRouteInspector::class => NativeWebSocketRouteInspector::class,
        WebSocketRoutePublisher::class => NativeWebSocketRoutePublisher::class,
    ];

    public function register(): void
    {
        $this->app->tag([WebSocketCaddySiteSource::class], NodeCaddySiteSource::class);
    }
}
