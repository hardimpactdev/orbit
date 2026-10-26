<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Infrastructure;

use Orbit\Extensions\AiRouter\Domain\AiRouterHostname;

/**
 * Every trace ai-router leaves on its Node beside the collector Process, and the proof Orbit owns each one.
 */
final readonly class AiRouterFootprint
{
    public const string Hostname = AiRouterHostname::Value;

    public const string CaddyFragmentMarker = '# Managed by Orbit: ai-router';

    public const string CaddyBindPlaceholder = '__ORBIT_AI_ROUTER_BIND__';

    public const string CertificateCurrentDirectory = '/etc/caddy/orbit-ai-router-cert-current';

    public const string CaddyServiceName = 'caddy';
}
