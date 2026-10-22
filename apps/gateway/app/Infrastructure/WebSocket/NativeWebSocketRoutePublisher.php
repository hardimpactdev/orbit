<?php

declare(strict_types=1);

namespace App\Infrastructure\WebSocket;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\WebSocket\WebSocketRoutePublisher;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildException;
use App\Infrastructure\Routes\AppRouteCaddyPublisher;
use App\Models\Route;

final readonly class NativeWebSocketRoutePublisher implements WebSocketRoutePublisher
{
    public function __construct(private AppRouteCaddyPublisher $publisher) {}

    public function publish(Route $route): void
    {
        try {
            $this->publisher->publish($route);
        } catch (NodeCaddyBuildException $exception) {
            throw new ResourceOperationException('websocket.route_publication_failed',
                'The attachment environment is stored, but its Caddy build failed. Retry public attachment to publish, or run node:converge with --force on the Route serving Nodes to repair a withdrawal.',
                502, previous: $exception);
        }
    }
}
