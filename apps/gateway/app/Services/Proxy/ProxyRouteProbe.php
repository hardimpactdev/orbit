<?php

declare(strict_types=1);

namespace App\Services\Proxy;

use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Enums\DriftKind;
use App\Enums\Nodes\NodeRoleName;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Services\Ca\OrbitCaService;
use App\Services\Gateway\CaddyGlobalConfig;
use App\Services\Gateway\CaddyGlobalSiteBlocks;
use App\Services\Nodes\NodeContainerScope;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Runtime\OrbitContainerNames;
use App\Services\Tools\ToolScriptDispatcher;
use App\Services\Workspaces\WorkspacePlacement;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

final readonly class ProxyRouteProbe
{
    private const array OwnerTypes = [
        'app',
        'app-analytics',
        'app-websocket',
        'workspace',
        'gateway',
        'router',
        's3',
        'tool',
        'custom',
    ];

    private const array Kinds = ['app', 'workspace', 'internal', 'proxy', 'redirect'];

    private const array WORKSPACE_OWNER_TYPES = ['workspace', Workspace::class];

    public function __construct(
        private ?ToolScriptDispatcher $scripts = null,
        private ?ProxyRouteRenderer $renderer = null,
        private ?AgentToolProxyRouteIntent $agentToolRoutes = null,
        private ?ProxyRouteFileProbeContract $routeFileProbeContract = null,
        private ?WorkspaceProxyRouteOwnershipResolver $workspaceRouteOwnership = null,
    ) {}

    public function key(): string
    {
        return 'proxy';
    }

    public function label(): string
    {
        return 'Proxy';
    }

    public function introspect(ProxyRoute $route): ProbeSnapshot
    {
        $route->loadMissing('node');

        if (! $route->node instanceof Node || $route->domain === '') {
            return new ProbeSnapshot([]);
        }

        $public = $this->inspectRouteFile(
            $route->node,
            $route->domain,
            runtimeUpstream: $this->runtimeUpstreamForProbe($route),
        );

        if (! $this->usesIngressPlacement($route)) {
            return new ProbeSnapshot([
                $route->domain => $public,
            ]);
        }

        $router = [];
        $routerArtifact = $this->routerArtifact($route);
        $routerNodeId = $routerArtifact['node_id'] ?? null;
        $routerNode = is_int($routerNodeId) ? Node::query()->find($routerNodeId) : null;

        if ($routerNode instanceof Node) {
            $router = $this->inspectRouteFile($routerNode, $route->domain);
        }

        $backends = [];

        foreach ($this->backendArtifacts($route) as $artifact) {
            $nodeId = $artifact['node_id'] ?? null;

            if (! is_int($nodeId)) {
                continue;
            }

            $backendNode = Node::query()->find($nodeId);

            if (! $backendNode instanceof Node) {
                continue;
            }

            $backends[$nodeId] = $this->inspectRouteFile($backendNode, $route->domain, backend: true);
        }

        return new ProbeSnapshot([
            $route->domain => [
                ...$public,
                'public' => $public,
                'router' => $router,
                'backends' => $backends,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function inspectRouteFile(
        Node $node,
        string $domain,
        bool $backend = false,
        ?string $runtimeUpstream = null,
    ): array {
        $script = $this->routeFileProbeContract()->render(
            domain: $domain,
            routeSuffix: $backend ? '.backend' : '',
            runtimeUpstream: $runtimeUpstream,
        );

        $result = $this->scripts()->run($node, 'orbit-proxy', 'probe', $script, throw: true);

        return [
            'runtime_upstream' => $runtimeUpstream,
            ...$this->routeFileProbeContract()->parse($result),
        ];
    }

    public function introspectNode(Node $node): ProbeSnapshot
    {
        $script = <<<'BASH'
            set -euo pipefail
            if [ "$(docker container inspect --format '{{if .State.Restarting}}restarting{{else}}{{.State.Status}}{{end}}' orbit-caddy 2>/dev/null || true)" != "running" ]; then
                exit 0
            fi
            docker exec orbit-caddy sh -c '
                if [ ! -d /etc/caddy/sites ]; then
                    exit 0
                fi
                for f in /etc/caddy/sites/*.caddy; do
                    [ -e "$f" ] || continue
                    name=$(basename "$f" .caddy)
                    hash=$(sha256sum "$f" | cut -d " " -f 1)
                    cert=""
                    key=""
                    tls_line=$(grep -m 1 -E "^[[:space:]]*tls[[:space:]]+" "$f" || true)
                    if [ -n "$tls_line" ]; then
                        set -- $tls_line
                        if [ "${1:-}" = "tls" ] && [ "${2:-}" != "internal" ]; then
                            cert="${2:-}"
                            key="${3:-}"
                        fi
                    fi
                    cert_exists=0; key_exists=0
                    [ -n "$cert" ] && [ -f "$cert" ] && cert_exists=1
                    [ -n "$key" ] && [ -f "$key" ] && key_exists=1
                    printf "%s\t%s\t%s\t%s\t%s\t%s\n" "$name" "$hash" "$cert" "$key" "$cert_exists" "$key_exists"
                done
            '
            BASH;

        $result = $this->scripts()->run($node, 'orbit-proxy', 'probe-many', $script, throw: true);
        $items = [];

        foreach (explode("\n", rtrim($result->stdout, "\n\r")) as $line) {
            if ($line === '') {
                continue;
            }

            $parts = explode("\t", $line, 6);

            if (count($parts) !== 6) {
                continue;
            }

            [$name, $hash, $cert, $key, $certExists, $keyExists] = $parts;
            $items[$name] = [
                'route_exists' => true,
                'route_hash' => $hash,
                'cert_path' => $cert,
                'key_path' => $key,
                'cert_exists' => $certExists === '1',
                'key_exists' => $keyExists === '1',
            ];
        }

        return new ProbeSnapshot($items);
    }

    public function introspectGlobalConfig(Node $node): ProbeSnapshot
    {
        // Host-mounted Caddyfile is the source of truth. Resolve the bind source
        // via docker inspect (works for restarting containers) and fall back to
        // the default host path when the container is absent — never require a
        // healthy docker-exec target to decide whether the global config exists.
        $script = <<<'BASH'
            # orbit-proxy-doctor:global-caddy-config-probe
            set -euo pipefail
            container=orbit-caddy
            source=$(docker container inspect --format '{{range .Mounts}}{{if eq .Destination "/etc/caddy/Caddyfile"}}{{.Source}}{{println}}{{end}}{{end}}' "$container" 2>/dev/null | head -n1 || true)
            if [ -z "${source}" ]; then
                base=$(docker container inspect --format '{{range .Mounts}}{{if eq .Destination "/etc/caddy"}}{{.Source}}{{println}}{{end}}{{end}}' "$container" 2>/dev/null | head -n1 || true)
                if [ -n "${base}" ]; then
                    source="${base}/Caddyfile"
                fi
            fi
            if [ -z "${source}" ]; then
                source="/etc/caddy/Caddyfile"
            fi
            if [ ! -f "$source" ]; then
                printf "0\t\n"
                exit 0
            fi
            # Prefer stdin redirection: GNU base64 accepts files or stdin; BSD/macOS
            # base64 does not accept a bare path for encode (needs -i or stdin).
            content=$(base64 -w0 < "$source" 2>/dev/null || base64 < "$source" | tr -d "\n")
            printf "1\t%s\n" "$content"
            BASH;

        $result = $this->scripts()->run($node, 'orbit-proxy', 'probe', $script, throw: true);
        $parts = explode("\t", trim($result->stdout), limit: 2);
        $exists = ($parts[0] ?? '') === '1';
        $content = '';

        if ($exists && is_string($parts[1] ?? null)) {
            $decoded = base64_decode($parts[1], true);
            $content = $decoded === false ? '' : $decoded;
        }

        return new ProbeSnapshot([
            'global_caddy_config' => [
                'exists' => $exists,
                'content' => $content,
                'hash' => hash('sha256', $content),
            ],
        ]);
    }

    /**
     * Probe the orbit-caddy container on a serving node. Returns a single
     * snapshot keyed by the canonical container name with `runtime_status`
     * (one of `available`, `no_docker`, `daemon_unavailable`),
     * `container_exists`, and `container_running` flags. Host caddy.service is
     * intentionally never inspected — orbit-caddy is the steady-state runtime.
     *
     * The script tag `# orbit-proxy-doctor:caddy-container-probe` lets test
     * fakes and other tooling identify this probe unambiguously even when
     * other code paths invoke `docker container inspect orbit-caddy` (e.g.
     * CaddyTool::updateScript).
     */
    public function introspectCaddyContainer(Node $node): ProbeSnapshot
    {
        $containerNames = OrbitContainerNames::forNodeScope(NodeContainerScope::forNode($node));
        $caddyName = $containerNames->caddy();

        $script = sprintf(
            <<<'BASH'
                # orbit-proxy-doctor:caddy-container-probe
                container=%s
                network=%s
                runtime="available"
                exists="false"
                running="false"
                network_attached="false"

                if ! command -v docker >/dev/null 2>&1; then
                    runtime="no_docker"
                elif ! docker info >/dev/null 2>&1; then
                    runtime="daemon_unavailable"
                else
                    state=$(docker container inspect --format '{{if .State.Restarting}}restarting{{else}}{{.State.Status}}{{end}}' "$container" 2>/dev/null || true)
                    if [ -n "$state" ]; then
                        exists="true"
                        if [ "$state" = "running" ]; then
                            running="true"
                        fi
                        if docker container inspect --format '{{range $name, $_ := .NetworkSettings.Networks}}{{println $name}}{{end}}' "$container" 2>/dev/null | grep -Fx "$network" >/dev/null; then
                            network_attached="true"
                        fi
                    fi
                fi
                printf '%%s\t%%s\t%%s\t%%s\n' "$runtime" "$exists" "$running" "$network_attached"
                BASH,
            escapeshellarg($caddyName),
            escapeshellarg($containerNames->network()),
        );

        $result = $this->scripts()->run($node, 'orbit-proxy', 'probe', $script);
        $parts = explode("\t", trim($result->stdout), limit: 4);
        $runtimeStatus = ($parts[0] ?? '') !== '' ? $parts[0] : 'unknown';

        return new ProbeSnapshot([
            $caddyName => [
                'runtime_status' => $runtimeStatus,
                'container_exists' => ($parts[1] ?? '') === 'true',
                'container_running' => ($parts[2] ?? '') === 'true',
                'container_network_attached' => ($parts[3] ?? '') === 'true',
            ],
        ]);
    }

    /**
     * Compare the observed orbit-caddy container state on a serving node
     * against the expectation that it must exist and be running for mounted
     * proxy route artifacts to take effect.
     *
     * @return list<DriftEntry>
     */
    public function diffCaddyContainer(Node $node, ProbeSnapshot $snapshot): array
    {
        $caddyName = OrbitContainerNames::forNodeScope(NodeContainerScope::forNode($node))->caddy();
        $observed = $snapshot->get($caddyName);

        if (! is_array($observed)) {
            return [];
        }

        $runtimeStatus = is_string($observed['runtime_status'] ?? null)
            ? $observed['runtime_status']
            : 'available';

        if ($runtimeStatus !== 'available') {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.docker_runtime_unavailable',
                    kind: DriftKind::Divergent,
                    summary: $runtimeStatus === 'no_docker'
                        ? "Docker CLI is not installed on {$node->name}; orbit-caddy cannot be probed."
                        : "Docker daemon is unreachable on {$node->name}; orbit-caddy cannot be probed.",
                    detail: [
                        'container' => $caddyName,
                        'node' => $node->name,
                        'runtime_status' => $runtimeStatus,
                    ],
                ),
            ];
        }

        if (($observed['container_exists'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.caddy_container_missing',
                    kind: DriftKind::Missing,
                    summary: "Proxy runtime container {$caddyName} is missing on {$node->name}.",
                    detail: [
                        'container' => $caddyName,
                        'node' => $node->name,
                    ],
                ),
            ];
        }

        if (($observed['container_running'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.caddy_container_down',
                    kind: DriftKind::Divergent,
                    summary: "Proxy runtime container {$caddyName} is not running on {$node->name}.",
                    detail: [
                        'container' => $caddyName,
                        'node' => $node->name,
                    ],
                ),
            ];
        }

        if (($observed['container_network_attached'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.caddy_container_detached',
                    kind: DriftKind::Divergent,
                    summary: "Proxy runtime container {$caddyName} is detached from its managed Docker network.",
                    detail: [
                        'container' => $caddyName,
                        'node' => $node->name,
                        'network' => OrbitContainerNames::forNodeScope(
                            NodeContainerScope::forNode($node),
                        )->network(),
                    ],
                ),
            ];
        }

        return [];
    }

    public function snapshotForAdopt(Node $node): ProbeSnapshot
    {
        $script = <<<'BASH'
            set -euo pipefail
            if [ "$(docker container inspect --format '{{if .State.Restarting}}restarting{{else}}{{.State.Status}}{{end}}' orbit-caddy 2>/dev/null || true)" != "running" ]; then
                exit 0
            fi
            docker exec orbit-caddy sh -c '
                if [ ! -d /etc/caddy/sites ]; then
                    exit 0
                fi
                for f in /etc/caddy/sites/*.caddy; do
                    [ -e "$f" ] || continue
                    name=$(basename "$f" .caddy)
                    vhost_hash=$(sha256sum "$f" | cut -d " " -f 1)
                    body_b64=$(base64 -w0 "$f" 2>/dev/null || base64 "$f" | tr -d "\n")
                    printf "%s\t%s\t%s\n" "$name" "$vhost_hash" "$body_b64"
                done
            '
            BASH;

        $result = $this->scripts()->run($node, 'orbit-proxy', 'probe-many', $script, throw: true);
        $items = [];

        foreach (explode("\n", rtrim($result->stdout, "\n\r")) as $line) {
            if ($line === '') {
                continue;
            }

            $parts = explode("\t", $line, 3);

            if (count($parts) !== 3) {
                continue;
            }

            [$name, $hash, $bodyB64] = $parts;
            $body = base64_decode($bodyB64, true);

            if ($body === false) {
                continue;
            }

            $items[$name] = [
                'hash' => $hash,
                'body' => $body,
            ];
        }

        return new ProbeSnapshot($items);
    }

    /**
     * @return list<DriftEntry>
     */
    public function diff(ProxyRoute $route, ProbeSnapshot $snapshot): array
    {
        // A missing owner is one conceptual orphan. Suppress record_incomplete
        // and backend/TLS sub-findings for the same doomed row so restore/remove
        // paths see a single owner_invalid issue.
        $ownerDrift = $this->checkOwnerEligibility($route);

        if ($ownerDrift !== []) {
            return $ownerDrift;
        }

        $drift = [
            ...$this->checkRecordCompleteness($route),
            ...$this->checkNodeEligibility($route),
            ...$this->checkCustomDomainConflict($route),
            ...$this->checkRouteProbeEvidence($route, $snapshot),
            ...$this->checkBackendReality($route, $snapshot),
            ...$this->checkTlsReality($route, $snapshot),
        ];

        return [
            ...$drift,
            ...$this->checkEnactmentState($route),
        ];
    }

    /**
     * @return list<DriftEntry>
     */
    public function diffNode(Node $node, ProbeSnapshot $snapshot): array
    {
        $drift = $this->diffAgentToolRouteIntent($node);
        $allRoutes = $this->persistedRoutesForNodeEvaluation($node);
        $dbRoutes = array_values(array_filter(
            $allRoutes,
            fn (ProxyRoute $route): bool => $route->node_id === $node->id,
        ));
        $observedDomains = $this->observedRouteDomainsForNode($node, $snapshot);
        $expectedDomains = $this->expectedDomainsForNode($node);

        foreach ($dbRoutes as $route) {
            $routeDrift = $this->diff($route, $snapshot);

            if (! in_array($route->domain, $observedDomains, true)) {
                $hasBackendDrift = collect($routeDrift)->contains(
                    fn (DriftEntry $entry): bool => in_array(
                        $entry->key,
                        [
                            'proxy.route_missing',
                            'proxy.route_mismatch',
                            'proxy.public_route_missing',
                            'proxy.public_route_mismatch',
                        ],
                        true,
                    ),
                );

                if (! $hasBackendDrift) {
                    $routeDrift[] = new DriftEntry(
                        family: $this->key(),
                        key: 'proxy.route_missing',
                        kind: DriftKind::Missing,
                        summary: "Proxy backend route {$route->domain} is missing on the serving node.",
                    );
                }
            }

            $drift = array_merge($drift, $routeDrift);
        }

        foreach ($observedDomains as $domain) {
            $domain = $domain;

            if (in_array($domain, $expectedDomains, true)) {
                continue;
            }

            $drift[] = new DriftEntry(
                family: $this->key(),
                key: $domain,
                kind: DriftKind::Extra,
                summary: "Proxy route '{$domain}' exists on node but not in gateway registry.",
                detail: [
                    'domain' => $domain,
                    'code' => 'proxy.route_extra',
                ],
            );
        }

        return $drift;
    }

    /**
     * @return list<DriftEntry>
     */
    public function diffAgentToolRouteIntent(Node $node): array
    {
        $drift = [];

        foreach ($this->agentToolRouteIntent()->expectedRoutesForNode($node) as $expected) {
            $actual = $this->agentToolRouteIntent()->persistedRoute($expected);
            $expectedConfig = is_array($expected->config) ? $expected->config : [];

            if (! $actual instanceof ProxyRoute) {
                $drift[] = new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.agent_tool_route_missing',
                    kind: DriftKind::Missing,
                    summary: "Agent tool {$expectedConfig['owner_name']} is missing its expected proxy route {$expected->domain}.",
                    detail: [
                        'tool' => $expectedConfig['owner_name'] ?? null,
                        'domain' => $expected->domain,
                    ],
                );

                continue;
            }

            if ($this->agentToolRouteIntent()->hasOwnershipConflict($actual, $expected)) {
                $actualConfig = is_array($actual->config) ? $actual->config : [];
                $drift[] = new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.agent_tool_route_conflict',
                    kind: DriftKind::Divergent,
                    summary: "Agent tool proxy route {$expected->domain} is occupied by different proxy intent.",
                    detail: [
                        'tool' => $expectedConfig['owner_name'] ?? null,
                        'domain' => $expected->domain,
                        'observed_owner_type' => $actual->owner_type,
                        'observed_owner_name' => $actualConfig['owner_name'] ?? null,
                    ],
                );

                continue;
            }

            if ($this->agentToolRouteIntent()->matches($actual, $expected)) {
                continue;
            }

            $actualConfig = is_array($actual->config) ? $actual->config : [];
            $drift[] = new DriftEntry(
                family: $this->key(),
                key: 'proxy.agent_tool_route_mismatch',
                kind: DriftKind::Divergent,
                summary: "Agent tool proxy route {$expected->domain} differs from gateway proxy intent.",
                detail: [
                    'tool' => $expectedConfig['owner_name'] ?? null,
                    'domain' => $expected->domain,
                    'expected_kind' => $expected->kind,
                    'observed_kind' => $actual->kind,
                    'expected_upstream' => $expectedConfig['upstream'] ?? null,
                    'observed_upstream' => $actualConfig['target']['value'] ?? $actualConfig['upstream'] ?? null,
                    'expected_hash' => $expected->source_hash,
                    'observed_hash' => $actual->source_hash,
                ],
            );
        }

        return $drift;
    }

    /**
     * @return list<DriftEntry>
     */
    public function diffGlobalConfig(Node $node, ProbeSnapshot $snapshot): array
    {
        $observed = $snapshot->get('global_caddy_config');

        if (! is_array($observed)) {
            return [];
        }

        if (($observed['exists'] ?? null) !== true) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.global_config_missing',
                    kind: DriftKind::Missing,
                    summary: "Global orbit-caddy config is missing on {$node->name}.",
                    detail: ['node' => $node->name],
                ),
            ];
        }

        $content = is_string($observed['content'] ?? null) ? $observed['content'] : '';
        $config = new CaddyGlobalConfig;
        $expectedContent = $config->ensure($content);
        $drift = [];

        if (! hash_equals($expectedContent, rtrim($content)."\n")) {
            $drift[] = new DriftEntry(
                family: $this->key(),
                key: 'proxy.global_config_mismatch',
                kind: DriftKind::Divergent,
                summary: "Global orbit-caddy config on {$node->name} is missing Orbit-managed imports or snippets.",
                detail: [
                    'node' => $node->name,
                    'current_hash' => is_string($observed['hash'] ?? null)
                        ? $observed['hash']
                        : hash('sha256', $content),
                    'expected_hash' => hash('sha256', $expectedContent),
                    'expected_imports' => CaddyGlobalConfig::Imports,
                ],
            );
        }

        $tld = is_string($node->tld) ? trim($node->tld) : '';

        if ($tld === '') {
            return $drift;
        }

        $expectedDomains = $this->expectedDomainsForNode($node);
        $excludedDomains = $this->workspaceRouteDomainsExcludedForNode($node);

        foreach (new CaddyGlobalSiteBlocks()->domains($content) as $domain) {
            if (
                ! str_ends_with($domain, ".{$tld}")
                || in_array($domain, $expectedDomains, true)
                || in_array($domain, $excludedDomains, true)
            ) {
                continue;
            }

            $drift[] = new DriftEntry(
                family: $this->key(),
                key: $domain,
                kind: DriftKind::Extra,
                summary: "Proxy route '{$domain}' exists in the global Caddyfile but not in the gateway registry.",
                detail: [
                    'domain' => $domain,
                    'source' => 'global_caddy_config',
                    'code' => 'proxy.route_extra',
                ],
            );
        }

        return $drift;
    }

    /**
     * @return list<string>
     */
    public function expectedDomainsForNode(Node $node): array
    {
        return array_values(array_unique([
            ...$this->expectedRouteDomainsForNode($this->persistedRoutesForNodeEvaluation($node), $node),
            ...array_map(
                static fn (ProxyRoute $route): string => $route->domain,
                $this->agentToolRouteIntent()->expectedRoutesForNode($node),
            ),
        ]));
    }

    /**
     * @return list<string>
     */
    public function observedRouteDomainsForNode(Node $node, ProbeSnapshot $snapshot): array
    {
        $excludedDomains = $this->workspaceRouteDomainsExcludedForNode($node);

        return array_values(array_filter(
            $snapshot->keys(),
            static fn (string $domain): bool => ! in_array($domain, $excludedDomains, true),
        ));
    }

    /**
     * @param  list<ProxyRoute>  $routes
     * @return list<string>
     */
    private function expectedRouteDomainsForNode(array $routes, Node $node): array
    {
        $domains = [];

        foreach ($routes as $route) {
            if ($route->node_id === $node->id) {
                $domains[] = $route->domain;
            }

            $routerNodeId = $this->routerArtifact($route)['node_id'] ?? null;

            if ($routerNodeId === $node->id) {
                $domains[] = $route->domain;
            }

            foreach ($this->backendArtifacts($route) as $artifact) {
                if (($artifact['node_id'] ?? null) === $node->id) {
                    $domains[] = "{$route->domain}.backend";
                }
            }
        }

        return array_values(array_unique(array_filter($domains, is_string(...))));
    }

    /**
     * @return list<ProxyRoute>
     */
    private function persistedRoutesForNodeEvaluation(Node $node): array
    {
        $query = ProxyRoute::query();

        if ($this->productionNodeExcludesWorkspaceRoutes($node)) {
            $query
                ->whereNull('workspace_id')
                ->whereNotIn('owner_type', self::WORKSPACE_OWNER_TYPES)
                ->where('kind', '!=', 'workspace');
        }

        return array_values($query->get()->all());
    }

    /**
     * @return list<string>
     */
    private function workspaceRouteDomainsExcludedForNode(Node $node): array
    {
        if (! $this->productionNodeExcludesWorkspaceRoutes($node)) {
            return [];
        }

        /** @var Builder<ProxyRoute> $query */
        $query = ProxyRoute::query();
        $query->where(function (Builder $workspaceQuery): void {
            $workspaceQuery
                ->whereNotNull('workspace_id')
                ->orWhereIn('owner_type', self::WORKSPACE_OWNER_TYPES)
                ->orWhere('kind', 'workspace');
        });

        $routes = array_values($query->get()->all());

        return array_values(array_unique([
            ...array_map(static fn (ProxyRoute $route): string => $route->domain, $routes),
            ...$this->expectedRouteDomainsForNode($routes, $node),
        ]));
    }

    private function productionNodeExcludesWorkspaceRoutes(Node $node): bool
    {
        return app(NodeRoleAssignments::class)->nodeHasActiveRole($node, NodeRoleName::AppProduction->value);
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkRecordCompleteness(ProxyRoute $route): array
    {
        if (
            ! is_string($route->domain)
            || $route->domain === ''
            || ! is_int($route->node_id)
            || ! in_array($route->owner_type, self::OwnerTypes, true)
            || ! in_array($route->kind, self::Kinds, true)
            || ! is_string($route->source_hash)
            || $route->source_hash === ''
            || ! $this->hasTargetShape($route)
        ) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.record_incomplete',
                    kind: DriftKind::Missing,
                    summary: "Proxy route record for {$route->domain} is missing required fields.",
                ),
            ];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $observed
     * @return list<DriftEntry>
     */
    private function checkRuntimeUpstreamReality(ProxyRoute $route, array $observed): array
    {
        if (! $this->shouldProbeRuntimeUpstream($route)) {
            return [];
        }

        if (($observed['runtime_upstream_reachable'] ?? null) !== false) {
            return [];
        }

        return [
            new DriftEntry(
                family: $this->key(),
                key: 'proxy.runtime_unreachable',
                kind: DriftKind::Divergent,
                summary: "Proxy route {$route->domain} cannot reach its runtime upstream from orbit-caddy.",
                detail: [
                    'runtime_upstream' => $observed['runtime_upstream'] ?? $this->runtimeUpstreamForProbe($route),
                    'probe_error' => $observed['runtime_probe_error'] ?? null,
                ],
            ),
        ];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkOwnerEligibility(ProxyRoute $route): array
    {
        $route->loadMissing(['instance.app', 'workspace.instance']);

        if ($route->owner_type === 'app' && ! $this->hasInstanceOwner($route)) {
            return [$this->ownerInvalid($route, 'app')];
        }

        if ($route->owner_type === 'app-analytics' && ! $this->hasInstanceOwner($route)) {
            return [$this->ownerInvalid($route, 'app-analytics')];
        }

        if ($route->owner_type === 'app-websocket' && ! $this->hasInstanceOwner($route)) {
            return [$this->ownerInvalid($route, 'app-websocket')];
        }

        if (
            $route->owner_type === 'workspace'
            && $this->workspaceRouteOwnership()->resolve($route) === null
        ) {
            return [$this->ownerInvalid($route, 'workspace')];
        }

        if ($route->owner_type === 'tool' && $this->toolOwnerIsMissing($route)) {
            return [$this->ownerInvalid($route, 'tool')];
        }

        return [];
    }

    private function hasInstanceOwner(ProxyRoute $route): bool
    {
        return (
            $route->instance instanceof Instance
            && $route->instance->app instanceof App
            && $route->app_id === $route->instance->app_id
        );
    }

    private function toolOwnerIsMissing(ProxyRoute $route): bool
    {
        $config = is_array($route->config) ? $route->config : [];
        $ownerName = is_string($config['owner_name'] ?? null) ? $config['owner_name'] : null;

        if ($ownerName === null || $ownerName === '') {
            return true;
        }

        return ! NodeTool::query()
            ->where('node_id', $route->node_id)
            ->where('name', $ownerName)
            ->where('expected_state', 'installed')
            ->exists();
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkNodeEligibility(ProxyRoute $route): array
    {
        $route->loadMissing('node');

        if (! $route->node instanceof Node) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.node_invalid',
                    kind: DriftKind::Divergent,
                    summary: "Proxy route {$route->domain} points at a missing serving node.",
                ),
            ];
        }

        if (! $route->node->isActive() || ! $this->canServeProxyRoutes($route, $route->node)) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.node_invalid',
                    kind: DriftKind::Divergent,
                    summary: "Proxy route {$route->domain} is served by node {$route->node->name}, which is not an eligible active serving node.",
                    detail: [
                        'node' => $route->node->name,
                        'role' => $route->node->displayRole(),
                        'status' => $route->node->status,
                    ],
                ),
            ];
        }

        return [];
    }

    private function canServeProxyRoutes(ProxyRoute $route, Node $node): bool
    {
        // Ingress-placed routes (public S3 host routes, public WebSocket routes, etc.)
        // are served on the ingress node — checked first before any owner_type branch.
        if ($this->usesIngressPlacement($route)) {
            return app(NodeRoleAssignments::class)->nodeCanServeIngress($node);
        }

        // Router-owned service routes (websocket.orbit, s3.orbit) live on the router node.
        // Without this branch they would fall through to nodeCanServeGatewayOrAppHostWorkloads
        // and produce a false proxy.node_invalid on a healthy router-only node.
        if ($route->owner_type === 'router') {
            return app(NodeRoleAssignments::class)->nodeCanServeRouter($node);
        }

        $assignments = app(NodeRoleAssignments::class);

        if ($route->owner_type === 'custom') {
            return (
                $assignments->nodeCanServeGatewayOrAppHostWorkloads($node) || $assignments->nodeCanServeIngress($node)
            );
        }

        if ($route->owner_type === 'tool') {
            return $assignments->nodeHostsOrbitCaddy($node);
        }

        return $assignments->nodeCanServeGatewayOrAppHostWorkloads($node);
    }

    private function agentToolRouteIntent(): AgentToolProxyRouteIntent
    {
        return $this->agentToolRoutes ?? app(AgentToolProxyRouteIntent::class);
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkCustomDomainConflict(ProxyRoute $route): array
    {
        if ($route->owner_type !== 'custom') {
            return [];
        }

        // Domain ownership is instance-authoritative: an app conflicts with the
        // custom route when one of its concrete instances serves that host.
        $app = App::query()
            ->with('instances')
            ->get()
            ->first(fn (App $candidate): bool => app(WorkspacePlacement::class)->appHasUrl($candidate, $route->domain));

        if ($app instanceof App) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.domain_conflict',
                    kind: DriftKind::Divergent,
                    summary: "Custom proxy route {$route->domain} conflicts with app {$app->name}.",
                    detail: [
                        'domain' => $route->domain,
                        'owner_type' => 'app',
                        'owner_name' => $app->name,
                    ],
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkEnactmentState(ProxyRoute $route): array
    {
        if (! in_array($route->owner_type, ['app', 'custom'], true)) {
            return [];
        }

        $config = is_array($route->config) ? $route->config : [];
        $status = ProxyRouteEnactment::status($config);

        if (! in_array(
            $status,
            [
                ProxyRouteEnactment::FAILED,
                ProxyRouteEnactment::PARTIAL,
                ProxyRouteEnactment::PENDING,
            ],
            true,
        )) {
            return [];
        }

        $enactment = is_array($config['enactment'] ?? null) ? $config['enactment'] : [];
        $failure = is_array($enactment['failure'] ?? null) ? $enactment['failure'] : null;

        return [
            new DriftEntry(
                family: $this->key(),
                key: 'proxy.enactment_incomplete',
                kind: DriftKind::Divergent,
                summary: "Proxy route {$route->domain} has incomplete persisted enactment.",
                detail: [
                    'status' => $status,
                    'failure' => $failure,
                ],
            ),
        ];
    }

    private function ownerInvalid(ProxyRoute $route, string $ownerType): DriftEntry
    {
        return new DriftEntry(
            family: $this->key(),
            key: 'proxy.owner_invalid',
            kind: DriftKind::Divergent,
            summary: "Proxy route {$route->domain} points at a missing {$ownerType} owner.",
            detail: [
                'owner_type' => $ownerType,
            ],
        );
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkRouteProbeEvidence(ProxyRoute $route, ProbeSnapshot $snapshot): array
    {
        $observed = $snapshot->get($route->domain);

        if (! is_array($observed)) {
            return [];
        }

        $unavailable = [];

        if (! $this->usesIngressPlacement($route)) {
            $this->appendUnavailableObservation($unavailable, 'serving', $route->node_id, $observed);

            return $this->unavailableRouteProbeDrift($route, $unavailable);
        }

        $this->appendUnavailableObservation(
            $unavailable,
            'public',
            $route->node_id,
            $this->publicObservation($observed),
        );

        $routerNodeId = $this->routerArtifact($route)['node_id'] ?? null;
        $this->appendUnavailableObservation(
            $unavailable,
            'router',
            is_int($routerNodeId) ? $routerNodeId : null,
            $this->routerObservation($observed),
        );

        $backends = is_array($observed['backends'] ?? null) ? $observed['backends'] : [];

        foreach ($backends as $nodeId => $backend) {
            if (! is_array($backend)) {
                continue;
            }

            $this->appendUnavailableObservation(
                $unavailable,
                'backend',
                is_int($nodeId) ? $nodeId : null,
                $backend,
            );
        }

        return $this->unavailableRouteProbeDrift($route, $unavailable);
    }

    /**
     * @param  list<array{scope: string, node_id: int|null, status: string|null, error: string|null}>  $unavailable
     * @return list<DriftEntry>
     */
    private function unavailableRouteProbeDrift(ProxyRoute $route, array $unavailable): array
    {
        if ($unavailable === []) {
            return [];
        }

        return [
            new DriftEntry(
                family: $this->key(),
                key: 'proxy.route_probe_failed',
                kind: DriftKind::Unverifiable,
                summary: "Proxy route {$route->domain} could not be fully inspected.",
                detail: ['observations' => $unavailable],
            ),
        ];
    }

    /**
     * @param  list<array{scope: string, node_id: int|null, status: string|null, error: string|null}>  $unavailable
     * @param  array<array-key, mixed>  $observation
     */
    private function appendUnavailableObservation(
        array &$unavailable,
        string $scope,
        ?int $nodeId,
        array $observation,
    ): void {
        if (! $this->routeProbeFailed($observation)) {
            return;
        }

        $unavailable[] = [
            'scope' => $scope,
            'node_id' => $nodeId,
            'status' => is_string($observation['route_probe_status'] ?? null)
                ? $observation['route_probe_status']
                : null,
            'error' => is_string($observation['route_probe_error'] ?? null)
                ? $observation['route_probe_error']
                : null,
        ];
    }

    /** @param array<array-key, mixed> $observation */
    private function routeProbeFailed(array $observation): bool
    {
        return ($observation['route_probe_ok'] ?? null) === false;
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkBackendReality(ProxyRoute $route, ProbeSnapshot $snapshot): array
    {
        $observed = $snapshot->get($route->domain);

        if ($observed === null) {
            return $this->checkBackendArtifactNodes($route);
        }

        if ($this->usesIngressPlacement($route)) {
            return [
                ...$this->checkPublicRouteReality($route, $observed),
                ...$this->checkRouterArtifactNode($route),
                ...$this->checkRouterRouteReality($route, $observed),
                ...$this->checkBackendArtifactNodes($route),
                ...$this->checkBackendArtifactReality($route, $observed),
            ];
        }

        if ($this->routeProbeFailed($observed)) {
            return [];
        }

        if (($observed['route_exists'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.route_missing',
                    kind: DriftKind::Missing,
                    summary: "Proxy backend route {$route->domain} is missing on the serving node.",
                ),
            ];
        }

        $expectedHash = $this->expectedSourceHash($route);

        if ($route->source_hash !== $expectedHash || ($observed['route_hash'] ?? null) !== $expectedHash) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.route_mismatch',
                    kind: DriftKind::Divergent,
                    summary: "Proxy backend route {$route->domain} differs from gateway proxy intent.",
                    detail: [
                        'expected_hash' => $expectedHash,
                        'observed_hash' => $observed['route_hash'] ?? null,
                    ],
                ),
            ];
        }

        return $this->checkRuntimeUpstreamReality($route, $observed);
    }

    /**
     * @param  array<string, mixed>  $observed
     * @return list<DriftEntry>
     */
    private function checkPublicRouteReality(ProxyRoute $route, array $observed): array
    {
        $public = $this->publicObservation($observed);

        if ($this->routeProbeFailed($public)) {
            return [];
        }

        if (($public['route_exists'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.public_route_missing',
                    kind: DriftKind::Missing,
                    summary: "Public proxy route {$route->domain} is missing on the ingress node.",
                ),
            ];
        }

        if (($public['route_hash'] ?? null) !== $route->source_hash) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.public_route_mismatch',
                    kind: DriftKind::Divergent,
                    summary: "Public proxy route {$route->domain} differs from gateway proxy intent.",
                    detail: [
                        'expected_hash' => $route->source_hash,
                        'observed_hash' => $public['route_hash'] ?? null,
                    ],
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkRouterArtifactNode(ProxyRoute $route): array
    {
        $artifact = $this->routerArtifact($route);
        $nodeId = $artifact['node_id'] ?? null;
        $node = is_int($nodeId) ? Node::query()->find($nodeId) : null;

        if ($node instanceof Node && app(NodeRoleAssignments::class)->nodeCanServeRouter($node)) {
            return [];
        }

        return [
            new DriftEntry(
                family: $this->key(),
                key: 'proxy.router_node_invalid',
                kind: DriftKind::Divergent,
                summary: "Router route {$route->domain} points at an invalid router node.",
                detail: [
                    'router_node_id' => $nodeId,
                ],
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $observed
     * @return list<DriftEntry>
     */
    private function checkRouterRouteReality(ProxyRoute $route, array $observed): array
    {
        $router = $this->routerObservation($observed);

        if ($this->routeProbeFailed($router)) {
            return [];
        }

        if (($router['route_exists'] ?? false) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.router_route_missing',
                    kind: DriftKind::Missing,
                    summary: "Private router route {$route->domain} is missing on the router node.",
                ),
            ];
        }

        $expectedHash = $this->routerArtifact($route)['source_hash'] ?? null;

        if (($router['route_hash'] ?? null) !== $expectedHash) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.router_route_mismatch',
                    kind: DriftKind::Divergent,
                    summary: "Private router route {$route->domain} differs from gateway proxy intent.",
                    detail: [
                        'expected_hash' => $expectedHash,
                        'observed_hash' => $router['route_hash'] ?? null,
                    ],
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkBackendArtifactNodes(ProxyRoute $route): array
    {
        $drift = [];

        foreach ($this->backendArtifacts($route) as $artifact) {
            $nodeId = $artifact['node_id'] ?? null;
            $node = is_int($nodeId) ? Node::query()->find($nodeId) : null;

            if (
                $node instanceof Node
                && $node->isActive()
                && app(NodeRoleAssignments::class)->nodeHasActiveRole($node, 'app-prod')
            ) {
                continue;
            }

            $drift[] = new DriftEntry(
                family: $this->key(),
                key: 'proxy.backend_node_invalid',
                kind: DriftKind::Divergent,
                summary: "Private backend route {$route->domain} points at an invalid backend node.",
                detail: [
                    'backend_node_id' => $nodeId,
                ],
            );
        }

        return $drift;
    }

    /**
     * @param  array<string, mixed>  $observed
     * @return list<DriftEntry>
     */
    private function checkBackendArtifactReality(ProxyRoute $route, array $observed): array
    {
        $drift = [];
        $backends = is_array($observed['backends'] ?? null) ? $observed['backends'] : [];

        foreach ($this->backendArtifacts($route) as $artifact) {
            $nodeId = $artifact['node_id'] ?? null;

            if (! is_int($nodeId)) {
                continue;
            }

            $backend = $backends[$nodeId] ?? null;

            if (! is_array($backend)) {
                continue;
            }

            if ($this->routeProbeFailed($backend)) {
                continue;
            }

            if (($backend['route_exists'] ?? null) === false) {
                $drift[] = new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.backend_route_missing',
                    kind: DriftKind::Missing,
                    summary: "Private backend route {$route->domain} is missing on backend node.",
                    detail: [
                        'backend_node_id' => $nodeId,
                    ],
                );

                continue;
            }

            if (($backend['route_hash'] ?? null) !== ($artifact['source_hash'] ?? null)) {
                $drift[] = new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.backend_route_mismatch',
                    kind: DriftKind::Divergent,
                    summary: "Private backend route {$route->domain} differs from gateway proxy intent.",
                    detail: [
                        'backend_node_id' => $nodeId,
                        'expected_hash' => $artifact['source_hash'] ?? null,
                        'observed_hash' => $backend['route_hash'] ?? null,
                    ],
                );
            }
        }

        return $drift;
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkTlsReality(ProxyRoute $route, ProbeSnapshot $snapshot): array
    {
        $observed = $snapshot->get($route->domain);

        if ($observed === null || ! $this->expectsOrbitTls($route)) {
            return [];
        }

        $observed = $this->usesIngressPlacement($route) ? $this->publicObservation($observed) : $observed;

        if ($this->routeProbeFailed($observed)) {
            return [];
        }

        if (($observed['route_exists'] ?? null) === false) {
            return [];
        }

        if (($observed['cert_exists'] ?? null) === false || ($observed['key_exists'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.tls_missing',
                    kind: DriftKind::Missing,
                    summary: "Proxy route {$route->domain} is missing Orbit-managed TLS material.",
                ),
            ];
        }

        $expected = $this->expectedTlsPaths($route);

        if (
            ($observed['cert_path'] ?? null) !== $expected['cert']
            || ($observed['key_path'] ?? null) !== $expected['key']
        ) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.tls_mismatch',
                    kind: DriftKind::Divergent,
                    summary: "Proxy route {$route->domain} TLS material does not match gateway proxy intent.",
                    detail: [
                        'expected' => $expected,
                        'observed' => [
                            'cert' => $observed['cert_path'] ?? null,
                            'key' => $observed['key_path'] ?? null,
                        ],
                    ],
                ),
            ];
        }

        $validityDays = $observed['cert_validity_days'] ?? null;

        if (($observed['cert_validity_observed'] ?? null) === true && ! is_int($validityDays)) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.tls_mismatch',
                    kind: DriftKind::Divergent,
                    summary: "Proxy route {$route->domain} TLS certificate validity could not be verified.",
                    detail: [
                        'expected' => [
                            'validity_days' => '396-397',
                        ],
                        'observed' => [
                            'validity_days' => null,
                        ],
                    ],
                ),
            ];
        }

        if (is_int($validityDays) && ! OrbitCaService::hasExpectedLeafValidity($validityDays)) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'proxy.tls_mismatch',
                    kind: DriftKind::Divergent,
                    summary: "Proxy route {$route->domain} TLS certificate validity does not match Orbit policy.",
                    detail: [
                        'expected' => [
                            'validity_days' => '396-397',
                        ],
                        'observed' => [
                            'validity_days' => $validityDays,
                        ],
                    ],
                ),
            ];
        }

        return [];
    }

    private function expectsOrbitTls(ProxyRoute $route): bool
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

        if ($managedBy === 'acme') {
            return false;
        }

        return in_array($managedBy, ['orbit', 'internal'], true);
    }

    /**
     * @return array{cert: string, key: string}
     */
    private function expectedTlsPaths(ProxyRoute $route): array
    {
        $config = is_array($route->config) ? $route->config : [];
        $cert = $config['tls']['cert_path'] ?? null;
        $key = $config['tls']['key_path'] ?? null;

        return [
            'cert' => is_string($cert) && $cert !== '' ? $cert : "/etc/orbit/certs/{$route->domain}.crt",
            'key' => is_string($key) && $key !== '' ? $key : "/etc/orbit/certs/{$route->domain}.key",
        ];
    }

    private function hasTargetShape(ProxyRoute $route): bool
    {
        $config = is_array($route->config) ? $route->config : [];

        if ($route->kind === 'redirect') {
            $target = $config['target']['value'] ?? $config['redirect'] ?? $config['redirect_url'] ?? null;
            $code = $config['code'] ?? $config['redirect_code'] ?? null;

            return is_string($target) && $target !== '' && is_int($code);
        }

        if ($route->kind === 'proxy') {
            // Router-owned service routes that carry a backend pool (e.g. websocket.orbit)
            // are validated by pool presence, not by a target value.
            if ($route->owner_type === 'router' && isset($config['router_backend_pool'])) {
                return $this->hasRouterBackendPool($config);
            }

            $target = $config['target']['value'] ?? $config['upstream'] ?? $config['target'] ?? null;

            return is_string($target) && $target !== '';
        }

        if ($route->kind === 'app') {
            return is_int($route->instance_id);
        }

        if ($route->kind === 'workspace') {
            return is_int($route->workspace_id) && is_int($route->instance_id);
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function hasRouterBackendPool(array $config): bool
    {
        $pool = $config['router_backend_pool'] ?? null;

        if (! is_array($pool) || $pool === []) {
            return false;
        }

        return array_all(
            $pool,
            fn (mixed $backend): bool => (
                is_array($backend)
                && is_string($backend['url'] ?? null)
                && $backend['url'] !== ''
            ),
        );
    }

    private function usesIngressPlacement(ProxyRoute $route): bool
    {
        $config = is_array($route->config) ? $route->config : [];

        return ($config['placement'] ?? null) === 'ingress';
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
     * @param  array<string, mixed>  $observed
     * @return array<string, mixed>
     */
    private function publicObservation(array $observed): array
    {
        $public = $observed['public'] ?? null;

        return is_array($public) ? $public : $observed;
    }

    /**
     * @param  array<string, mixed>  $observed
     * @return array<string, mixed>
     */
    private function routerObservation(array $observed): array
    {
        $router = $observed['router'] ?? null;

        return is_array($router) ? $router : [];
    }

    private function expectedSourceHash(ProxyRoute $route): string
    {
        if ($this->usesIngressPlacement($route) || ! in_array($route->kind, ['app', 'workspace'], strict: true)) {
            return is_string($route->source_hash) ? $route->source_hash : '';
        }

        try {
            $route->loadMissing(['instance.app', 'workspace.instance.app', 'node']);
            $expected = $route->replicate();
            $expected->setRelations($route->getRelations());

            return $this->renderer()->managedPhpRuntimeIntentSourceHash($expected);
        } catch (Throwable) {
            return is_string($route->source_hash) ? $route->source_hash : '';
        }
    }

    private function renderer(): ProxyRouteRenderer
    {
        return $this->renderer ?? app(ProxyRouteRenderer::class);
    }

    private function workspaceRouteOwnership(): WorkspaceProxyRouteOwnershipResolver
    {
        return $this->workspaceRouteOwnership ?? app(WorkspaceProxyRouteOwnershipResolver::class);
    }

    private function scripts(): ToolScriptDispatcher
    {
        return $this->scripts ?? app(ToolScriptDispatcher::class);
    }

    private function routeFileProbeContract(): ProxyRouteFileProbeContract
    {
        return $this->routeFileProbeContract ?? new ProxyRouteFileProbeContract;
    }

    private function shouldProbeRuntimeUpstream(ProxyRoute $route): bool
    {
        return $this->runtimeUpstreamForProbe($route) !== null;
    }

    private function runtimeUpstreamForProbe(ProxyRoute $route): ?string
    {
        if ($this->usesIngressPlacement($route) || ! in_array($route->kind, ['app', 'workspace'], strict: true)) {
            return null;
        }

        $config = is_array($route->config) ? $route->config : [];

        try {
            $route->loadMissing(['instance.app', 'workspace.instance.app', 'node']);
            $expected = $route->replicate();
            $expected->setRelations($route->getRelations());
            $this->renderer()->renderManagedPhpRuntimeIntent($expected);

            $expectedConfig = is_array($expected->config) ? $expected->config : [];
            $expectedUpstream = $expectedConfig['runtime_upstream'] ?? null;

            if (is_string($expectedUpstream) && $expectedUpstream !== '') {
                return $expectedUpstream;
            }
        } catch (Throwable) {
            return $this->configuredRuntimeUpstream($config);
        }

        return $this->configuredRuntimeUpstream($config);
    }

    /** @param array<string, mixed> $config */
    private function configuredRuntimeUpstream(array $config): ?string
    {
        if (! is_string($config['runtime_upstream'] ?? null) || $config['runtime_upstream'] === '') {
            return null;
        }

        return $config['runtime_upstream'];
    }
}
