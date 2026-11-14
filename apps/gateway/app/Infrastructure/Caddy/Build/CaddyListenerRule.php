<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy\Build;

/**
 * The listener rules of ADR 0141, and the own-site rule of an Instance container. Each site has exactly one.
 */
enum CaddyListenerRule: string
{
    /** Workload and Router sites, custom proxy Routes, tracking hosts, and Vite: the WireGuard and LAN addresses. */
    case Wildcard = 'wildcard';

    /** Public Ingress sites: `0.0.0.0`, reached on the public address, and the WireGuard and LAN addresses. */
    case Public = 'public';

    /** `gateway.orbit`, `metrics.orbit`, and the service metrics scrape site: the WireGuard address only. */
    case WireGuard = 'wireguard';

    /** `websocket`, `analytics`, and the sites that extensions contribute: the WireGuard address. */
    case Shared = 'shared';

    /**
     * The own site of an Instance container on a private Route: the Instance bridge's gateway address, for that container alone
     * (docs/reference/development-containers.md#the-own-site-from-inside).
     */
    case Bridge = 'bridge';
}
