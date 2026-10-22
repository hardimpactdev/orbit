<?php

declare(strict_types=1);

namespace App\Infrastructure\WebSocket;

use App\Domain\Instances\LaravelRouteInspector;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WebSocket\WebSocketRouteInspector;
use App\Models\Instance;

final readonly class NativeWebSocketRouteInspector implements WebSocketRouteInspector
{
    public function __construct(private LaravelRouteInspector $inspector) {}

    public function hasCollisions(Instance $instance): bool
    {
        try {
            return $this->inspector->hasCollisions($instance, ['/app', '/apps']);
        } catch (ResourceOperationException $exception) {
            throw new ResourceOperationException('websocket.route_inspection_failed',
                'Could not inspect the current Laravel release for /app and /apps route collisions.', 409, previous: $exception);
        }
    }
}
