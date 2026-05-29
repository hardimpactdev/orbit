<?php

declare(strict_types=1);

namespace App\Services\Runtime;

/**
 * E2E-local mirror of the gateway orbit-caddy container image identity.
 *
 * The Docker topology provider inspects/asserts this image when preparing
 * topologies. Only the image reference is needed by the harness; kept in sync
 * with apps/gateway/app/Services/Runtime/OrbitCaddyContainer::Image.
 */
final class OrbitCaddyContainer
{
    public const string Image = 'caddy:2-alpine';
}
