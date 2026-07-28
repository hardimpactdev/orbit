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
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Proxy\AppProxyRouteCaddyInstaller;
use App\Services\Proxy\CaddyContainerHostPathResolver;
use App\Services\Proxy\ProxyRouteRenderer;
use RuntimeException;
use Throwable;

final readonly class EnsureWorkspaceProxyRoute
{
    public function __construct(
        private WorkspaceRuntimeContainerRenderer $runtimeContainerRenderer,
        private SiteCertificateInstaller $siteCertificateInstaller,
        private AppProxyRouteCaddyInstaller $caddyInstaller,
        private ProxyRouteRenderer $proxyRouteRenderer,
        private OrbitCaService $ca,
        private WorkspaceRoleGuard $roleGuard,
        private WorkspacePlacement $placement = new WorkspacePlacement,
        private NodeRoleAssignments $nodeRoleAssignments = new NodeRoleAssignments,
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

        $route = ProxyRoute::query()->updateOrCreate(
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
        $content = $this->proxyRouteRenderer->render($route);
        $route->forceFill(['source_hash' => hash('sha256', $content)])->save();

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
     * @param  array<string, mixed>  $config
     */
    private function ensureRuntimeTrustPool(Node $node, array $config): void
    {
        $runtimeUpstreamTls = $config['runtime_upstream_tls'] ?? null;
        $hasDevelopmentActivation = $node->hasActiveRole('app-dev') && $this->nodeRoleAssignments
            ->activeGatewayNodeQuery()
            ->whereNotNull('wireguard_address')
            ->exists();

        if (
            ! $hasDevelopmentActivation
            && (! is_array($runtimeUpstreamTls)
            || ($runtimeUpstreamTls['trusted_by_gateway_ca'] ?? null) !== true)
        ) {
            return;
        }

        $caPath =
            is_array($runtimeUpstreamTls)
            && is_string($runtimeUpstreamTls['ca_path'] ?? null)
            && $runtimeUpstreamTls['ca_path'] !== ''
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

        $content = $this->proxyRouteRenderer->render(new ProxyRoute([
            'node_id' => $node->id,
            'domain' => $domain,
            'app_id' => $app->id,
            'workspace_id' => $workspace->id,
            'owner_type' => 'workspace',
            'kind' => 'workspace',
            'config' => $config,
        ]));

        return [$node, $config, $content];
    }

    /**
     * @return array{cert: string, key: string}
     */
    private function siteCertificatePaths(Node $node, string $domain): array
    {
        return $this->siteCertificateInstaller->expectedPathsFor($node, $domain);
    }
}
