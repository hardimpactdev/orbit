<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Contracts\SiteCertificateInstaller;
use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Services\Apps\AppDevelopmentInnerTlsPolicy;
use App\Services\Apps\AppOwningNodeResolver;
use App\Services\Apps\AppRuntimeContainerRenderer;
use App\Services\Ca\OrbitCaService;
use App\Services\Convergence\ManagedFile;
use App\Services\Proxy\AppProxyRouteCaddyInstaller;
use App\Services\Proxy\CaddyContainerHostPathResolver;
use App\Services\Proxy\IngressResolver;
use App\Services\Proxy\ProxyRouteEnactment;
use App\Services\Proxy\ProxyRouteRenderer;
use Closure;
use RuntimeException;

/**
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-methods
 */
final readonly class EnsureAppProxyRoute
{
    public function __construct(
        private SiteCertificateInstaller $siteCertificateInstaller,
        private AppProxyRouteCaddyInstaller $caddyInstaller,
        private IngressResolver $ingressResolver,
        private ProxyRouteRenderer $proxyRouteRenderer,
        private AppRuntimeContainerRenderer $appRuntimeContainerRenderer,
        private OrbitCaService $ca,
        private AppOwningNodeResolver $appOwningNodeResolver,
        private AppDevelopmentInnerTlsPolicy $innerTlsPolicy = new AppDevelopmentInnerTlsPolicy,
        private ?CaddyContainerHostPathResolver $caddyHostPathResolver = null,
    ) {}

    /**
     * @return list<array<string, string>>
     */
    public function handle(App $app): array
    {
        $owningNode = $this->appOwningNodeResolver->resolve($app);

        $domain = $this->domain($app, $owningNode);
        [$servingNode, $config, $content] = $this->routeArtifact($app, $owningNode, $domain);

        $route = ProxyRoute::query()->updateOrCreate(
            ['domain' => $domain],
            [
                'node_id' => $servingNode->id,
                'app_id' => $app->id,
                'owner_type' => 'app',
                'kind' => 'app',
                'config' => ProxyRouteEnactment::pending(
                    $config,
                    $this->plannedOperations($config, $servingNode, $owningNode),
                ),
                'source_hash' => hash('sha256', $content),
            ],
        );
        ProxyRoute::query()
            ->where('app_id', $app->id)
            ->where('owner_type', 'app')
            ->where('kind', 'app')
            ->where('domain', '!=', $domain)
            ->delete();

        if (($config['placement'] ?? null) === 'ingress') {
            $warning = $this->enactProductionRoute(
                route: $route,
                app: $app,
                owningNode: $owningNode,
                servingNode: $servingNode,
                domain: $domain,
                config: $config,
                ingressContent: $content,
            );
        } else {
            $warning = $this->enactSingleNodeRoute(
                route: $route,
                node: $servingNode,
                domain: $domain,
                config: $config,
                content: $content,
            );
        }

        if ($warning !== null) {
            return [$warning];
        }

        $this->storeConfig($route, ProxyRouteEnactment::converged($this->routeConfig($route)));

        return $this->productionActivationWarnings($app);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, string>|null
     */
    private function enactSingleNodeRoute(
        ProxyRoute $route,
        Node $node,
        string $domain,
        array $config,
        string $content,
    ): ?array {
        foreach ([
            [
                'operation' => 'site_certificate.ensure',
                'callback' => fn (): bool => $this->ensureSiteCertificate($node, $domain),
            ],
            ...$this->runtimeTrustOperation($node, $config, 'route'),
            [
                'operation' => 'caddy.global.ensure',
                'callback' => fn (): bool => $this->ensureGlobalCaddyfile($node),
            ],
            [
                'operation' => 'caddy.route.install',
                'callback' => fn (): bool => $this->caddyInstaller
                    ->installRouteConfig(
                        $node,
                        $domain,
                        $content,
                    )
                    ->successful(),
            ],
        ] as $step) {
            $warning = $this->performOperation(
                route: $route,
                node: $node,
                layer: 'route',
                operation: $step['operation'],
                callback: $step['callback'],
            );

            if ($warning !== null) {
                return $warning;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, string>|null
     *
     * @mago-expect lint:excessive-parameter-list
     */
    private function enactProductionRoute(
        ProxyRoute $route,
        App $app,
        Node $owningNode,
        Node $servingNode,
        string $domain,
        array $config,
        string $ingressContent,
    ): ?array {
        $routerNode = $this->routerNode($config, $domain);
        $backendArtifact = $this->backendArtifact($config, $domain);
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
        $routerContent = $this->proxyRouteRenderer->renderRouterRoute(new ProxyRoute([
            'node_id' => $routerNode->id,
            'domain' => $domain,
            'app_id' => $app->id,
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => $config,
        ]));
        $steps = [
            [
                'node' => $owningNode,
                'layer' => 'backend',
                'operation' => 'caddy.global.ensure',
                'callback' => fn (): bool => $this->ensureGlobalCaddyfile($owningNode),
            ],
            ...$this->runtimeTrustOperation($owningNode, $config, 'backend'),
            [
                'node' => $owningNode,
                'layer' => 'backend',
                'operation' => 'caddy.backend.install',
                'callback' => fn (): bool => $this->caddyInstaller
                    ->installRouteConfig(
                        $owningNode,
                        $domain,
                        $backendContent,
                        backend: true,
                    )
                    ->successful(),
            ],
            [
                'node' => $routerNode,
                'layer' => 'router',
                'operation' => 'caddy.global.ensure',
                'callback' => fn (): bool => $this->ensureGlobalCaddyfile($routerNode),
            ],
            [
                'node' => $routerNode,
                'layer' => 'router',
                'operation' => 'caddy.router.install',
                'callback' => fn (): bool => $this->caddyInstaller
                    ->installRouteConfig(
                        $routerNode,
                        $domain,
                        $routerContent,
                    )
                    ->successful(),
            ],
            [
                'node' => $servingNode,
                'layer' => 'ingress',
                'operation' => 'site_certificate.ensure',
                'callback' => fn (): bool => $this->ensureSiteCertificate($servingNode, $domain),
            ],
            ...$this->runtimeTrustOperation($servingNode, $config, 'ingress'),
            [
                'node' => $servingNode,
                'layer' => 'ingress',
                'operation' => 'caddy.global.ensure',
                'callback' => fn (): bool => $this->ensureGlobalCaddyfile($servingNode),
            ],
            [
                'node' => $servingNode,
                'layer' => 'ingress',
                'operation' => 'caddy.ingress.install',
                'callback' => fn (): bool => $this->caddyInstaller
                    ->installRouteConfig(
                        $servingNode,
                        $domain,
                        $ingressContent,
                    )
                    ->successful(),
            ],
        ];

        foreach ($steps as $step) {
            $warning = $this->performOperation(
                route: $route,
                node: $step['node'],
                layer: $step['layer'],
                operation: $step['operation'],
                callback: $step['callback'],
            );

            if ($warning !== null) {
                return $warning;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>|null
     */
    private function performOperation(
        ProxyRoute $route,
        Node $node,
        string $layer,
        string $operation,
        Closure $callback,
    ): ?array {
        $record = [
            'layer' => $layer,
            'node' => $node->name,
            'operation' => $operation,
        ];

        try {
            $successful = $callback();
        } catch (\Throwable) {
            $successful = false;
        }

        if ($successful) {
            $this->storeConfig(
                $route,
                ProxyRouteEnactment::completed($this->routeConfig($route), $record),
            );

            return null;
        }

        $this->storeConfig(
            $route,
            ProxyRouteEnactment::failed($this->routeConfig($route), $record),
        );

        return [
            'code' => 'proxy.enactment_failed',
            'family' => 'proxy',
            'layer' => $layer,
            'node' => $node->name,
            'operation' => $operation,
            'message' => "Proxy route '{$route->domain}' failed on node '{$node->name}' during '{$operation}'. Run doctor to converge proxy artifacts.",
            'next_command' => 'doctor --family=proxy --restore',
        ];
    }

    private function ensureSiteCertificate(Node $node, string $domain): bool
    {
        $this->siteCertificateInstaller->ensureFor($node, $domain);

        return true;
    }

    private function ensureGlobalCaddyfile(Node $node): bool
    {
        $this->caddyInstaller->ensureGlobalCaddyfile($node);

        return true;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<array{node: Node, layer: string, operation: string, callback: Closure(): bool}>
     */
    private function runtimeTrustOperation(Node $node, array $config, string $layer): array
    {
        if (! $this->requiresRuntimeTrustPool($config)) {
            return [];
        }

        return [[
            'node' => $node,
            'layer' => $layer,
            'operation' => 'runtime_trust_pool.ensure',
            'callback' => function () use ($node, $config): bool {
                $this->ensureRuntimeTrustPool($node, $config);

                return true;
            },
        ]];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function requiresRuntimeTrustPool(array $config): bool
    {
        $runtimeUpstreamTls = $config['runtime_upstream_tls'] ?? null;

        return is_array($runtimeUpstreamTls) && ($runtimeUpstreamTls['trusted_by_gateway_ca'] ?? null) === true;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<array{layer: string, node: string, operation: string}>
     */
    private function plannedOperations(array $config, Node $servingNode, Node $owningNode): array
    {
        if (($config['placement'] ?? null) !== 'ingress') {
            return $this->plannedNodeOperations(
                node: $servingNode,
                layer: 'route',
                operations: [
                    'site_certificate.ensure',
                    ...($this->requiresRuntimeTrustPool($config) ? ['runtime_trust_pool.ensure'] : []),
                    'caddy.global.ensure',
                    'caddy.route.install',
                ],
            );
        }

        $routerNode = $this->routerNode($config, 'production route');

        return [
            ...$this->plannedNodeOperations(
                node: $owningNode,
                layer: 'backend',
                operations: [
                    'caddy.global.ensure',
                    ...($this->requiresRuntimeTrustPool($config) ? ['runtime_trust_pool.ensure'] : []),
                    'caddy.backend.install',
                ],
            ),
            ...$this->plannedNodeOperations(
                node: $routerNode,
                layer: 'router',
                operations: ['caddy.global.ensure', 'caddy.router.install'],
            ),
            ...$this->plannedNodeOperations(
                node: $servingNode,
                layer: 'ingress',
                operations: [
                    'site_certificate.ensure',
                    ...($this->requiresRuntimeTrustPool($config) ? ['runtime_trust_pool.ensure'] : []),
                    'caddy.global.ensure',
                    'caddy.ingress.install',
                ],
            ),
        ];
    }

    /**
     * @param  list<string>  $operations
     * @return list<array{layer: string, node: string, operation: string}>
     */
    private function plannedNodeOperations(Node $node, string $layer, array $operations): array
    {
        return array_map(
            static fn (string $operation): array => [
                'layer' => $layer,
                'node' => $node->name,
                'operation' => $operation,
            ],
            $operations,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function routerNode(array $config, string $domain): Node
    {
        $routerArtifact = $config['router_artifact'] ?? null;

        if (! is_array($routerArtifact)) {
            throw new RuntimeException("Proxy route '{$domain}' is missing a router artifact.");
        }

        $routerNodeId = $routerArtifact['node_id'] ?? null;
        $routerNode = is_int($routerNodeId) ? Node::query()->find($routerNodeId) : null;

        if (! $routerNode instanceof Node) {
            throw new RuntimeException("Proxy route '{$domain}' points at an unavailable router node.");
        }

        return $routerNode;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function backendArtifact(array $config, string $domain): array
    {
        $backendArtifact = $config['backend_artifacts'][0] ?? null;

        if (! is_array($backendArtifact)) {
            throw new RuntimeException("Proxy route '{$domain}' is missing a backend artifact.");
        }

        $artifact = [];

        foreach ($backendArtifact as $key => $value) {
            if (! is_string($key)) {
                throw new RuntimeException("Proxy route '{$domain}' has an invalid backend artifact.");
            }

            $artifact[$key] = $value;
        }

        return $artifact;
    }

    /**
     * @return array<string, mixed>
     */
    private function routeConfig(ProxyRoute $route): array
    {
        return is_array($route->config) ? $route->config : [];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function storeConfig(ProxyRoute $route, array $config): void
    {
        $route->forceFill(['config' => $config])->save();
        $route->setAttribute('config', $config);
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
     *     runtime_upstream?: string|null,
     *     php_socket?: string|null,
     *     tls: array{cert_path: string, key_path: string},
     * }  $config
     */
    private function renderCaddySite(App $app, string $domain, array $config): string
    {
        $pathBlocking = $app->document_root === '.'
            ? 'import path_blocking_project_root'
            : 'import path_blocking_public_root';

        if ($app->runtimeKind() === AppRuntimeKind::Php) {
            $upstream = $config['runtime_upstream'] ?? null;

            if (! is_string($upstream) || $upstream === '') {
                throw new RuntimeException("App '{$app->name}' route is missing a runtime container upstream.");
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
            path: $this->caddyHostPathResolver()->resolve($node, $caPath),
            content: $this->ca->rootCert(),
            mode: '0644',
            directoryMode: '0755',
        );
        $plan = $file->plan($file->probe($node));
        $result = $file->apply($node, $plan);

        if (! $result->successful()) {
            throw new RuntimeException($result->summary);
        }
    }

    private function caddyHostPathResolver(): CaddyContainerHostPathResolver
    {
        return $this->caddyHostPathResolver ?? app(CaddyContainerHostPathResolver::class);
    }

    private function domain(App $app, Node $owningNode): string
    {
        if (is_string($app->domain) && $app->domain !== '') {
            return $app->domain;
        }

        $tld = is_string($owningNode->tld) ? trim($owningNode->tld, '.') : '';

        if ($tld === '') {
            return $app->name;
        }

        return "{$app->name}.{$tld}";
    }

    /**
     * @return array{0: Node, 1: array<string, mixed>, 2: string}
     */
    private function routeArtifact(App $app, Node $owningNode, string $domain): array
    {
        $isPhp = $app->runtimeKind() === AppRuntimeKind::Php;
        $runtimeUpstream = $isPhp ? $this->appRuntimeContainerRenderer->upstreamUrl($app) : null;

        if ($app->environment !== 'production') {
            $certificatePaths = $this->siteCertificatePaths($owningNode, $domain);
            $config = [
                'document_root' => $app->documentRootPath(),
                'runtime_upstream' => $runtimeUpstream,
                'php_socket' => null,
                'tls' => [
                    'cert_path' => $certificatePaths['cert'],
                    'key_path' => $certificatePaths['key'],
                ],
            ];

            if ($this->innerTlsPolicy->appliesToApp($app)) {
                $config['runtime_upstream_tls'] = $this->innerTlsPolicy->runtimeUpstreamTlsConfig($owningNode, $domain);
            }

            return [$owningNode, $config, $this->renderCaddySite($app, $domain, $config)];
        }

        $ingressNode = $this->ingressResolver->forAppNode($owningNode);
        $routerNode = $this->ingressResolver->router();
        $certificatePaths = $this->siteCertificatePaths($ingressNode, $domain);
        $backendArtifact = [
            'node_id' => $owningNode->id,
            'domain' => $domain,
            'bind' => $owningNode->wireguard_address,
            'document_root' => $app->documentRootPath(),
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
                    'node_id' => $owningNode->id,
                    'node' => $owningNode->name,
                    'url' => $this->ingressResolver->backendUrl($owningNode),
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

    /**
     * @return array{cert: string, key: string}
     */
    private function siteCertificatePaths(Node $node, string $domain): array
    {
        return $this->siteCertificateInstaller->expectedPathsFor($node, $domain);
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
