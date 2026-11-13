<?php

declare(strict_types=1);

namespace App\Infrastructure\WebSocket;

/**
 * Renders the Caddy site that terminates Orbit-CA TLS for `reverb.orbit` on
 * the websocket role's own node and reverse-proxies to the local Reverb
 * process. `reverse_proxy` admits a WebSocket upgrade by default.
 *
 * `$listen` is the site's port: 443 on the listener it shares with the Node's
 * other private sites, or the Instance port, a listener that serves this site
 * only (docs/reference/websocket-apps.md#reach-from-a-container).
 */
final readonly class WebSocketCaddySiteRenderer
{
    public function render(int $port, ?string $gatewayAddress = null, int $listen = 443): string
    {
        $marker = WebSocketFootprint::CaddyFragmentMarker;
        $host = WebSocketFootprint::Hostname.($listen === 443 ? '' : ':'.$listen);
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
