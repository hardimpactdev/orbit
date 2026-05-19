<?php

declare(strict_types=1);

namespace App\Services\Proxy;

use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Data\Doctor\DriftEntry;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Services\Ca\OrbitCaService;

final readonly class ProxyRouteFixer
{
    public function __construct(
        private RemoteShell $remoteShell,
        private ProxyRouteRenderer $renderer,
        private OrbitCaService $ca,
        private SiteCertificateInstaller $siteCertificateInstaller,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function fix(ProxyRoute $route, DriftEntry $entry): ?array
    {
        if (! in_array($entry->key, ['proxy.route_missing', 'proxy.route_mismatch', 'proxy.tls_missing', 'proxy.tls_mismatch'], true)) {
            return null;
        }

        if (! in_array($route->kind, ['app', 'workspace', 'proxy', 'redirect'], true)) {
            return null;
        }

        $route->loadMissing('node');

        if (in_array($entry->key, ['proxy.tls_missing', 'proxy.tls_mismatch'], true)) {
            $this->repairTls($route);

            return [
                'family' => 'proxy',
                'node' => $route->node->name,
                'code' => $entry->key,
                'key' => $entry->key,
                'mode' => 'fix',
                'status' => 'completed',
                'summary' => "Repaired Orbit-managed TLS material for proxy route {$route->domain}.",
                'details' => [
                    'route' => $route->domain,
                ],
            ];
        }

        $content = $this->renderer->render($route);
        $this->ensureSiteCertificateForOwnedPhpRoute($route);
        $this->remoteShell->run($route->node, $this->installScript($route->domain, $content), ['throw' => true]);

        $route->forceFill([
            'source_hash' => hash('sha256', $content),
        ])->save();

        return [
            'family' => 'proxy',
            'node' => $route->node->name,
            'code' => $entry->key,
            'key' => $entry->key,
            'mode' => 'fix',
            'status' => 'completed',
            'summary' => "Re-applied proxy route {$route->domain} from gateway intent.",
            'details' => [
                'route' => $route->domain,
            ],
        ];
    }

    private function repairTls(ProxyRoute $route): void
    {
        if ($this->ensureSiteCertificateForOwnedPhpRoute($route)) {
            return;
        }

        $leaf = $this->ca->issueLeaf($route->domain);

        $this->remoteShell->run(
            $route->node,
            $this->tlsInstallScript(
                $route->domain,
                (string) file_get_contents($leaf['cert']),
                (string) file_get_contents($leaf['key']),
            ),
            ['throw' => true],
        );
    }

    private function ensureSiteCertificateForOwnedPhpRoute(ProxyRoute $route): bool
    {
        if (! in_array($route->kind, ['app', 'workspace'], true)) {
            return false;
        }

        $this->siteCertificateInstaller->ensureFor($route->node, $route->domain);

        return true;
    }

    private function installScript(string $domain, string $content): string
    {
        $sitePath = "/etc/caddy/sites/{$domain}.caddy";

        return sprintf(
            <<<'SH'
sudo install -d -m 0755 /etc/caddy/sites
printf %%s %s | base64 -d | sudo tee %s >/dev/null
sudo systemctl reload caddy
SH,
            escapeshellarg(base64_encode($content)),
            escapeshellarg($sitePath),
        );
    }

    private function tlsInstallScript(string $domain, string $cert, string $key): string
    {
        $certPath = "/etc/orbit/certs/{$domain}.crt";
        $keyPath = "/etc/orbit/certs/{$domain}.key";

        return sprintf(
            <<<'SH'
sudo install -d -m 0755 /etc/orbit/certs
printf %%s %s | base64 -d | sudo tee %s >/dev/null
printf %%s %s | base64 -d | sudo tee %s >/dev/null
sudo chmod 0644 %s
sudo chmod 0600 %s
orbit_caddy_group=""
if command -v systemctl >/dev/null 2>&1 && systemctl cat caddy >/dev/null 2>&1; then
    orbit_caddy_group=$(systemctl show caddy -p Group --value 2>/dev/null | awk 'NF{print $1; exit}')
    if [ -z "$orbit_caddy_group" ]; then
        orbit_caddy_user=$(systemctl show caddy -p User --value 2>/dev/null | awk 'NF{print $1; exit}')
        if [ -n "$orbit_caddy_user" ] && [ "$orbit_caddy_user" != "root" ]; then
            orbit_caddy_group=$(id -gn "$orbit_caddy_user" 2>/dev/null || true)
        fi
    fi
fi
if [ -z "$orbit_caddy_group" ] && getent group caddy >/dev/null 2>&1; then
    orbit_caddy_group="caddy"
fi
if [ -n "$orbit_caddy_group" ]; then
    sudo chgrp "$orbit_caddy_group" %s
    sudo chmod 0640 %s
fi
sudo systemctl reload caddy
SH,
            escapeshellarg(base64_encode($cert)),
            escapeshellarg($certPath),
            escapeshellarg(base64_encode($key)),
            escapeshellarg($keyPath),
            escapeshellarg($certPath),
            escapeshellarg($keyPath),
            escapeshellarg($keyPath),
            escapeshellarg($keyPath),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function removeExtra(Node $node, string $domain): array
    {
        $sitePath = "/etc/caddy/sites/{$domain}.caddy";
        $certPath = "/etc/orbit/certs/{$domain}.crt";
        $keyPath = "/etc/orbit/certs/{$domain}.key";

        $script = sprintf(
            <<<'SH'
sudo rm -f %s
sudo rm -f %s
sudo rm -f %s
sudo systemctl reload caddy || true
SH,
            escapeshellarg($sitePath),
            escapeshellarg($certPath),
            escapeshellarg($keyPath),
        );

        $this->remoteShell->run($node, $script, ['throw' => true]);

        return [
            'family' => 'proxy',
            'node' => $node->name,
            'code' => $domain,
            'key' => $domain,
            'mode' => 'fix',
            'status' => 'completed',
            'summary' => "Removed extra proxy route {$domain} from node.",
        ];
    }
}
