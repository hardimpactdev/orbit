<?php

declare(strict_types=1);

namespace App\Infrastructure\WebSocket;

/**
 * Renders the Caddy site that terminates Orbit-CA TLS for `reverb.orbit` on
 * the websocket role's own node and reverse-proxies to the local Reverb
 * process. `reverse_proxy` admits a WebSocket upgrade by default.
 */
final readonly class WebSocketCaddySiteRenderer
{
    public function render(int $port, ?string $gatewayAddress = null): string
    {
        $marker = WebSocketFootprint::CaddyFragmentMarker;
        $host = WebSocketFootprint::Hostname;
        $certificate = WebSocketFootprint::CertificateCurrentDirectory;
        $bind = WebSocketFootprint::CaddyBindPlaceholder;

        $control = "handle /internal/* {\n        respond 403\n    }";
        if (is_string($gatewayAddress) && filter_var($gatewayAddress, FILTER_VALIDATE_IP) !== false) {
            $control = "handle /internal/* {\n        @gateway remote_ip {$gatewayAddress}\n        handle @gateway {\n            reverse_proxy 127.0.0.1:{$port}\n        }\n        respond 403\n    }";
        }

        return <<<CADDY
            {$marker}
            {$host} {
                bind {$bind}
                tls {$certificate}/reverb.pem {$certificate}/reverb.key
                {$control}
                handle {
                    reverse_proxy 127.0.0.1:{$port}
                }
            }

            CADDY;
    }
}
