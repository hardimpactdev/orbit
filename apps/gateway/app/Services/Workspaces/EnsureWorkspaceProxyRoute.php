<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Contracts\SiteCertificateInstaller;
use App\Enums\Apps\AppRuntimeKind;
use App\Models\Node;
use App\Models\Project;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Services\Apps\AppDevelopmentInnerTlsPolicy;
use App\Services\Ca\OrbitCaService;
use App\Services\Convergence\ManagedFile;
use App\Services\Proxy\AppProxyRouteCaddyInstaller;
use App\Services\Proxy\CaddyContainerHostPathResolver;
use RuntimeException;
use Throwable;

final readonly class EnsureWorkspaceProxyRoute
{
    public function __construct(
        private WorkspaceRuntimeContainerRenderer $runtimeContainerRenderer,
        private SiteCertificateInstaller $siteCertificateInstaller,
        private AppProxyRouteCaddyInstaller $caddyInstaller,
        private OrbitCaService $ca,
        private WorkspaceRoleGuard $roleGuard,
        private WorkspacePlacement $placement = new WorkspacePlacement,
        private AppDevelopmentInnerTlsPolicy $innerTlsPolicy = new AppDevelopmentInnerTlsPolicy,
        private ?CaddyContainerHostPathResolver $caddyHostPathResolver = null,
    ) {}

    /**
     * @return list<array<string, string>>
     */
    public function handle(Workspace $workspace): array
    {
        $workspace->loadMissing(['app', 'app.node', 'app.instances', 'appInstance']);

        $app = $workspace->app;

        if (! $app instanceof Project) {
            throw new RuntimeException("Workspace '{$workspace->name}' has no parent project.");
        }

        $node = $this->placement->nodeForWorkspace($workspace);

        if (! $node instanceof Node) {
            throw new RuntimeException("App '{$app->name}' has no owning node.");
        }

        $this->roleGuard->ensureNodeSupportsWorkspaces($app, $node);

        $domain = $this->domain($workspace);
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
    private function renderCaddySite(Workspace $workspace, Project $app, string $domain, array $config): string
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

    private function domain(Workspace $workspace): string
    {
        return $this->placement->workspaceDomain($workspace);
    }

    private function documentRoot(Workspace $workspace, Project $app): string
    {
        $root = trim($this->placement->documentRootForWorkspace($workspace), '/');

        if ($root === '') {
            return rtrim($workspace->path, '/');
        }

        return rtrim($workspace->path, '/').'/'.$root;
    }

    /**
     * @return array{0: Node, 1: array<string, mixed>, 2: string}
     */
    private function routeArtifact(Workspace $workspace, Project $app, Node $node, string $domain): array
    {
        $isPhp = $app->runtimeKind() === AppRuntimeKind::Php;
        $runtimeUpstream = $isPhp ? $this->runtimeContainerRenderer->upstreamUrl($workspace) : null;
        $certificatePaths = $this->siteCertificatePaths($node, $domain);
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
