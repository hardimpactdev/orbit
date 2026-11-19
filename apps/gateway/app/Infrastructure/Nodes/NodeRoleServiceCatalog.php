<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\GatewayReleases\GatewayReleaseHost;
use App\Domain\Nodes\RoleName;
use App\Models\Node;

final class NodeRoleServiceCatalog
{
    /** @return list<string> */
    public function forRole(RoleName $role, ?Node $node = null): array
    {
        return match ($role) {
            RoleName::Gateway => $node !== null && GatewayReleaseHost::inventory($node) !== null ? ['caddy', 'incus'] : ['caddy', 'php8.5-fpm'],
            RoleName::Vpn => ['wg-quick@orbit', 'dnsmasq'],
            RoleName::Router => ['caddy'],
            RoleName::Ingress, RoleName::Swamp => ['caddy'],
            RoleName::AppDev, RoleName::AppProd => ['caddy', 'docker'],
            RoleName::Metrics => ['docker'],
            RoleName::Database => ['docker'],
            RoleName::CiRunner => ['incus'],
            RoleName::WebSocket => ['caddy', 'orbit-websocket'],
            RoleName::Analytics => ['caddy', 'docker'],
        };
    }
}
