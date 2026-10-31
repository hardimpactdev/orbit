<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Route;

/** Stored placement only. A request cannot choose a probe address or firewall source. */
final readonly class RouteHopInput
{
    /** @return array<string, mixed>|null */
    public function firewall(Instance $instance, Route $route, Node $router, int $nodeId): ?array
    {
        $instance->loadMissing('node');
        if ($instance->node_id !== $nodeId) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The firewall belongs to the workload Operation Node.', 422);
        }
        if ($router->id === $nodeId || ! is_string($instance->node->lan_ip) || $instance->node->lan_ip === '') {
            return null;
        }
        if (! is_string($router->lan_ip) || $router->lan_ip === '') {
            throw new ResourceOperationException('route.lan_unreachable', 'The configured workload LAN requires a Router LAN address.', 409);
        }

        return ['operation' => 'converge', 'route_id' => $route->id, 'router' => $router->lan_ip, 'workload' => $instance->node->lan_ip];
    }
}
