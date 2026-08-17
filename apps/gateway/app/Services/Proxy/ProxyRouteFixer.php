<?php

declare(strict_types=1);

namespace App\Services\Proxy;

use App\Actions\Apps\EnsureAppProxyRoute;
use App\Contracts\SiteCertificateInstaller;
use App\Data\Doctor\DriftEntry;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\DriftKind;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Services\Apps\AppDevelopmentInnerTlsPolicy;
use App\Services\Ca\OrbitCaService;
use App\Services\Convergence\ManagedFile;
use App\Services\Doctor\DoctorRestoreActionId;
use App\Services\Gateway\CaddyGlobalConfig;
use App\Services\Gateway\CaddyGlobalSiteBlocks;
use App\Services\Nodes\NodeContainerScope;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\RemoteShell\RunsInternalCommands;
use App\Services\Runtime\OrbitContainerNames;
use Closure;
use RuntimeException;

final readonly class ProxyRouteFixer
{
    public function __construct(
        private ProxyRouteRenderer $renderer,
        private OrbitCaService $ca,
        private SiteCertificateInstaller $siteCertificateInstaller,
        private ?RemoteCaddyConfig $caddyConfig = null,
        private ?Closure $appRouteEnactor = null,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function restoreAgentToolRoute(Node $node, DriftEntry $entry): ?array
    {
        if (! in_array($entry->key, ['proxy.agent_tool_route_missing', 'proxy.agent_tool_route_mismatch'], true)) {
            return null;
        }

        $tool = is_string($entry->detail['tool'] ?? null) ? $entry->detail['tool'] : null;

        if ($tool === null) {
            return null;
        }

        $expected = app(AgentToolProxyRouteIntent::class)->expectedRouteFor($node, $tool);

        if (! $expected instanceof ProxyRoute) {
            return null;
        }

        $route = app(AgentToolProxyRouteIntent::class)->persist($expected);

        if (! $route instanceof ProxyRoute) {
            return null;
        }

        $fixed = $this->fix($route, new DriftEntry(
            family: 'proxy',
            key: 'proxy.route_mismatch',
            kind: DriftKind::Divergent,
            summary: "Agent tool proxy route {$route->domain} requires canonical re-application.",
        ));

        if ($fixed === null) {
            return null;
        }

        return [
            'family' => 'proxy',
            'node' => $node->name,
            'code' => $entry->key,
            'key' => $entry->key,
            'mode' => 'restore',
            'status' => 'completed',
            'summary' => "Restored expected agent tool proxy route {$route->domain} from gateway intent.",
            'details' => [
                'route' => $route->domain,
                'tool' => $tool,
            ],
        ];
    }

    /**
     * Codes ProxyRouteFixer methods can restore (route fix, caddy, global, agent tool, extra).
     * Specialized websocket/s3/analytics probes own their own support maps.
     *
     * @return array<string, string> code => restore_action
     */
    public static function restoreSupport(): array
    {
        return DoctorRestoreActionId::map([
            'proxy.route_missing',
            'proxy.route_mismatch',
            'proxy.route_extra',
            'proxy.public_route_missing',
            'proxy.public_route_mismatch',
            'proxy.router_route_missing',
            'proxy.router_route_mismatch',
            'proxy.backend_route_missing',
            'proxy.backend_route_mismatch',
            'proxy.tls_missing',
            'proxy.tls_mismatch',
            'proxy.enactment_incomplete',
            'proxy.caddy_container_missing',
            'proxy.caddy_container_down',
            'proxy.caddy_container_detached',
            'proxy.global_config_missing',
            'proxy.global_config_mismatch',
            'proxy.agent_tool_route_missing',
            'proxy.agent_tool_route_mismatch',
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fix(ProxyRoute $route, DriftEntry $entry): ?array
    {
        if (! in_array(
            $entry->key,
            [
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
                'proxy.enactment_incomplete',
            ],
            true,
        )) {
            return null;
        }

        if ($route->owner_type === 'instance') {
            return null;
        }

        $route->loadMissing('node');

        if (
            NonInstanceProxyRouteOwnership::supports($route->owner_type)
            && ! app(NonInstanceProxyRouteOwnership::class)->matches($route)
        ) {
            return null;
        }

        if ($route->owner_type === 'workspace' || $route->kind === 'workspace') {
            new WorkspaceProxyRouteOwnershipResolver()->resolveOrFail($route);
        }

        if (
            InstanceProxyRouteOwnershipResolver::isDirectOwner($route->owner_type)
            && app(InstanceProxyRouteOwnershipResolver::class)->resolve($route) === null
        ) {
            return null;
        }

        if (
            InstanceProxyRouteOwnershipResolver::isPublicBindingOwner($route->owner_type)
            && ! app(PublicBindingProxyRouteOwnership::class)->matches($route)
        ) {
            return null;
        }

        if (! in_array($route->kind, ['app', 'workspace', 'proxy', 'redirect'], true)) {
            return null;
        }

        if ($entry->key === 'proxy.enactment_incomplete') {
            if ($route->owner_type === 'custom') {
                return $this->reenactCustomRoute($route, $entry);
            }

            return $this->reenactAppRoute($route, $entry);
        }

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

        $this->normalizeOwnedRouteTlsPaths($route);

        $content = $this->renderer->renderManagedPhpRuntimeIntent($route);

        if ($route->owner_type === 'router') {
            $this->ensureRouterTrustPool($route->node, $route);
        }

        $this->ensureRouteTlsMaterial($route);
        $this->ensureRuntimeTrustPool($route->node, $route);

        $this->writeSiteAndReload($route->node, $route->domain, $content);

        $route->forceFill([
            'config' => $route->config,
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
    private function reenactCustomRoute(ProxyRoute $route, DriftEntry $entry): ?array
    {
        $applied = $this->fix(
            $route,
            new DriftEntry(
                family: 'proxy',
                key: 'proxy.route_mismatch',
                kind: DriftKind::Divergent,
                summary: "Custom proxy route {$route->domain} requires re-enactment.",
            ),
        );

        if ($applied === null) {
            return null;
        }

        $route->refresh();
        $config = is_array($route->config) ? $route->config : [];
        $route->forceFill([
            'config' => ProxyRouteEnactment::converged($config),
        ])->save();

        return [
            'family' => 'proxy',
            'node' => $route->node->name,
            'code' => $entry->key,
            'key' => $entry->key,
            'mode' => 'fix',
            'status' => 'completed',
            'summary' => "Re-enacted custom proxy route {$route->domain} from gateway intent.",
            'details' => [
                'route' => $route->domain,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function reenactAppRoute(ProxyRoute $route, DriftEntry $entry): ?array
    {
        $route->loadMissing(['node', 'instance.app']);
        $instance = $this->appRouteTargets()->instanceForRoute($route);
        $app = $this->appRouteTargets()->appForRoute($route, $instance);

        if (! $instance instanceof Instance || ! $app instanceof App) {
            return null;
        }

        $this->executeAppRouteEnactment($app, $instance);

        $route->refresh();
        $config = is_array($route->config) ? $route->config : [];

        if (ProxyRouteEnactment::status($config) !== ProxyRouteEnactment::CONVERGED) {
            throw new RuntimeException($this->enactmentFailureMessage($route, $config));
        }

        return [
            'family' => 'proxy',
            'node' => $route->node->name,
            'code' => $entry->key,
            'key' => $entry->key,
            'mode' => 'fix',
            'status' => 'completed',
            'summary' => "Re-enacted proxy route {$route->domain} across its complete topology.",
            'details' => [
                'route' => $route->domain,
            ],
        ];
    }

    private function executeAppRouteEnactment(App $app, Instance $instance): void
    {
        if ($this->appRouteEnactor instanceof Closure) {
            ($this->appRouteEnactor)($app, $instance);

            return;
        }

        app(EnsureAppProxyRoute::class)->handle($app, $instance);
    }

    private function appRouteTargets(): AppProxyRouteTargetResolver
    {
        return app(AppProxyRouteTargetResolver::class);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function enactmentFailureMessage(ProxyRoute $route, array $config): string
    {
        $enactment = is_array($config['enactment'] ?? null) ? $config['enactment'] : [];
        $failure = is_array($enactment['failure'] ?? null) ? $enactment['failure'] : [];
        $node = is_string($failure['node'] ?? null) ? $failure['node'] : null;
        $operation = is_string($failure['operation'] ?? null) ? $failure['operation'] : null;

        if ($node !== null && $operation !== null) {
            return "Proxy route '{$route->domain}' failed on node '{$node}' during '{$operation}'.";
        }

        return "Proxy route '{$route->domain}' did not converge during full re-enactment.";
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
        $this->ensureRouterTrustPool($routerNode, $route);
        $this->writeSiteAndReload($routerNode, $route->domain, $content);

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
        $this->ensureRuntimeTrustPool($backendNode, $route);
        $this->writeSiteAndReload($backendNode, $route->domain, $content, backend: true);

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
        if ($this->repairOwnedRouteTls($route)) {
            return;
        }

        $leaf = $this->ca->issueLeaf($route->domain);
        $paths = $this->tlsPaths($route);
        $hostPaths = $this->hostPathsForCaddyContainer($route->node, $paths);

        $this->applyManagedFile($route->node, new ManagedFile(
            path: $hostPaths['cert'],
            content: (string) file_get_contents($leaf['cert']),
            mode: '0644',
            directoryMode: '0755',
            localExecutor: app(RunsInternalCommands::class),
        ));
        $this->applyManagedFile($route->node, new ManagedFile(
            path: $hostPaths['key'],
            content: (string) file_get_contents($leaf['key']),
            mode: '0600',
            directoryMode: '0755',
            sensitive: true,
            localExecutor: app(RunsInternalCommands::class),
        ));
        $this->reloadCaddy($route->node);
    }

    private function repairOwnedRouteTls(ProxyRoute $route): bool
    {
        if (! $this->ensureSiteCertificateForOwnedPhpRoute($route)) {
            return false;
        }

        $this->normalizeOwnedRouteTlsPaths($route);
        $content = $this->renderer->renderManagedPhpRuntimeIntent($route);

        $this->ensureRuntimeTrustPool($route->node, $route);
        $this->writeSiteAndReload($route->node, $route->domain, $content);

        $route->forceFill([
            'config' => $route->config,
            'source_hash' => hash('sha256', $content),
        ])->save();

        return true;
    }

    private function ensureSiteCertificateForOwnedPhpRoute(ProxyRoute $route): bool
    {
        if (! in_array($route->kind, ['app', 'workspace'], true)) {
            return false;
        }

        $this->siteCertificateInstaller->ensureFor($route->node, $route->domain);

        return true;
    }

    private function ensureRouteTlsMaterial(ProxyRoute $route): void
    {
        if ($this->ensureSiteCertificateForOwnedPhpRoute($route)) {
            return;
        }

        if (! $this->expectsOrbitManagedTls($route)) {
            return;
        }

        $this->repairTls($route);
    }

    private function normalizeOwnedRouteTlsPaths(ProxyRoute $route): void
    {
        if (! in_array($route->kind, ['app', 'workspace'], true)) {
            return;
        }

        if (! $this->usesOrbitManagedTls($route)) {
            return;
        }

        $config = is_array($route->config) ? $route->config : [];
        $tls = is_array($config['tls'] ?? null) ? $config['tls'] : [];

        $paths = $this->siteCertificateInstaller->expectedPathsFor($route->node, $route->domain);

        $tls['cert_path'] = $paths['cert'];
        $tls['key_path'] = $paths['key'];
        $config['tls'] = $tls;
        $route->config = $config;
    }

    private function usesOrbitManagedTls(ProxyRoute $route): bool
    {
        $config = is_array($route->config) ? $route->config : [];
        $tls = $config['tls'] ?? null;

        if ($tls === 'internal') {
            return false;
        }

        $managedBy = is_array($tls)
            ? $tls['managed_by'] ?? $config['tls_managed_by'] ?? 'orbit'
            : $config['tls_managed_by'] ?? 'orbit';

        return $managedBy === 'orbit';
    }

    private function expectsOrbitManagedTls(ProxyRoute $route): bool
    {
        if ($this->usesIngressPlacement($route)) {
            return false;
        }

        $config = is_array($route->config) ? $route->config : [];
        $tls = $config['tls'] ?? null;

        if ($tls === 'internal') {
            return false;
        }

        $managedBy = is_array($tls)
            ? $tls['managed_by'] ?? $config['tls_managed_by'] ?? 'orbit'
            : $config['tls_managed_by'] ?? 'orbit';

        return in_array($managedBy, ['orbit', 'internal'], true);
    }

    private function usesIngressPlacement(ProxyRoute $route): bool
    {
        $config = is_array($route->config) ? $route->config : [];

        return ($config['placement'] ?? null) === 'ingress';
    }

    private function ensureRouterTrustPool(Node $node, ProxyRoute $route): void
    {
        $config = is_array($route->config) ? $route->config : [];
        $backendTls = $config['router_backend_tls'] ?? null;

        if (! is_array($backendTls) || ($backendTls['trusted_by_gateway_ca'] ?? null) !== true) {
            return;
        }

        $caPath = is_string($backendTls['ca_path'] ?? null) && $backendTls['ca_path'] !== ''
            ? $backendTls['ca_path']
            : '/etc/orbit/ca/root.crt';

        $this->installTrustPool($node, $route, $caPath);
    }

    private function ensureRuntimeTrustPool(Node $node, ProxyRoute $route): void
    {
        $config = is_array($route->config) ? $route->config : [];
        $runtimeUpstreamTls = $config['runtime_upstream_tls'] ?? null;
        $instance = $config['instance'] ?? null;
        $isDevelopmentRuntimeRoute = $node->hasActiveRole('app-dev') && app(NodeRoleAssignments::class)
            ->activeGatewayNodeQuery()
            ->whereNotNull('wireguard_address')
            ->exists() && ($route->kind === 'workspace' || $route->kind === 'app' && is_array($instance) && is_int($instance['id'] ?? null));

        if (
            ! $isDevelopmentRuntimeRoute
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

        $this->installTrustPool($node, $route, $caPath);
    }

    private function installTrustPool(Node $node, ProxyRoute $route, string $caPath): void
    {
        $caPath = $this->validatedAbsolutePath($route, $caPath, 'has an invalid router backend CA path.');

        $file = new ManagedFile(
            path: $this->hostPathForCaddyContainerPath($node, $caPath),
            content: $this->ca->rootCert(),
            mode: '0644',
            directoryMode: '0755',
            localExecutor: app(RunsInternalCommands::class),
        );
        $this->applyManagedFile($node, $file);
    }

    private function applyManagedFile(Node $node, ManagedFile $file): void
    {
        $plan = $file->plan($file->probe($node));
        $result = $file->apply($node, $plan);

        if (! $result->successful()) {
            throw new RuntimeException($result->summary);
        }
    }

    private function publicRouteSummary(ProxyRoute $route, DriftEntry $entry): string
    {
        if (in_array($entry->key, ['proxy.public_route_missing', 'proxy.public_route_mismatch'], strict: true)) {
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

    /**
     * @return array{cert: string, key: string}
     */
    private function tlsPaths(ProxyRoute $route): array
    {
        $config = is_array($route->config) ? $route->config : [];
        $tls = is_array($config['tls'] ?? null) ? $config['tls'] : [];
        $cert = $tls['cert_path'] ?? null;
        $key = $tls['key_path'] ?? null;

        return [
            'cert' => $this->validatedAbsolutePath(
                $route,
                is_string($cert) && $cert !== '' ? $cert : "/etc/orbit/certs/{$route->domain}.crt",
                'has an invalid TLS cert path.',
            ),
            'key' => $this->validatedAbsolutePath(
                $route,
                is_string($key) && $key !== '' ? $key : "/etc/orbit/certs/{$route->domain}.key",
                'has an invalid TLS key path.',
            ),
        ];
    }

    /**
     * @param  array{cert: string, key: string}  $paths
     * @return array{cert: string, key: string}
     */
    private function hostPathsForCaddyContainer(Node $node, array $paths): array
    {
        return [
            'cert' => $this->hostPathForCaddyContainerPath($node, $paths['cert']),
            'key' => $this->hostPathForCaddyContainerPath($node, $paths['key']),
        ];
    }

    private function hostPathForCaddyContainerPath(Node $node, string $containerPath): string
    {
        return $this->caddyHostPathResolver()->resolve($node, $containerPath);
    }

    private function caddyHostPathResolver(): CaddyContainerHostPathResolver
    {
        return app(CaddyContainerHostPathResolver::class);
    }

    /**
     * Restore the orbit-caddy container on a serving node when proxy probing
     * reports the container is missing, stopped, restarting, or detached from
     * its managed network. With a managed tool spec, apply-container starts a
     * stopped container and force-recreates restart loops even when the stored
     * spec hash and network match. Without a managed record, only docker start
     * is attempted for the container-down path.
     *
     * @return array<string, mixed>|null
     */
    public function fixCaddyContainer(Node $node, DriftEntry $entry): ?array
    {
        $caddyName = $this->caddyContainerName($node);

        if ($entry->key === 'proxy.caddy_container_down') {
            $spec = $this->managedCaddyContainerSpec($node);
            $result = $spec === null
                ? $this->caddyConfig()->startContainer($node, $caddyName)
                : $this->caddyConfig()->applyContainer($node, $spec);

            $this->ensureSuccessful($result, "Failed to restore orbit-caddy container on {$node->name}");

            return [
                'family' => 'proxy',
                'node' => $node->name,
                'code' => $entry->key,
                'key' => $entry->key,
                'mode' => 'fix',
                'status' => 'completed',
                'summary' => $spec === null
                    ? "Started orbit-caddy container on {$node->name}."
                    : "Restored orbit-caddy container on {$node->name}.",
                'details' => [
                    'container' => $caddyName,
                ],
            ];
        }

        if (in_array(
            $entry->key,
            ['proxy.caddy_container_missing', 'proxy.caddy_container_detached'],
            strict: true,
        )) {
            $spec = $this->managedCaddyContainerSpec($node);

            if ($spec === null) {
                return [
                    'family' => 'proxy',
                    'node' => $node->name,
                    'code' => $entry->key,
                    'key' => $entry->key,
                    'mode' => 'fix',
                    'status' => 'refused',
                    'summary' => "Refusing to recreate orbit-caddy on {$node->name}: node has no managed orbit-caddy tool record. Run node role baseline convergence first.",
                    'details' => [
                        'container' => $caddyName,
                        'reason' => 'no_managed_caddy_tool',
                    ],
                ];
            }

            $result = $this->caddyConfig()->applyContainer($node, $spec);
            $this->ensureSuccessful($result, "Failed to reconcile orbit-caddy container on {$node->name}");

            return [
                'family' => 'proxy',
                'node' => $node->name,
                'code' => $entry->key,
                'key' => $entry->key,
                'mode' => 'fix',
                'status' => 'completed',
                'summary' => "Reconciled orbit-caddy container on {$node->name}.",
                'details' => [
                    'container' => $caddyName,
                ],
            ];
        }

        return null;
    }

    /**
     * Resolve the node's managed orbit-caddy container spec. The role baseline
     * persists role-specific port bindings (public ingress vs private node vs
     * default) into NodeTool.config.container; reconcile must use that spec so
     * the recreated container matches what the role baseline expects.
     *
     * @return array<string, mixed>|null
     */
    private function managedCaddyContainerSpec(Node $node): ?array
    {
        $tool = NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'caddy')
            ->first();

        if (! $tool instanceof NodeTool) {
            return null;
        }

        $config = is_array($tool->config) ? $tool->config : [];
        $container = $config['container'] ?? null;

        if (! is_array($container) || $container === []) {
            return null;
        }

        return $container;
    }

    /**
     * @return array<string, mixed>
     */
    public function removeExtra(Node $node, string $domain): array
    {
        $result = $this->caddyConfig()->removeSite($node, $domain, $this->caddyContainerName($node));
        $this->ensureSuccessful($result, "Failed to remove extra proxy route {$domain} from {$node->name}");
        $this->removeGlobalSiteBlock($node, $domain);

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

    /**
     * Restore the host-mounted global Caddyfile. When the file is missing and a
     * managed orbit-caddy tool spec exists, apply the container so the host
     * bind source is seeded before create/recreate and repair ends only after
     * the container is stably running. Healthy mismatch still writes the host
     * artifact and reloads.
     *
     * @return array<string, mixed>
     */
    public function fixGlobalConfig(Node $node, DriftEntry $entry): array
    {
        $spec = $this->managedCaddyContainerSpec($node);

        if ($entry->key === 'proxy.global_config_missing' && $spec !== null) {
            $result = $this->caddyConfig()->applyContainer($node, $spec);
            $this->ensureSuccessful(
                $result,
                "Failed to restore host global orbit-caddy config and container on {$node->name}",
            );

            return [
                'family' => 'proxy',
                'node' => $node->name,
                'code' => $entry->key,
                'key' => $entry->key,
                'mode' => 'fix',
                'status' => 'completed',
                'summary' => "Restored host global orbit-caddy config and container on {$node->name}.",
                'details' => [
                    'node' => $node->name,
                    'container' => $this->caddyContainerNameFromSpec($spec, $node),
                ],
            ];
        }

        $content = $this->caddyConfig()->readGlobal($node);

        if ($content === null) {
            throw new RuntimeException("Failed to read global orbit-caddy config on {$node->name}");
        }

        $updated = new CaddyGlobalConfig()->ensure($content);

        if (! hash_equals($updated, $content)) {
            $write = $this->caddyConfig()->writeGlobal($node, $updated);
            $this->ensureSuccessful($write, "Failed to write global orbit-caddy config on {$node->name}");

            try {
                $this->reloadCaddy($node);
            } catch (RuntimeException $exception) {
                if ($spec === null) {
                    throw $exception;
                }

                $result = $this->caddyConfig()->applyContainer($node, $spec);
                $this->ensureSuccessful(
                    $result,
                    "Failed to apply orbit-caddy container after writing global config on {$node->name}",
                );
            }
        }

        return [
            'family' => 'proxy',
            'node' => $node->name,
            'code' => $entry->key,
            'key' => $entry->key,
            'mode' => 'fix',
            'status' => 'completed',
            'summary' => "Reconciled global orbit-caddy config on {$node->name}.",
            'details' => [
                'node' => $node->name,
            ],
        ];
    }

    private function removeGlobalSiteBlock(Node $node, string $domain): void
    {
        $content = $this->caddyConfig()->readGlobal($node);

        if ($content === null) {
            return;
        }

        $updated = new CaddyGlobalSiteBlocks()->remove($content, [$domain]);

        if (hash_equals($updated, $content)) {
            return;
        }

        $write = $this->caddyConfig()->writeGlobal($node, $updated);
        $this->ensureSuccessful($write, "Failed to remove legacy global Caddy route {$domain} on {$node->name}");

        $this->reloadCaddy($node);
    }

    private function caddyContainerName(Node $node): string
    {
        return $this->caddyContainerNameFromSpec($this->managedCaddyContainerSpec($node), $node);
    }

    /**
     * @param  array<string, mixed>|null  $spec
     */
    private function caddyContainerNameFromSpec(?array $spec, Node $node): string
    {
        $name = $spec['name'] ?? null;

        if (is_string($name) && $name !== '') {
            return $name;
        }

        return OrbitContainerNames::forNodeScope(NodeContainerScope::forNode($node))->caddy();
    }

    private function writeSiteAndReload(Node $node, string $domain, string $content, bool $backend = false): void
    {
        $write = $this->caddyConfig()->writeSite($node, $domain, $content, $backend);
        $this->ensureSuccessful($write, "Failed to write Caddy site {$domain} on {$node->name}");

        $this->reloadCaddy($node);
    }

    private function reloadCaddy(Node $node): void
    {
        $reload = $this->caddyConfig()->reload($node, $this->caddyContainerName($node));
        $this->ensureSuccessful($reload, "Failed to reload Caddy on {$node->name}");
    }

    private function caddyConfig(): RemoteCaddyConfig
    {
        return $this->caddyConfig ?? app(RemoteCaddyConfig::class);
    }

    private function ensureSuccessful(RemoteShellResult $result, string $summary): void
    {
        if ($result->successful()) {
            return;
        }

        $output = trim($result->stderr.' '.$result->stdout);

        throw new RuntimeException($output !== '' ? "{$summary}: {$output}" : $summary);
    }

    private function validatedAbsolutePath(ProxyRoute $route, string $value, string $suffix): string
    {
        if (preg_match('/[\x00-\x1F\x7F\s]/', $value) === 1 || ! str_starts_with($value, '/')) {
            throw new RuntimeException("Proxy route '{$route->domain}' {$suffix}");
        }

        return $value;
    }
}
