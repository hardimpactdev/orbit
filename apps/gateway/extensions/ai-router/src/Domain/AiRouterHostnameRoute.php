<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Domain;

use App\Domain\Routes\CustomProxyUpstream;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Route;
use App\Models\RouteCustomProxy;
use Throwable;

/**
 * A Route that holds the collector hostname (ADR 0145). Enable takes over only a custom proxy Route that
 * already serves the collector: on the collector Node, to the loopback collector port, with no Process
 * target. It refuses every other Route on that name before it changes anything.
 */
final readonly class AiRouterHostnameRoute
{
    public function takeoverCandidate(Node $node): ?Route
    {
        $route = Route::query()
            ->with(['customProxy', 'targets'])
            ->where('domain', AiRouterHostname::Value)
            ->first();

        if (! $route instanceof Route) {
            return null;
        }

        if (! $this->servesCollector($route, $node)) {
            $port = AiRouterProcess::PORT;
            throw new ResourceOperationException(
                errorCode: 'ai_router.hostname_taken',
                message: "Route [{$route->id}] holds ".AiRouterHostname::Value.' and is not a custom proxy Route to '
                    ."127.0.0.1:{$port} on Node [{$node->name}]. Remove that Route before you enable ai_router.",
                status: 409,
            );
        }

        return $route;
    }

    private function servesCollector(Route $route, Node $node): bool
    {
        $proxy = $route->customProxy;

        if (
            ! $route->isCustomProxy()
            || ! $proxy instanceof RouteCustomProxy
            || (int) $route->node_id !== $node->id
            || (int) $proxy->node_id !== $node->id
            || $proxy->process_id !== null
            || $route->targets->isNotEmpty()
        ) {
            return false;
        }

        try {
            return CustomProxyUpstream::parse($proxy->upstream)->authority() === '127.0.0.1:'.AiRouterProcess::PORT;
        } catch (Throwable) {
            return false;
        }
    }
}
