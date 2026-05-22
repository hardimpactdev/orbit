<?php

declare(strict_types=1);

namespace App\Services\Proxy;

use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Data\Doctor\DriftEntry;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Services\Ca\OrbitCaService;
use App\Tools\CaddyTool;

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
        if (! in_array($entry->key, [
            'proxy.route_missing',
            'proxy.route_mismatch',
            'proxy.public_route_missing',
            'proxy.public_route_mismatch',
            'proxy.router_route_missing',
            'proxy.router_route_mismatch',
            'proxy.backend_route_missing',
            'proxy.backend_route_mismatch',
            'proxy.tls_missing',
            'proxy.tls_mismatch',
        ], true)) {
            return null;
        }

        if (! in_array($route->kind, ['app', 'workspace', 'proxy', 'redirect'], true)) {
            return null;
        }

        $route->loadMissing('node');

        if (in_array($entry->key, ['proxy.backend_route_missing', 'proxy.backend_route_mismatch'], true)) {
            return $this->repairBackendRoute($route, $entry);
        }

        if (in_array($entry->key, ['proxy.router_route_missing', 'proxy.router_route_mismatch'], true)) {
            return $this->repairRouterRoute($route, $entry);
        }

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
            'summary' => $this->publicRouteSummary($route, $entry),
            'details' => [
                'route' => $route->domain,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function repairRouterRoute(ProxyRoute $route, DriftEntry $entry): ?array
    {
        $artifact = $this->routerArtifact($route);
        $nodeId = $artifact['node_id'] ?? null;
        $routerNode = is_int($nodeId) ? Node::query()->find($nodeId) : null;

        if (! $routerNode instanceof Node) {
            return null;
        }

        $content = $this->renderer->renderRouterRoute($route);
        $this->remoteShell->run($routerNode, $this->installScript($route->domain, $content), ['throw' => true]);

        $this->updateRouterArtifactHash($route, hash('sha256', $content));

        return [
            'family' => 'proxy',
            'node' => $routerNode->name,
            'code' => $entry->key,
            'key' => $entry->key,
            'mode' => 'fix',
            'status' => 'completed',
            'summary' => "Re-applied private router route {$route->domain} from gateway intent.",
            'details' => [
                'route' => $route->domain,
                'router_node_id' => $nodeId,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function repairBackendRoute(ProxyRoute $route, DriftEntry $entry): ?array
    {
        $artifact = $this->backendArtifactForEntry($route, $entry);

        if ($artifact === null) {
            return null;
        }

        $nodeId = $artifact['node_id'] ?? null;
        $backendNode = is_int($nodeId) ? Node::query()->find($nodeId) : null;

        if (! $backendNode instanceof Node) {
            return null;
        }

        $content = $this->renderer->renderPrivateBackend($route, $artifact);
        $this->remoteShell->run($backendNode, $this->installScript($route->domain, $content, backend: true), ['throw' => true]);

        $this->updateBackendArtifactHash($route, $nodeId, hash('sha256', $content));

        return [
            'family' => 'proxy',
            'node' => $backendNode->name,
            'code' => $entry->key,
            'key' => $entry->key,
            'mode' => 'fix',
            'status' => 'completed',
            'summary' => "Re-applied private backend route {$route->domain} on {$backendNode->name} from gateway intent.",
            'details' => [
                'route' => $route->domain,
                'backend_node_id' => $nodeId,
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

    private function installScript(string $domain, string $content, bool $backend = false): string
    {
        $suffix = $backend ? '.backend' : '';
        $sitePath = "/etc/caddy/sites/{$domain}{$suffix}.caddy";

        return sprintf(
            <<<'SH'
sudo install -d -m 0755 /etc/caddy/sites
printf %%s %s | base64 -d | sudo tee %s >/dev/null
%s
SH,
            escapeshellarg(base64_encode($content)),
            escapeshellarg($sitePath),
            CaddyTool::reloadCommand(),
        );
    }

    private function publicRouteSummary(ProxyRoute $route, DriftEntry $entry): string
    {
        if (in_array($entry->key, ['proxy.public_route_missing', 'proxy.public_route_mismatch'], true)) {
            return "Re-applied public proxy route {$route->domain} from gateway intent.";
        }

        return "Re-applied proxy route {$route->domain} from gateway intent.";
    }

    /**
     * @return array<string, mixed>|null
     */
    private function backendArtifactForEntry(ProxyRoute $route, DriftEntry $entry): ?array
    {
        $backendNodeId = $entry->detail['backend_node_id'] ?? null;

        if (! is_int($backendNodeId)) {
            return null;
        }

        foreach ($this->backendArtifacts($route) as $artifact) {
            if (($artifact['node_id'] ?? null) === $backendNodeId) {
                return $artifact;
            }
        }

        return null;
    }

    private function updateBackendArtifactHash(ProxyRoute $route, int $nodeId, string $hash): void
    {
        $config = is_array($route->config) ? $route->config : [];
        $artifacts = $config['backend_artifacts'] ?? [];

        if (! is_array($artifacts)) {
            return;
        }

        foreach ($artifacts as $index => $artifact) {
            if (! is_array($artifact) || ($artifact['node_id'] ?? null) !== $nodeId) {
                continue;
            }

            $artifacts[$index]['source_hash'] = $hash;
        }

        $config['backend_artifacts'] = $artifacts;

        $route->forceFill(['config' => $config])->save();
    }

    private function updateRouterArtifactHash(ProxyRoute $route, string $hash): void
    {
        $config = is_array($route->config) ? $route->config : [];
        $artifact = $config['router_artifact'] ?? null;

        if (! is_array($artifact)) {
            return;
        }

        $artifact['source_hash'] = $hash;
        $config['router_artifact'] = $artifact;

        $route->forceFill(['config' => $config])->save();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function backendArtifacts(ProxyRoute $route): array
    {
        $config = is_array($route->config) ? $route->config : [];
        $artifacts = $config['backend_artifacts'] ?? null;

        if (! is_array($artifacts)) {
            return [];
        }

        return array_values(array_filter($artifacts, is_array(...)));
    }

    /**
     * @return array<string, mixed>
     */
    private function routerArtifact(ProxyRoute $route): array
    {
        $config = is_array($route->config) ? $route->config : [];
        $artifact = $config['router_artifact'] ?? null;

        return is_array($artifact) ? $artifact : [];
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
%s
SH,
            escapeshellarg(base64_encode($cert)),
            escapeshellarg($certPath),
            escapeshellarg(base64_encode($key)),
            escapeshellarg($keyPath),
            escapeshellarg($certPath),
            escapeshellarg($keyPath),
            CaddyTool::reloadCommand(),
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
%s || true
SH,
            escapeshellarg($sitePath),
            escapeshellarg($certPath),
            escapeshellarg($keyPath),
            CaddyTool::reloadCommand(),
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
