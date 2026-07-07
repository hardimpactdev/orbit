<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Services\Apps\AppDevelopmentInnerTlsPolicy;
use App\Services\Ca\OrbitCaService;
use App\Services\Convergence\ManagedFile;
use App\Services\Proxy\AppProxyRouteCaddyInstaller;
use App\Services\Proxy\IngressResolver;
use App\Services\Proxy\ProxyRouteRenderer;
use RuntimeException;
use Throwable;

final readonly class EnsureWorkspaceProxyRoute
{
    public function __construct(
        private RemoteShell $remoteShell,
        private WorkspaceRuntimeContainerRenderer $runtimeContainerRenderer,
        private SiteCertificateInstaller $siteCertificateInstaller,
        private AppProxyRouteCaddyInstaller $caddyInstaller,
        private IngressResolver $ingressResolver,
        private ProxyRouteRenderer $proxyRouteRenderer,
        private OrbitCaService $ca,
        private AppDevelopmentInnerTlsPolicy $innerTlsPolicy = new AppDevelopmentInnerTlsPolicy,
    ) {}

    /**
     * @return list<array<string, string>>
     */
    public function handle(Workspace $workspace): array
    {
        $workspace->loadMissing(['app', 'app.node']);

        $app = $workspace->app;

        if (! $app instanceof App) {
            throw new RuntimeException("Workspace '{$workspace->name}' has no parent app.");
        }

        $node = $app->node;

        if (! $node instanceof Node) {
            throw new RuntimeException("App '{$app->name}' has no owning node.");
        }

        $domain = $this->domain($workspace, $app, $node);
        [$servingNode, $config, $content] = $this->routeArtifact($workspace, $app, $node, $domain);

        ProxyRoute::query()->updateOrCreate(
            ['domain' => $domain],
            [
                'node_id' => $servingNode->id,
                'app_id' => $app->id,
                'workspace_id' => $workspace->id,
                'owner_type' => 'workspace',
                'kind' => 'workspace',
                'config' => $config,
                'source_hash' => hash('sha256', $content),
            ],
        );

        try {
            $this->siteCertificateInstaller->ensureFor($servingNode, $domain);
            $this->ensureRuntimeTrustPool($servingNode, $config);
            $this->caddyInstaller->ensureGlobalCaddyfile($servingNode);
        } catch (Throwable) {
            return [[
                'code' => 'proxy.enactment_failed',
                'family' => 'proxy',
                'message' => "Proxy route '{$domain}' was recorded, but TLS material could not be installed. Run doctor to converge proxy artifacts.",
                'next_command' => 'doctor --family=proxy --restore',
            ]];
        }

        $result = $this->caddyInstaller->installRouteConfig($servingNode, $domain, $content);

        if (! $result->successful()) {
            return [[
                'code' => 'proxy.enactment_failed',
                'family' => 'proxy',
                'message' => "Proxy route '{$domain}' was recorded, but backend enactment failed. Run doctor to converge proxy artifacts.",
                'next_command' => 'doctor --family=proxy --restore',
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
                'kind' => 'workspace',
                'owner_type' => 'workspace',
                'app_id' => $app->id,
                'workspace_id' => $workspace->id,
                'config' => $config,
            ]));

            $this->caddyInstaller->ensureGlobalCaddyfile($routerNode);
            $routerResult = $this->caddyInstaller->installRouteConfig($routerNode, $domain, $routerContent);

            if (! $routerResult->successful()) {
                return [[
                    'code' => 'proxy.enactment_failed',
                    'family' => 'proxy',
                    'message' => "Proxy route '{$domain}' was recorded, but router enactment failed. Run doctor to converge proxy artifacts.",
                    'next_command' => 'doctor --family=proxy --restore',
                ]];
            }

            $backendArtifact = $config['backend_artifacts'][0] ?? null;

            if (! is_array($backendArtifact)) {
                throw new RuntimeException("Proxy route '{$domain}' is missing a backend artifact.");
            }

            $backendContent = $this->proxyRouteRenderer->renderPrivateBackend(
                new ProxyRoute([
                    'domain' => $domain,
                    'kind' => 'workspace',
                    'owner_type' => 'workspace',
                    'app_id' => $app->id,
                    'workspace_id' => $workspace->id,
                    'config' => $config,
                ]),
                $backendArtifact,
            );

            $this->caddyInstaller->ensureGlobalCaddyfile($node);
            $this->ensureRuntimeTrustPool($node, $config);
            $backendResult = $this->caddyInstaller->installRouteConfig(
                $node,
                $domain,
                $backendContent,
                backend: true,
            );

            if (! $backendResult->successful()) {
                return [[
                    'code' => 'proxy.enactment_failed',
                    'family' => 'proxy',
                    'message' => "Proxy route '{$domain}' was recorded, but backend enactment failed. Run doctor to converge proxy artifacts.",
                    'next_command' => 'doctor --family=proxy --restore',
                ]];
            }
        }

        return [];
    }

    /**
     * @param  array{
     *     document_root: string,
     *     runtime_upstream?: string|null,
     *     php_socket?: string|null,
     *     tls: array{cert_path: string, key_path: string},
     * }  $config
     */
    private function renderCaddySite(Workspace $workspace, App $app, string $domain, array $config): string
    {
        $pathBlocking = $app->document_root === '.'
            ? 'import path_blocking_project_root'
            : 'import path_blocking_public_root';

        if ($app->runtimeKind() === AppRuntimeKind::Php) {
            $upstream = $config['runtime_upstream'] ?? null;

            if (! is_string($upstream) || $upstream === '') {
                throw new RuntimeException(
                    "Workspace '{$workspace->name}' route is missing a runtime container upstream.",
                );
            }

            $transport = $this->runtimeUpstreamTransportDirectives($config);

            return <<<CADDY
                {$domain} {
                    tls {$config['tls']['cert_path']} {$config['tls']['key_path']}
                    encode gzip

                    import security_headers
                    import profiling_headers
                    {$pathBlocking}
                    import security_txt
                    import cache_headers

                    reverse_proxy {$upstream} {
                        header_up Host {host}
                        header_up X-Forwarded-Host {host}
                        header_up X-Forwarded-Proto {scheme}
                {$transport}    }
                }

                CADDY;
        }

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
                file_server
            }

            CADDY;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function ensureRuntimeTrustPool(Node $node, array $config): void
    {
        $runtimeUpstreamTls = $config['runtime_upstream_tls'] ?? null;

        if (! is_array($runtimeUpstreamTls) || ($runtimeUpstreamTls['trusted_by_gateway_ca'] ?? null) !== true) {
            return;
        }

        $caPath = is_string($runtimeUpstreamTls['ca_path'] ?? null) && $runtimeUpstreamTls['ca_path'] !== ''
            ? $runtimeUpstreamTls['ca_path']
            : AppDevelopmentInnerTlsPolicy::RuntimeTrustPoolPath;

        $file = new ManagedFile(
            path: $caPath,
            content: $this->ca->rootCert(),
            mode: '0644',
            directoryMode: '0755',
        );
        $plan = $file->plan($file->probe($node, $this->remoteShell));
        $result = $file->apply($node, $this->remoteShell, $plan);

        if (! $result->successful()) {
            throw new RuntimeException($result->summary);
        }
    }

    private function domain(Workspace $workspace, App $app, Node $node): string
    {
        if ($app->environment === 'production' && is_string($app->domain) && $app->domain !== '') {
            return "{$workspace->name}.{$app->domain}";
        }

        $tld = is_string($node->tld) ? trim($node->tld, '.') : '';

        if ($tld === '') {
            return "{$workspace->name}.{$app->name}";
        }

        return "{$workspace->name}.{$app->name}.{$tld}";
    }

    private function documentRoot(Workspace $workspace, App $app): string
    {
        $root = trim($app->document_root, '/');

        if ($root === '') {
            return rtrim($workspace->path, '/');
        }

        return rtrim($workspace->path, '/').'/'.$root;
    }

    /**
     * @return array{0: Node, 1: array<string, mixed>, 2: string}
     */
    private function routeArtifact(Workspace $workspace, App $app, Node $node, string $domain): array
    {
        $isPhp = $app->runtimeKind() === AppRuntimeKind::Php;
        $runtimeUpstream = $isPhp ? $this->runtimeContainerRenderer->upstreamUrl($workspace) : null;

        if ($app->environment !== 'production') {
            $certificatePaths = $this->caddyContainerCertificatePaths($domain);
            $config = [
                'document_root' => $this->documentRoot($workspace, $app),
                'runtime_upstream' => $runtimeUpstream,
                'php_socket' => null,
                'tls' => [
                    'cert_path' => $certificatePaths['cert'],
                    'key_path' => $certificatePaths['key'],
                ],
            ];

            if ($this->innerTlsPolicy->appliesToWorkspace($workspace)) {
                $config['runtime_upstream_tls'] = $this->innerTlsPolicy->runtimeUpstreamTlsConfig($node, $domain);
            }

            return [$node, $config, $this->renderCaddySite($workspace, $app, $domain, $config)];
        }

        $ingressNode = $this->ingressResolver->forAppNode($node);
        $routerNode = $this->ingressResolver->router();
        $certificatePaths = $this->caddyContainerCertificatePaths($domain);
        $backendArtifact = [
            'node_id' => $node->id,
            'domain' => $domain,
            'bind' => $node->wireguard_address,
            'document_root' => $this->documentRoot($workspace, $app),
            'runtime_upstream' => $runtimeUpstream,
            'php_socket' => null,
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
                    'node_id' => $node->id,
                    'node' => $node->name,
                    'url' => $this->ingressResolver->backendUrl($node),
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
            'workspace_id' => $workspace->id,
            'owner_type' => 'workspace',
            'kind' => 'workspace',
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
            'workspace_id' => $workspace->id,
            'owner_type' => 'workspace',
            'kind' => 'workspace',
            'config' => $config,
        ]));

        $backendArtifact['source_hash'] = hash('sha256', $this->proxyRouteRenderer->renderPrivateBackend(
            new ProxyRoute([
                'domain' => $domain,
                'app_id' => $app->id,
                'workspace_id' => $workspace->id,
                'owner_type' => 'workspace',
                'kind' => 'workspace',
                'config' => ['placement' => 'ingress'],
            ]),
            $backendArtifact,
        ));
        $config['backend_artifacts'] = [$backendArtifact];

        return [$ingressNode, $config, $content];
    }

    /**
     * @return array{cert: string, key: string}
     */
    private function caddyContainerCertificatePaths(string $domain): array
    {
        return [
            'cert' => "/etc/orbit/certs/{$domain}.crt",
            'key' => "/etc/orbit/certs/{$domain}.key",
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function runtimeUpstreamTransportDirectives(array $config): string
    {
        $runtimeUpstreamTls = $config['runtime_upstream_tls'] ?? null;

        if (! is_array($runtimeUpstreamTls)) {
            return '';
        }

        return $this->innerTlsPolicy->runtimeUpstreamTransportDirectives($runtimeUpstreamTls);
    }
}
