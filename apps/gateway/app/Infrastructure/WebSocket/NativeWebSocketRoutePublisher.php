<?php

declare(strict_types=1);

namespace App\Infrastructure\WebSocket;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\WebSocket\WebSocketRoutePublisher;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildException;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
use App\Models\Node;
use App\Models\Route;

final readonly class NativeWebSocketRoutePublisher implements WebSocketRoutePublisher
{
    public function __construct(private DevelopmentSiteRepository $sites, private NodeCaddyBuilds $builds) {}

    public function publish(Route $route): void
    {
        $ids = $this->sites->all()->where('domain', $route->domain)->pluck('nodeId')->unique()->all();
        foreach (Node::query()->whereIn('id', $ids)->orderBy('id')->get() as $node) {
            try {
                $this->builds->build($node);
            } catch (NodeCaddyBuildException $exception) {
                throw new ResourceOperationException('websocket.route_publication_failed',
                    'The attachment environment is stored, but its Caddy build failed. Retry public attachment or converge the Route serving Nodes to repair a withdrawal.',
                    502, previous: $exception);
            }
        }
    }
}
