<?php

declare(strict_types=1);

namespace App\Services\Proxy;

use App\Contracts\RemoteShell;
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
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function fix(ProxyRoute $route, DriftEntry $entry): ?array
    {
        if (! in_array($entry->key, ['proxy.route_missing', 'proxy.route_mismatch', 'proxy.tls_missing', 'proxy.tls_mismatch'], true)) {
            return null;
        }

        if ($route->owner_type !== 'custom' || ! in_array($route->kind, ['proxy', 'redirect'], true)) {
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

    private function installScript(string $domain, string $content): string
    {
        $sitePath = "/etc/caddy/sites/{$domain}.caddy";

        return sprintf(
            <<<'SH'
sudo install -d -m 0755 /etc/caddy/sites
cat <<'ORBIT_CADDY_SITE' | sudo tee %s >/dev/null
%s
ORBIT_CADDY_SITE
sudo systemctl reload caddy
SH,
            escapeshellarg($sitePath),
            $content,
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
sudo systemctl reload caddy
SH,
            escapeshellarg(base64_encode($cert)),
            escapeshellarg($certPath),
            escapeshellarg(base64_encode($key)),
            escapeshellarg($keyPath),
            escapeshellarg($certPath),
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
