<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Models\App;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Services\Gateway\CaddyGlobalConfig;
use App\Services\Proxy\IngressResolver;
use App\Services\Proxy\ProxyRouteRenderer;
use App\Tools\CaddyTool;
use RuntimeException;
use Throwable;

final readonly class EnsureAppProxyRoute
{
    public function __construct(
        private RemoteShell $remoteShell,
        private SiteCertificateInstaller $siteCertificateInstaller,
        private CaddyGlobalConfig $caddyGlobalConfig,
        private IngressResolver $ingressResolver,
        private ProxyRouteRenderer $proxyRouteRenderer,
    ) {}

    /**
     * @return list<array<string, string>>
     */
    public function handle(App $app): array
    {
        $app->loadMissing('node');

        if ($app->node === null) {
            throw new RuntimeException("App '{$app->name}' has no owning node.");
        }

        $domain = $this->domain($app);
        [$servingNode, $config, $content] = $this->routeArtifact($app, $domain);

        ProxyRoute::query()->updateOrCreate(
            ['domain' => $domain],
            [
                'node_id' => $servingNode->id,
                'app_id' => $app->id,
                'owner_type' => 'app',
                'kind' => 'app',
                'config' => $config,
                'source_hash' => hash('sha256', $content),
            ],
        );

        try {
            $this->siteCertificateInstaller->ensureFor($servingNode, $domain);
            $this->ensureGlobalCaddyfile($servingNode);
        } catch (Throwable) {
            return [[
                'code' => 'proxy.enactment_failed',
                'family' => 'proxy',
                'message' => "Proxy route '{$domain}' was recorded, but TLS material could not be installed. Run doctor to converge proxy artifacts.",
                'next_command' => 'doctor --fix --family=proxy --restore',
            ]];
        }

        $result = $this->remoteShell->run($servingNode, $this->renderInstallScript($domain, $content));

        if (! $result->successful()) {
            return [[
                'code' => 'proxy.enactment_failed',
                'family' => 'proxy',
                'message' => "Proxy route '{$domain}' was recorded, but backend enactment failed. Run doctor to converge proxy artifacts.",
                'next_command' => 'doctor --fix --family=proxy --restore',
            ]];
        }

        if (($config['placement'] ?? null) === 'ingress') {
            $routerArtifact = $config['router_artifact'] ?? null;

            if (! is_array($routerArtifact)) {
                throw new RuntimeException("Proxy route '{$domain}' is missing a router artifact.");
            }

            $routerNodeId = $routerArtifact['node_id'] ?? null;
            $routerNode = is_int($routerNodeId) ? Node::query()->find($routerNodeId) : null;

            if (! $routerNode instanceof Node) {
                throw new RuntimeException("Proxy route '{$domain}' points at an unavailable router node.");
            }

            $routerContent = $this->proxyRouteRenderer->renderRouterRoute(new ProxyRoute([
                'node_id' => $routerNode->id,
                'domain' => $domain,
                'app_id' => $app->id,
                'owner_type' => 'app',
                'kind' => 'app',
                'config' => $config,
            ]));

            $this->ensureGlobalCaddyfile($routerNode);
            $routerResult = $this->remoteShell->run($routerNode, $this->renderInstallScript($domain, $routerContent));

            if (! $routerResult->successful()) {
                return [[
                    'code' => 'proxy.enactment_failed',
                    'family' => 'proxy',
                    'message' => "Proxy route '{$domain}' was recorded, but router enactment failed. Run doctor to converge proxy artifacts.",
                    'next_command' => 'doctor --fix --family=proxy --restore',
                ]];
            }

            $backendArtifact = $config['backend_artifacts'][0] ?? null;

            if (! is_array($backendArtifact)) {
                throw new RuntimeException("Proxy route '{$domain}' is missing a backend artifact.");
            }

            $backendContent = $this->proxyRouteRenderer->renderPrivateBackend(
                new ProxyRoute([
                    'domain' => $domain,
                    'kind' => 'app',
                    'owner_type' => 'app',
                    'app_id' => $app->id,
                    'config' => $config,
                ]),
                $backendArtifact,
            );

            $this->ensureGlobalCaddyfile($app->node);
            $backendResult = $this->remoteShell->run($app->node, $this->renderInstallScript($domain, $backendContent, backend: true));

            if (! $backendResult->successful()) {
                return [[
                    'code' => 'proxy.enactment_failed',
                    'family' => 'proxy',
                    'message' => "Proxy route '{$domain}' was recorded, but backend enactment failed. Run doctor to converge proxy artifacts.",
                    'next_command' => 'doctor --fix --family=proxy --restore',
                ]];
            }
        }

        return $this->productionActivationWarnings($app);
    }

    /**
     * @return list<array<string, string>>
     */
    private function productionActivationWarnings(App $app): array
    {
        if (! is_string($app->domain) || $app->domain === '') {
            return [];
        }

        return [[
            'code' => 'proxy.domain_inactive',
            'family' => 'proxy',
            'message' => "Production domain '{$app->domain}' is not yet active. Retry with 'orbit app:register {$app->name} --domain={$app->domain}' once DNS has propagated.",
            'next_command' => "app:register {$app->name} --domain={$app->domain}",
        ]];
    }

    /**
     * @param  array{
     *     document_root: string,
     *     php_socket: string,
     *     tls: array{cert_path: string, key_path: string},
     * }  $config
     */
    private function renderCaddySite(App $app, string $domain, array $config): string
    {
        $pathBlocking = $app->document_root === '.'
            ? 'import path_blocking_project_root'
            : 'import path_blocking_public_root';

        return <<<CADDY
{$domain} {
    tls {$config['tls']['cert_path']} {$config['tls']['key_path']}
    root * {$config['document_root']}
    encode gzip

    import security_headers
    import profiling_headers
    {$pathBlocking}
    import security_txt
    import cache_headers
    php_fastcgi unix/{$config['php_socket']}
    file_server
}

CADDY;
    }

    private function renderInstallScript(string $domain, string $content, bool $backend = false): string
    {
        $suffix = $backend ? '.backend' : '';
        $sitePath = "/etc/caddy/sites/{$domain}{$suffix}.caddy";

        return sprintf(
            <<<'SH'
sudo install -d -m 0755 /etc/caddy /etc/caddy/sites
printf %%s %s | base64 -d | sudo tee %s >/dev/null
%s
SH,
            escapeshellarg(base64_encode($content)),
            escapeshellarg($sitePath),
            CaddyTool::reloadCommand(),
        );
    }

    private function ensureGlobalCaddyfile(Node $node): void
    {
        $readResult = $this->remoteShell->run(
            $node,
            'sudo test -f /etc/caddy/Caddyfile && sudo cat /etc/caddy/Caddyfile || true',
            ['throw' => true],
        );

        $updated = $this->caddyGlobalConfig->ensure($readResult->stdout);

        if ($updated === $readResult->stdout) {
            return;
        }

        $this->remoteShell->run(
            $node,
            sprintf(
                'sudo install -d -m 0755 /etc/caddy && printf %%s %s | base64 -d | sudo tee /etc/caddy/Caddyfile >/dev/null',
                escapeshellarg(base64_encode($updated)),
            ),
            ['throw' => true],
        );
    }

    private function domain(App $app): string
    {
        if (is_string($app->domain) && $app->domain !== '') {
            return $app->domain;
        }

        $tld = is_string($app->node?->tld) ? trim($app->node->tld, '.') : '';

        if ($tld === '') {
            return $app->name;
        }

        return "{$app->name}.{$tld}";
    }

    private function socketPath(App $app): string
    {
        $user = $app->node?->user ?: 'orbit';
        $home = $user === 'root' ? '/root' : "/home/{$user}";

        return "{$home}/.config/orbit/php/{$app->name}.sock";
    }

    /**
     * @return array{0: Node, 1: array<string, mixed>, 2: string}
     */
    private function routeArtifact(App $app, string $domain): array
    {
        if ($app->environment !== 'production') {
            $certificatePaths = $this->siteCertificateInstaller->expectedPathsFor($app->node, $domain);
            $config = [
                'document_root' => $app->documentRootPath(),
                'php_socket' => $this->socketPath($app),
                'tls' => [
                    'cert_path' => $certificatePaths['cert'],
                    'key_path' => $certificatePaths['key'],
                ],
            ];

            return [$app->node, $config, $this->renderCaddySite($app, $domain, $config)];
        }

        $ingressNode = $this->ingressResolver->forAppNode($app->node);
        $routerNode = $this->ingressResolver->router();
        $certificatePaths = $this->siteCertificateInstaller->expectedPathsFor($ingressNode, $domain);
        $backendArtifact = [
            'node_id' => $app->node->id,
            'domain' => $domain,
            'bind' => $app->node->wireguard_address,
            'document_root' => $app->documentRootPath(),
            'php_socket' => $this->socketPath($app),
        ];
        $config = [
            'placement' => 'ingress',
            'ingress_node_id' => $ingressNode->id,
            'router_upstream' => [
                'node_id' => $routerNode->id,
                'node' => $routerNode->name,
                'url' => $this->ingressResolver->routerUrl($routerNode),
            ],
            'router_backend_pool' => [
                [
                    'node_id' => $app->node->id,
                    'node' => $app->node->name,
                    'url' => $this->ingressResolver->backendUrl($app->node),
                ],
            ],
            'backend_artifacts' => [$backendArtifact],
            'tls' => [
                'cert_path' => $certificatePaths['cert'],
                'key_path' => $certificatePaths['key'],
            ],
        ];

        $routerContent = $this->proxyRouteRenderer->renderRouterRoute(new ProxyRoute([
            'node_id' => $routerNode->id,
            'domain' => $domain,
            'app_id' => $app->id,
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => $config,
        ]));
        $config['router_artifact'] = [
            'node_id' => $routerNode->id,
            'node' => $routerNode->name,
            'source_hash' => hash('sha256', $routerContent),
        ];

        $content = $this->proxyRouteRenderer->renderIngress(new ProxyRoute([
            'node_id' => $ingressNode->id,
            'domain' => $domain,
            'app_id' => $app->id,
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => $config,
        ]));

        $backendArtifact['source_hash'] = hash('sha256', $this->proxyRouteRenderer->renderPrivateBackend(
            new ProxyRoute([
                'domain' => $domain,
                'app_id' => $app->id,
                'owner_type' => 'app',
                'kind' => 'app',
                'config' => ['placement' => 'ingress'],
            ]),
            $backendArtifact,
        ));
        $config['backend_artifacts'] = [$backendArtifact];

        return [$ingressNode, $config, $content];
    }
}
