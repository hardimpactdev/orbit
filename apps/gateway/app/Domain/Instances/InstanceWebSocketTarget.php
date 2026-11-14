<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WebSocket\WebSocketHostname;
use App\Infrastructure\WebSocket\WebSocketDnsTarget;
use App\Infrastructure\WebSocket\WebSocketFootprint;
use App\Models\Instance;
use App\Models\Node;
use App\Models\WebSocketAppTarget;

/**
 * The private Reverb endpoint of an Instance that holds a websocket app on `reverb.orbit`
 * (docs/reference/development-containers.md#network). It is one target of the container's access plan: the exact address and port
 * that private DNS gives the name, with the name, so the container reaches that endpoint and nothing else on the websocket Node.
 * A public app needs no target: its host is the public app Route, which the egress proxy admits.
 */
final readonly class InstanceWebSocketTarget
{
    /** @return array{name: string, host: string, port: int, node_id: int}|null */
    public static function endpoint(Instance $instance): ?array
    {
        if (! WebSocketAppTarget::query()->where('instance_id', $instance->id)->whereNull('route_id')->exists()) {
            return null;
        }
        $role = Node::query()->where('status', LifecycleStatus::Active->value)->whereNotNull('wireguard_ip')
            ->whereHas('roles', static fn ($query) => $query->where('role', RoleName::WebSocket->value)->where('status', LifecycleStatus::Active->value))
            ->orderBy('id')->first();
        $node = app(WebSocketDnsTarget::class)->node($role);
        // On the container's own Node the traffic arrives from the bridge address, which the listener's VPN-only guard aborts: no
        // target, so the env check refuses an endpoint the container cannot reach.
        if (! $node instanceof Node || ! is_string($node->wireguard_ip) || $node->id === $instance->node_id) {
            return null;
        }

        return ['name' => WebSocketHostname::Value, 'host' => $node->wireguard_ip, 'port' => WebSocketFootprint::InstancePort, 'node_id' => $node->id];
    }
}
