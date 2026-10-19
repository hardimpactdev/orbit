<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Infrastructure;

use Orbit\Extensions\AiRouter\Domain\AiRouterProcess;

/**
 * Renders the Caddy site that terminates Orbit-CA TLS for the collector hostname and reverse-proxies to the loopback
 * collector. Caddy retries the collector for a few seconds when it cannot connect, so a request that arrives while
 * enable restarts the collector waits for it instead of failing.
 */
final readonly class AiRouterCaddySiteRenderer
{
    public const string RetryDuration = '5s';

    public function render(): string
    {
        $port = AiRouterProcess::PORT;
        $marker = AiRouterFootprint::CaddyFragmentMarker;
        $host = AiRouterFootprint::Hostname;
        $certificate = AiRouterFootprint::CertificateCurrentDirectory;
        $bind = AiRouterFootprint::CaddyBindPlaceholder;
        $retry = self::RetryDuration;

        return <<<CADDY
            {$marker}
            {$host} {
                bind {$bind}
                tls {$certificate}/ai_router.pem {$certificate}/ai_router.key
                reverse_proxy 127.0.0.1:{$port} {
                    lb_try_duration {$retry}
                }
            }
            CADDY;
    }
}
