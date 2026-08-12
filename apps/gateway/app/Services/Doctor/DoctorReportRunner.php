<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DoctorIssue;
use App\Data\Doctor\DoctorRestoreProbe;
use App\Data\Doctor\DoctorRunRequest;
use App\Data\Doctor\DoctorTargetScope;
use App\Data\Doctor\DriftEntry;
use App\Enums\DriftKind;
use App\Enums\Nodes\NodeConvergenceContext;
use App\Models\App;
use App\Models\FirewallRule;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Models\Schedule;
use App\Models\Workspace;
use App\Services\Analytics\AnalyticsProxyDoctorProbe;
use App\Services\Analytics\AnalyticsPublicProxyDoctorProbe;
use App\Services\Apps\AppsFixer;
use App\Services\Dns\DnsmasqReconciler;
use App\Services\Firewall\FirewallRuleFixer;
use App\Services\Nodes\NodeConverger;
use App\Services\Nodes\NodesProbe;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Proxy\ProxyRouteAdopter;
use App\Services\Proxy\ProxyRouteFixer;
use App\Services\Proxy\ProxyRouteProbe;
use App\Services\S3\S3ProxyDoctorProbe;
use App\Services\Schedules\SchedulesFixer;
use App\Services\Tools\ToolsFixer;
use App\Services\WebSockets\WebSocketProxyDoctorProbe;
use App\Services\Workspaces\WorkspacePlacement;
use Illuminate\Support\Collection;
use LogicException;
use Throwable;

final readonly class DoctorReportRunner
{
    public const int FLEET_PROBE_BATCH_SIZE = DoctorFleetProbeRunner::BATCH_SIZE;

    private const array VERIFIED_WEBSOCKET_RESTORE_KEYS = [
        'node.websocket.backend_cert_missing',
        'node.websocket.bind_public_interface',
    ];

    private const array VERIFIED_DNS_TOOL_RESTORE_KEYS = [
        'tool.dns_container_missing',
        'tool.dns_port_not_listening',
        'tool.dns_base_config_mismatch',
        'tool.dns_client_dns_drift',
        'tool.dns_forwarding_missing',
    ];

    public function __construct(
        private NodesProbe $nodesProbe,
        private AppsFixer $appsFixer,
        private DoctorDatabaseConnectionRestorer $databaseConnectionRestorer,
        private DoctorAdoptRunner $adoptRunner,
        private DoctorProcessRestorer $processRestorer,
        private ProxyRouteProbe $proxyRouteProbe,
        private FirewallRuleFixer $firewallRuleFixer,
        private ProxyRouteFixer $proxyRouteFixer,
        private ProxyRouteAdopter $proxyRouteAdopter,
        private NodeConverger $nodeConverger,
        private ToolsFixer $toolsFixer,
        private SchedulesFixer $schedulesFixer,
        private NodeRoleAssignments $nodeRoleAssignments,
        private DoctorNodeFamilyResolver $nodeFamilies,
        private DoctorNodeProbeRunner $nodeProbeRunner,
        private DoctorFleetProbeRunner $fleetProbeRunner,
        private DoctorFleetTargetProbe $fleetTargetProbe,
        private DoctorProxyRouteInventory $proxyRouteInventory,
        private DoctorScheduleNodeResolver $scheduleNodeResolver,
        private WebSocketProxyDoctorProbe $webSocketProxyDoctorProbe,
        private S3ProxyDoctorProbe $s3ProxyDoctorProbe,
        private AnalyticsProxyDoctorProbe $analyticsProxyDoctorProbe,
        private AnalyticsPublicProxyDoctorProbe $analyticsPublicProxyDoctorProbe,
        private DnsRuntimeProbe $dnsRuntimeProbe,
        private DnsmasqReconciler $dnsmasqReconciler,
        private DoctorIssueFactory $doctorIssueFactory,
        private DoctorReportSections $reportSections,
        private WorkspacePlacement $workspacePlacement = new WorkspacePlacement,
    ) {}

    /**
     * @return list<string>
     */
    public function supportedFamilies(): array
    {
        return $this->nodeFamilies->supportedFamilies();
    }

    /**
     * @return list<string>
     */
    public function categoriesForRole(string $role): array
    {
        return $this->nodeFamilies->categoriesForRole($role);
    }

    /**
     * @return list<string>
     */
    public function categoriesForNode(Node $node): array
    {
        return $this->nodeFamilies->categoriesForNode($node);
    }

    /**
     * @param  list<string>  $families
     * @return Collection<int, Node>
     */
    public function fleetTargetsForFamilies(array $families = []): Collection
    {
        return $this->fleetProbeRunner->targetsForFamilies($families);
    }

    /**
     * @param  list<string>  $families
     * @param  (callable(Node, 'running'|'done', ?array<string, mixed>=): void)|null  $onNodeProgress
     * @return array<string, mixed>
     */
    public function probeFleet(array $families = [], ?string $key = null, ?callable $onNodeProgress = null): array
    {
        return $this->fleetProbeRunner->probe($families, $key, $onNodeProgress);
    }

    /**
     * @param  list<string>  $families
     * @param  (callable(string, 'running'|'done', list<array<string, mixed>>, ?int, ?int): void)|null  $onFamilyProgress
     * @return array<string, mixed>
     */
    public function probeFleetTargetReport(
        Node $node,
        array $families,
        ?string $key,
        ?callable $onFamilyProgress = null,
    ): array {
        return $this->fleetTargetProbe->probe(
            node: $node,
            families: $families,
            key: $key,
            onFamilyProgress: $onFamilyProgress,
        );
    }

    /**
     * @param  list<string>  $families
     * @return array<string, mixed>
     */
    public function run(
        Node $node,
        string $mode = 'verify',
        array $families = [],
        ?DoctorRunRequest $request = null,
    ): array {
        $request ??= DoctorRunRequest::none();
        $scope = $request->targetScope();
        $key = $request->key;
        $dryRun = $request->dryRun;
        $probe = $this->probe($node, $families, $key, scope: $scope);
        $issues = $this->issuesFromProbe($probe);

        if ($mode === 'verify') {
            return $probe;
        }

        if ($dryRun) {
            return $this->finalize($probe, $mode, $this->plannedActions($mode, $issues), dryRun: true);
        }

        if ($mode === 'restore') {
            return $this->runRestoreConvergence(
                $node,
                $probe,
                $families,
                new DoctorRunRequest($key, dryRun: false, scope: $scope),
            );
        }

        /** @var list<string> $selectedFamilies */
        $selectedFamilies = $probe['scope']['families'] ?? [];
        $actions = $mode === 'adopt'
            ? (
                $key === 'node.updates'
                    ? []
                    : $this->adoptRunner->adopt(
                        $node,
                        $selectedFamilies,
                        $scope,
                    )
            )
            : $this->applyIssues($node, $mode, $issues);

        if ($mode !== 'adopt' || $key === 'node.updates') {
            $actions = [
                ...$actions,
                ...$this->actionsForUnsupportedMode($mode, $issues, $actions),
            ];
        }

        if ($mode === 'adopt') {
            // No mutation receipts: the first probe is already current. Re-probe only
            // when adopt produced any action (including skipped/unsupported).
            if ($actions === []) {
                return $this->finalize($probe, $mode, $actions);
            }

            return $this->finalizeResolution(
                $node,
                $mode,
                $actions,
                $families,
                new DoctorRunRequest($key, dryRun: false, scope: $scope),
            );
        }

        return $this->finalize($probe, $mode, $actions);
    }

    /**
     * Bounded multi-pass restore: re-apply genuine drift until clean, no progress,
     * or max passes. Scope fences (families/key/instance/workspace) are preserved
     * on every probe and apply.
     *
     * @param  array<string, mixed>  $initialProbe
     * @param  list<string>  $families
     * @return array<string, mixed>
     */
    private function runRestoreConvergence(
        Node $node,
        array $initialProbe,
        array $families,
        DoctorRunRequest $request,
    ): array {
        $scope = $request->targetScope();
        $convergence = new DoctorRestoreConvergence;
        // Apply may mutate the node row via a separately loaded model instance
        // (e.g. nodeFromIssue). Re-resolve the selected node from the database
        // for every post-mutation probe/apply so record changes are observed
        // without dropping family/key/instance/workspace fences.
        $resolveSelectedNode = function () use ($node): Node {
            $fresh = Node::query()->find($node->getKey());

            return $fresh instanceof Node ? $fresh : $node;
        };
        $pendingInitialProbe = $this->restoreProbe($initialProbe);
        $probe = function () use (
            $resolveSelectedNode,
            $families,
            $request,
            &$pendingInitialProbe,
        ): DoctorRestoreProbe {
            if ($pendingInitialProbe !== null) {
                $probe = $pendingInitialProbe;
                $pendingInitialProbe = null;

                return $probe;
            }

            $fresh = $this->probe(
                $resolveSelectedNode(),
                $families,
                $request->key,
                scope: $request->targetScope(),
            );

            return $this->restoreProbe($fresh);
        };
        $result = $convergence->run(
            probe: $probe,
            apply: fn (array $issues): array => $this->applyIssues($resolveSelectedNode(), 'restore', $issues),
            isRestorable: fn (DoctorIssue $issue): bool => $this->issueSupportsMode($issue, 'restore'),
        );

        $actions = $result['actions'];
        $finalProbe = $result['probe']->report;
        $finalIssues = $result['probe']->issues;
        $actions = [
            ...$actions,
            ...$this->actionsForUnsupportedMode('restore', $finalIssues, $actions),
        ];

        if ($actions === [] && $result['stop_reason'] === 'no_restorable') {
            return $this->attachConvergenceMetadata(
                report: $this->finalize(
                    probe: $initialProbe,
                    mode: 'restore',
                    actions: $actions,
                    authoritativeObservation: false,
                ),
                passes: 0,
                stopReason: 'no_restorable',
            );
        }

        $annotatedActions = $this->annotateRestoreActionsWithRemainingDrift(
            $actions,
            $finalIssues,
        );

        return $this->attachConvergenceMetadata(
            report: $this->finalize(
                probe: $finalProbe,
                mode: 'restore',
                actions: $annotatedActions,
                authoritativeObservation: true,
            ),
            passes: $result['passes'],
            stopReason: $result['stop_reason'],
        );
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function attachConvergenceMetadata(array $report, int $passes, string $stopReason): array
    {
        $report['convergence'] = [
            'passes' => $passes,
            'stop_reason' => $stopReason,
            'max_passes' => DoctorRestoreConvergence::MAX_PASSES,
        ];
        $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
        $report['summary'] = [
            ...$summary,
            'passes' => $passes,
            'stop_reason' => $stopReason,
        ];

        return $report;
    }

    /**
     * Re-probe the selected scope after a real restore/adopt mutation and treat
     * the fresh observation as authoritative for remaining issues and health.
     *
     * @param  list<array<string, mixed>>  $actions
     * @param  list<string>  $families
     * @return array<string, mixed>
     */
    public function finalizeResolution(
        Node $node,
        string $mode,
        array $actions,
        array $families = [],
        ?DoctorRunRequest $request = null,
    ): array {
        $request ??= DoctorRunRequest::none();
        $probe = $this->probe(
            $node,
            $families,
            $request->key,
            scope: $request->targetScope(),
        );
        $freshIssues = $this->issuesFromProbe($probe);
        // Fresh observation decides remaining issues for every resolution mode.
        // Richer per-family failure annotations are restore-only and never hide issues.
        $annotatedActions = $mode === 'restore'
            ? $this->annotateRestoreActionsWithRemainingDrift($actions, $freshIssues)
            : $actions;

        return $this->finalize(
            probe: $probe,
            mode: $mode,
            actions: $annotatedActions,
            authoritativeObservation: true,
        );
    }

    /**
     * @param  list<string>  $families
     * @param  (callable(string, 'running'|'done', list<array<string, mixed>>, ?int, ?int): void)|null  $onFamilyProgress
     * @return array<string, mixed>
     */
    public function probe(
        Node $node,
        array $families = [],
        ?string $key = null,
        ?callable $onFamilyProgress = null,
        ?DoctorTargetScope $scope = null,
    ): array {
        return $this->nodeProbeRunner->probe(
            node: $node,
            families: $families,
            key: $key,
            onFamilyProgress: $onFamilyProgress,
            scope: $scope,
        );
    }

    /**
     * @param  list<DoctorIssue>  $issues
     * @return list<array<string, mixed>>
     */
    public function apply(Node $node, string $mode, array $issues): array
    {
        return $this->applyIssues($node, $mode, $issues);
    }

    /**
     * @param  list<DoctorIssue>  $issues
     * @return list<array<string, mixed>>
     */
    private function applyIssues(Node $node, string $mode, array $issues): array
    {
        $actions = [];
        $convergenceRestoreIssues = [];

        foreach ($issues as $issue) {
            if (! $this->issueSupportsMode($issue, $mode)) {
                continue;
            }

            if ($this->productionNodeWorkspaceIssue($node, $issue)) {
                continue;
            }

            if (
                $mode === 'restore'
                && $issue->family === 'tool'
                && $this->dnsRuntimeProbe->isRestorable($issue->key)
            ) {
                $action = $this->applyDnsRuntimeIssue(
                    $node,
                    $issue->key,
                    $issue->detail,
                    $issue,
                );

                if ($action !== null) {
                    $actions[] = $action;
                }

                continue;
            }

            if (
                $mode === 'restore'
                && DoctorDnsProjectionRestoreSupport::supports($issue->key)
            ) {
                $actions[] = $this->applyDnsProjectionIssue($node, $issue);

                continue;
            }

            if (
                $mode === 'restore'
                && ($issue->family === 'tool'
                || $issue->family === 'node'
                && $issue->key === 'node.role_baseline_mismatch')
            ) {
                $convergenceRestoreIssues[] = $issue;

                continue;
            }

            $action = $this->applyIssue($node, $mode, $issue);

            if ($action !== null) {
                $actions[] = $action;
            }
        }

        if ($convergenceRestoreIssues !== []) {
            $result = $this->nodeConverger->applyIssues(
                $node,
                NodeConvergenceContext::Restore,
                $this->reportSections->serializeIssues($convergenceRestoreIssues),
            );
            $actions = [
                ...$actions,
                ...$result->actions(),
            ];
        }

        return array_map(fn (array $action): array => $this->normalizeActionMode($action, $mode), $actions);
    }

    /**
     * @param  array<string, mixed>  $probe
     * @param  list<array<string, mixed>>  $actions
     * @return array<string, mixed>
     */
    public function finalize(
        array $probe,
        string $mode,
        array $actions,
        bool $dryRun = false,
        bool $authoritativeObservation = false,
    ): array {
        $issues = $this->issuesFromProbe($probe);
        $remainingIssues = $authoritativeObservation
            ? $issues
            : $this->remainingIssues($issues, $actions);
        $summary = $this->reportSections->summary($remainingIssues, $actions);
        $summary['dispositions'] = $this->reportSections->dispositions($remainingIssues);

        $result = [
            ...$probe,
            'healthy' =>
                $summary['issues'] === 0
                    && $summary['failed'] === 0
                    && $summary['conflicts'] === 0
                    && $summary['skipped'] === 0,
            'mode' => $mode,
            'summary' => $summary,
            'issues' => $this->reportSections->serializeIssues($remainingIssues),
            'actions' => $actions,
        ];

        if ($dryRun) {
            $result['dry_run'] = true;
        }

        return $result;
    }

    private function productionNodeExcludesWorkspaces(Node $node): bool
    {
        return $this->proxyRouteInventory->nodeExcludesWorkspaces($node);
    }

    private function productionNodeWorkspaceIssue(Node $node, DoctorIssue $issue): bool
    {
        if (! $this->productionNodeExcludesWorkspaces($node)) {
            return false;
        }

        $family = $issue->family;
        $detail = $issue->detail;

        return match ($family) {
            'process' => $this->processRestorer->issueTargetsWorkspace($node, $detail),
            'proxy' => $this->proxyIssueTargetsWorkspace($detail),
            'database_connection' => $this->databaseConnectionRestorer->issueTargetsWorkspace($detail),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function proxyIssueTargetsWorkspace(array $detail): bool
    {
        $domain = is_string($detail['domain'] ?? null) ? $detail['domain'] : null;

        if ($domain === null) {
            return false;
        }

        $route = ProxyRoute::query()->where('domain', $domain)->first();

        return $route instanceof ProxyRoute && $this->proxyRouteIsWorkspaceOwned($route);
    }

    private function proxyRouteIsWorkspaceOwned(ProxyRoute $route): bool
    {
        return $this->proxyRouteInventory->routeIsWorkspaceOwned($route);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function applyIssue(Node $node, string $mode, DoctorIssue $issue): ?array
    {
        $family = $issue->family;
        $key = $issue->key;
        $detail = $issue->detail;

        return match ($family) {
            'node' => $this->applyNodeIssue($node, $key, $detail, $issue),
            'app' => $this->applyAppIssue($node, $key, $detail),
            'database_connection' => $this->databaseConnectionRestorer->apply($key, $detail),
            'workspace' => $this->applyWorkspaceIssue($node, $key, $detail),
            'process' => $this->processRestorer->apply($node, $key, $detail),
            'proxy' => $this->applyProxyIssue($node, $mode, $key, $detail, $issue),
            'firewall_rule' => $this->applyFirewallIssue($node, $key, $detail),
            'tool' => $this->applyToolIssue($node, $key, $detail),
            'schedule' => $this->applyScheduleIssue($node, $key, $detail, $issue),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function applyNodeIssue(Node $node, string $key, array $detail, DoctorIssue $issue): array
    {
        $targetNode = $this->nodeFromIssue($issue) ?? $node;
        $entry = $this->driftEntryFromStoredParts('node', $key, $detail, $issue);
        $code = $issue->code;

        try {
            $this->nodesProbe->reconcile($targetNode, $entry);
        } catch (Throwable $e) {
            return [
                'family' => 'node',
                'node' => $targetNode->name,
                'code' => $code,
                'key' => $key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to fix {$code}.",
                'details' => [
                    'error' => $e->getMessage(),
                ],
            ];
        }

        return [
            'family' => 'node',
            'node' => $targetNode->name,
            'code' => $code,
            'key' => $key,
            'mode' => 'restore',
            'status' => 'completed',
            'summary' => $issue->summary !== '' ? $issue->summary : "Fixed {$code}.",
            'details' => $detail,
        ];
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>|null
     */
    private function applyWorkspaceIssue(Node $node, string $key, array $detail): ?array
    {
        $workspaceName = is_string($detail['workspace'] ?? null) ? $detail['workspace'] : null;

        if ($workspaceName === null) {
            return null;
        }

        $appName = is_string($detail['app'] ?? null) ? $detail['app'] : null;
        $workspaces = Workspace::query()
            ->with(['app.node', 'app.instances', 'instance'])
            ->where('name', $workspaceName)
            ->whereHas('app', static function ($query) use ($appName): void {
                if ($appName !== null) {
                    $query->where('name', $appName);
                }
            })
            ->get();
        $workspace = $workspaces->first(
            fn (Workspace $workspace): bool => (
                $this->workspacePlacement->nodeForWorkspace($workspace)?->id === $node->id
            ),
        );

        if (! $workspace instanceof Workspace) {
            return null;
        }

        return $this->handleWorkspaceAction($workspace, $this->driftEntryFromStoredParts('workspace', $key, $detail));
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>|null
     */
    private function applyAppIssue(Node $node, string $key, array $detail): ?array
    {
        $appName = is_string($detail['app'] ?? null) ? $detail['app'] : null;

        if ($appName === null) {
            return null;
        }

        if ($key === 'app.runtime_config_extra') {
            return $this->handleAppConfigExtraAction($node, $appName);
        }

        $appInstanceName = is_string($detail['instance'] ?? null) ? $detail['instance'] : null;

        if ($appInstanceName !== null) {
            $app = App::query()
                ->with(['node', 'instances'])
                ->where('name', $appName)
                ->first();
            $instance = $app instanceof App
                ? $app->instances->firstWhere('name', $appInstanceName)
                : null;

            if (
                $app instanceof App
                && $instance instanceof Instance
                && $this->workspacePlacement->nodeForInstance($instance)?->id === $node->id
            ) {
                return $this->handleInstanceAction(
                    $app,
                    $instance,
                    $this->driftEntryFromStoredParts('app', $key, $detail),
                );
            }

            return null;
        }

        $app = App::query()
            ->with('node')
            ->where('node_id', $node->id)
            ->where('name', $appName)
            ->first();

        if (! $app instanceof App) {
            return null;
        }

        return $this->handleAppAction($app, $this->driftEntryFromStoredParts('app', $key, $detail));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function handleInstanceAction(App $app, Instance $instance, DriftEntry $entry): ?array
    {
        try {
            return $this->appsFixer->fixInstance($app, $instance, $entry);
        } catch (Throwable $e) {
            $node = $this->workspacePlacement->nodeForInstance($instance);

            return [
                'family' => $entry->family,
                'node' => $node?->name,
                'code' => $entry->key,
                'key' => $entry->key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to fix {$entry->key}.",
                'details' => [
                    'app' => $app->name,
                    'instance' => $instance->name,
                    'error' => $e->getMessage(),
                ],
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function handleAppConfigExtraAction(Node $node, string $appSlug): array
    {
        try {
            return $this->appsFixer->removeRuntimeConfigExtra($node, $appSlug);
        } catch (Throwable $e) {
            return [
                'family' => 'app',
                'node' => $node->name,
                'code' => 'app.runtime_config_extra',
                'key' => 'app.runtime_config_extra',
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to remove extra managed app runtime config for {$appSlug}.",
                'details' => [
                    'app' => $appSlug,
                    'error' => $e->getMessage(),
                ],
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>|null
     */
    private function applyProxyIssue(
        Node $fallbackNode,
        string $mode,
        string $key,
        array $detail,
        DoctorIssue $issue,
    ): ?array {
        $node = $this->nodeFromIssue($issue) ?? $fallbackNode;

        if (in_array($key, ['proxy.agent_tool_route_missing', 'proxy.agent_tool_route_mismatch'], true)) {
            return $this->handleAgentToolProxyAction(
                $mode,
                $node,
                $this->driftEntryFromIssue($issue),
            );
        }

        if (in_array(
            $key,
            [
                AnalyticsProxyDoctorProbe::RouterRouteKey,
                AnalyticsProxyDoctorProbe::RouterRouteOrphanedKey,
            ],
            true,
        )) {
            return $this->handleAnalyticsProxyAction($mode, $node, $this->driftEntryFromIssue($issue));
        }

        if ($key === AnalyticsPublicProxyDoctorProbe::PUBLIC_ROUTE_KEY) {
            return $this->handleAnalyticsPublicProxyAction($mode, $node, $this->driftEntryFromIssue($issue));
        }

        if ($issue->kind === DriftKind::Extra) {
            if ($mode === 'adopt') {
                $snapshot = $this->proxyRouteProbe->snapshotForAdopt($node);

                foreach ($this->proxyRouteAdopter->adopt($node, $snapshot) as $result) {
                    if ($result->key === $key) {
                        return [
                            'family' => $result->family,
                            'node' => $node->name,
                            'code' => $result->key,
                            'key' => $result->key,
                            'mode' => 'adopt',
                            'status' => $result->action->value,
                            'summary' => $result->summary,
                            'details' => $result->detail,
                        ];
                    }
                }

                return null;
            }

            return $this->handleProxyExtraAction($mode, $node, new DriftEntry(
                family: 'proxy',
                key: $key,
                kind: DriftKind::Extra,
                summary: $issue->summary !== ''
                    ? $issue->summary
                    : "Proxy route '{$key}' exists on node but not in gateway registry.",
            ));
        }

        if (in_array(
            $key,
            ['proxy.caddy_container_missing', 'proxy.caddy_container_down', 'proxy.caddy_container_detached'],
            true,
        )) {
            return $this->handleProxyCaddyContainerAction($mode, $node, $this->driftEntryFromIssue($issue));
        }

        if (in_array($key, ['proxy.global_config_missing', 'proxy.global_config_mismatch'], true)) {
            return $this->handleProxyGlobalConfigAction($mode, $node, $this->driftEntryFromIssue($issue));
        }

        if (in_array(
            $key,
            [WebSocketProxyDoctorProbe::RouterRouteKey, WebSocketProxyDoctorProbe::PublicRouteKey],
            true,
        )) {
            return $this->handleWebSocketProxyAction($mode, $node, $this->driftEntryFromIssue($issue));
        }

        if (in_array(
            $key,
            [
                S3ProxyDoctorProbe::RouterRouteKey,
                S3ProxyDoctorProbe::RouterBackendKey,
                S3ProxyDoctorProbe::PublicRouteKey,
            ],
            true,
        )) {
            return $this->handleS3ProxyAction($mode, $node, $this->driftEntryFromIssue($issue));
        }

        $domain = is_string($detail['domain'] ?? null) ? $detail['domain'] : null;

        if ($domain === null) {
            return null;
        }

        $route = ProxyRoute::query()
            ->where('domain', $domain)
            ->first();

        if (! $route instanceof ProxyRoute) {
            return null;
        }

        return $this->handleProxyAction($mode, $route, $this->driftEntryFromIssue($issue));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function handleProxyCaddyContainerAction(string $mode, Node $node, DriftEntry $entry): ?array
    {
        if ($mode === 'verify') {
            return null;
        }

        try {
            return $this->proxyRouteFixer->fixCaddyContainer($node, $entry);
        } catch (Throwable $e) {
            return [
                'family' => $entry->family,
                'node' => $node->name,
                'code' => $entry->key,
                'key' => $entry->key,
                'mode' => $mode,
                'status' => 'failed',
                'summary' => "Failed to fix {$entry->key}.",
                'details' => [
                    'error' => $e->getMessage(),
                ],
            ];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function handleAgentToolProxyAction(string $mode, Node $node, DriftEntry $entry): ?array
    {
        if ($mode === 'verify') {
            return null;
        }

        try {
            return $this->proxyRouteFixer->restoreAgentToolRoute($node, $entry);
        } catch (Throwable $e) {
            return [
                'family' => $entry->family,
                'node' => $node->name,
                'code' => $entry->key,
                'key' => $entry->key,
                'mode' => $mode,
                'status' => 'failed',
                'summary' => "Failed to fix {$entry->key}.",
                'details' => [
                    'error' => $e->getMessage(),
                ],
            ];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function handleProxyGlobalConfigAction(string $mode, Node $node, DriftEntry $entry): ?array
    {
        if ($mode === 'verify') {
            return null;
        }

        try {
            return $this->proxyRouteFixer->fixGlobalConfig($node, $entry);
        } catch (Throwable $e) {
            return [
                'family' => $entry->family,
                'node' => $node->name,
                'code' => $entry->key,
                'key' => $entry->key,
                'mode' => $mode,
                'status' => 'failed',
                'summary' => "Failed to fix {$entry->key}.",
                'details' => [
                    'error' => $e->getMessage(),
                ],
            ];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function handleWebSocketProxyAction(string $mode, Node $node, DriftEntry $entry): ?array
    {
        if ($mode === 'verify') {
            return null;
        }

        try {
            return $this->webSocketProxyDoctorProbe->restore($node, $entry);
        } catch (Throwable $e) {
            return [
                'family' => $entry->family,
                'node' => $node->name,
                'code' => $entry->key,
                'key' => $entry->key,
                'mode' => $mode,
                'status' => 'failed',
                'summary' => "Failed to fix {$entry->key}.",
                'details' => [
                    'error' => $e->getMessage(),
                ],
            ];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function handleS3ProxyAction(string $mode, Node $node, DriftEntry $entry): ?array
    {
        if ($mode === 'verify') {
            return null;
        }

        try {
            return $this->s3ProxyDoctorProbe->restore($node, $entry);
        } catch (Throwable $e) {
            return [
                'family' => $entry->family,
                'node' => $node->name,
                'code' => $entry->key,
                'key' => $entry->key,
                'mode' => $mode,
                'status' => 'failed',
                'summary' => "Failed to fix {$entry->key}.",
                'details' => [
                    'error' => $e->getMessage(),
                ],
            ];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function handleAnalyticsProxyAction(string $mode, Node $node, DriftEntry $entry): ?array
    {
        if ($mode === 'verify') {
            return null;
        }

        try {
            return $this->analyticsProxyDoctorProbe->restore($node, $entry);
        } catch (Throwable $throwable) {
            return [
                'family' => $entry->family,
                'node' => $node->name,
                'code' => $entry->key,
                'key' => $entry->key,
                'mode' => $mode,
                'status' => 'failed',
                'summary' => "Failed to fix {$entry->key}.",
                'details' => [
                    'error' => $throwable->getMessage(),
                ],
            ];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function handleAnalyticsPublicProxyAction(string $mode, Node $node, DriftEntry $entry): ?array
    {
        if ($mode === 'verify') {
            return null;
        }

        try {
            return $this->analyticsPublicProxyDoctorProbe->restore($node, $entry);
        } catch (Throwable $throwable) {
            return [
                'family' => $entry->family,
                'node' => $node->name,
                'code' => $entry->key,
                'key' => $entry->key,
                'mode' => $mode,
                'status' => 'failed',
                'summary' => "Failed to fix {$entry->key}.",
                'details' => [
                    'error' => $throwable->getMessage(),
                ],
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>|null
     */
    private function applyFirewallIssue(Node $node, string $key, array $detail): ?array
    {
        $ruleName = is_string($detail['rule'] ?? null) ? $detail['rule'] : null;

        if ($ruleName === null) {
            return null;
        }

        $rule = FirewallRule::query()
            ->where('node_id', $node->id)
            ->where('name', $ruleName)
            ->first();

        return $rule instanceof FirewallRule
            ? $this->handleFirewallAction(
                'restore',
                $rule,
                $this->driftEntryFromStoredParts('firewall_rule', $key, $detail),
            )
            : null;
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>|null
     */
    private function applyToolIssue(Node $node, string $key, array $detail): ?array
    {
        $toolName = is_string($detail['tool'] ?? null) ? $detail['tool'] : null;

        if ($toolName === null) {
            return null;
        }

        $tool = NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', $toolName)
            ->first();

        return $tool instanceof NodeTool
            ? $this->handleToolAction('restore', $tool, $this->driftEntryFromStoredParts('tool', $key, $detail))
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function applyDnsProjectionIssue(Node $node, DoctorIssue $issue): array
    {
        $key = $issue->key;
        $family = $key === 'node.dns_mapping_mismatch' ? 'node' : 'proxy';
        $targetNode = $this->nodeFromIssue($issue) ?? $node;
        $detail = $issue->detail;

        if (! DoctorDnsProjectionRestoreSupport::supports($key)) {
            return [
                'family' => $family,
                'node' => $targetNode->name,
                'code' => $key,
                'key' => $key,
                'mode' => 'restore',
                'status' => 'skipped',
                'summary' => "No restore action is registered for {$key}.",
                'details' => [
                    ...$detail,
                    'reason' => 'mode_not_supported',
                ],
            ];
        }

        if (! $this->dnsmasqReconciler->projectionDirectoryIsMounted()) {
            return [
                'family' => $family,
                'node' => $targetNode->name,
                'code' => $key,
                'key' => $key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to fix {$key}.",
                'details' => [
                    ...$detail,
                    'error' => 'The live orbit-dns runtime does not consume the managed projection directory.',
                ],
            ];
        }

        try {
            match ($key) {
                'node.dns_mapping_mismatch' => $this->dnsmasqReconciler->reconcileNodeRecords(),
                'proxy.dns_mapping_mismatch' => $this->dnsmasqReconciler->reconcileProxyRecords(),
                default => throw new LogicException("Unsupported DNS projection issue [{$key}]."),
            };
        } catch (Throwable $throwable) {
            return [
                'family' => $family,
                'node' => $targetNode->name,
                'code' => $key,
                'key' => $key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to fix {$key}.",
                'details' => [
                    ...$detail,
                    'error' => $throwable->getMessage(),
                ],
            ];
        }

        return [
            'family' => $family,
            'node' => $targetNode->name,
            'code' => $key,
            'key' => $key,
            'mode' => 'restore',
            'status' => 'completed',
            'summary' => $issue->summary !== '' ? $issue->summary : "Fixed {$key}.",
            'details' => $detail,
        ];
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>|null
     */
    private function applyDnsRuntimeIssue(
        Node $node,
        string $key,
        array $detail,
        DoctorIssue $issue,
    ): ?array {
        if (! $this->dnsRuntimeProbe->isRestorable($key)) {
            return null;
        }

        try {
            $restored = $this->dnsRuntimeProbe->restore($key);
        } catch (Throwable $e) {
            return [
                'family' => 'tool',
                'node' => $node->name,
                'code' => $key,
                'key' => $key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to fix {$key}.",
                'details' => [
                    ...$detail,
                    'error' => $e->getMessage(),
                ],
            ];
        }

        return [
            'family' => 'tool',
            'node' => $node->name,
            'code' => $key,
            'key' => $key,
            'mode' => 'restore',
            'status' => $restored ? 'completed' : 'failed',
            'summary' => $restored
                ? ($issue->summary !== '' ? $issue->summary : "Fixed {$key}.")
                : "Failed to fix {$key}.",
            'details' => $restored
                ? $detail
                : [
                    ...$detail,
                    'error' => 'restore_returned_false',
                ],
        ];
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>|null
     */
    private function applyScheduleIssue(
        Node $node,
        string $key,
        array $detail,
        DoctorIssue $issue,
    ): ?array {
        $scheduleKey = is_string($detail['schedule_key'] ?? null) ? $detail['schedule_key'] : null;

        if (in_array($key, SchedulesFixer::GatewayRestorableCodes, true)) {
            $gatewayNode = $this->gatewayNode() ?? $this->nodeFromIssue($issue) ?? $node;
            $schedule = $scheduleKey === null
                ? null
                : Schedule::query()->where('schedule_key', $scheduleKey)->first();

            try {
                $fixed = $this->schedulesFixer->fixGateway(
                    $gatewayNode,
                    $this->driftEntryFromStoredParts('schedule', $key, $detail, $issue),
                    $schedule instanceof Schedule ? $schedule : null,
                );

                return $fixed ?? [
                    'family' => 'schedule',
                    'node' => $gatewayNode->name,
                    'code' => $key,
                    'key' => $key,
                    'mode' => 'restore',
                    'status' => 'skipped',
                    'summary' => "No restore action is registered for {$key}.",
                    'details' => [
                        'reason' => 'mode_not_supported',
                    ],
                ];
            } catch (Throwable $e) {
                return [
                    'family' => 'schedule',
                    'node' => $gatewayNode->name,
                    'code' => $key,
                    'key' => $key,
                    'mode' => 'restore',
                    'status' => 'failed',
                    'summary' => "Failed to fix {$key}.",
                    'details' => [
                        'error' => $e->getMessage(),
                    ],
                ];
            }
        }

        if ($scheduleKey === null) {
            return null;
        }

        $schedule = Schedule::query()->where('schedule_key', $scheduleKey)->first();

        return $schedule instanceof Schedule
            ? $this->handleScheduleAction(
                'restore',
                $schedule,
                $this->driftEntryFromStoredParts('schedule', $key, $detail),
            )
            : null;
    }

    private function driftEntryFromIssue(DoctorIssue $issue): DriftEntry
    {
        return new DriftEntry(
            family: $issue->family,
            key: $issue->key,
            kind: $issue->kind,
            summary: $issue->summary,
            detail: $issue->detail,
        );
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function driftEntryFromStoredParts(
        string $family,
        string $key,
        array $detail,
        ?DoctorIssue $issue = null,
    ): DriftEntry {
        return new DriftEntry(
            family: $family,
            key: $key,
            kind: $issue?->kind ?? DriftKind::Divergent,
            summary: $issue?->summary ?? '',
            detail: $detail,
        );
    }

    private function nodeFromIssue(DoctorIssue $issue): ?Node
    {
        $nodeName = $issue->node;

        if ($nodeName === null) {
            return null;
        }

        $node = Node::query()->where('name', $nodeName)->first();

        return $node instanceof Node ? $node : null;
    }

    private function issueSupportsMode(DoctorIssue $issue, string $mode): bool
    {
        if ($mode === 'adopt') {
            return $issue->adoptable;
        }

        return $issue->restorable;
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    private function normalizeActionMode(array $action, string $mode): array
    {
        if (($action['mode'] ?? null) === 'fix') {
            $action['mode'] = match ($mode) {
                'interactive', 'restore' => 'restore',
                'adopt' => 'adopt',
                default => $mode,
            };
        }

        return $action;
    }

    /**
     * @param  list<DoctorIssue>  $issues
     * @param  list<array<string, mixed>>  $actions
     * @return list<DoctorIssue>
     */
    private function remainingIssues(array $issues, array $actions): array
    {
        $resolvedIssueIds = array_filter(array_map(
            fn (array $action): ?string => in_array(
                $action['status'] ?? null,
                ['completed', 'created', 'updated'],
                true,
            )
                    ? $this->issueResolutionId($action)
                    : null,
            $actions,
        ));
        $resolvedDatabaseTargets = array_values(array_filter(array_map(
            $this->databaseConnectionResolutionKey(...),
            $actions,
        )));

        return array_values(array_filter(
            $issues,
            fn (DoctorIssue $issue): bool => (
                ! in_array($this->doctorIssueResolutionId($issue), $resolvedIssueIds, true)
                && ! $this->databaseConnectionIssueResolved($issue, $resolvedDatabaseTargets)
            ),
        ));
    }

    /**
     * Enrich restore action receipts when family-specific re-probe still finds
     * matching drift. Does not filter issues; fresh observation remains
     * authoritative in finalizeResolution.
     *
     * @param  list<array<string, mixed>>  $actions
     * @param  list<DoctorIssue>  $remainingIssues
     * @return list<array<string, mixed>>
     */
    private function annotateRestoreActionsWithRemainingDrift(
        array $actions,
        array $remainingIssues,
    ): array {
        $annotatedActions = [];

        foreach ($actions as $action) {
            $annotatedActions[] = $this->verifyCompletedDnsToolAction(
                $this->verifyCompletedWebSocketAction(
                    $this->verifyCompletedNodeDnsAction(
                        $this->verifyCompletedProxyAction($action, $remainingIssues),
                        $remainingIssues,
                    ),
                    $remainingIssues,
                ),
                $remainingIssues,
            );
        }

        return $annotatedActions;
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  list<DoctorIssue>  $remainingIssues
     * @return array<string, mixed>
     */
    private function verifyCompletedDnsToolAction(array $action, array $remainingIssues): array
    {
        if (
            ($action['status'] ?? null) !== 'completed'
            || ($action['family'] ?? null) !== 'tool'
            || ! in_array($action['key'] ?? null, self::VERIFIED_DNS_TOOL_RESTORE_KEYS, true)
        ) {
            return $action;
        }

        $remainingIssue = collect($remainingIssues)->first(
            fn (DoctorIssue $issue): bool => (
                $issue->family === 'tool'
                && in_array($issue->key, self::VERIFIED_DNS_TOOL_RESTORE_KEYS, true)
            ),
        );

        if (! $remainingIssue instanceof DoctorIssue) {
            return $action;
        }

        $key = $remainingIssue->key;
        $issueDetail = $remainingIssue->detail;
        $details = is_array($action['details'] ?? null) ? $action['details'] : [];

        return [
            ...$action,
            'status' => 'failed',
            'summary' => 'DNS runtime restore verification failed.',
            'details' => [
                ...$details,
                ...$issueDetail,
                'operation' => "verify {$key}",
                'error' => "DNS runtime drift [{$key}] remained after restore.",
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  list<DoctorIssue>  $remainingIssues
     * @return array<string, mixed>
     */
    private function verifyCompletedNodeDnsAction(array $action, array $remainingIssues): array
    {
        if (
            ($action['status'] ?? null) !== 'completed'
            || ($action['family'] ?? null) !== 'node'
            || ($action['key'] ?? null) !== 'node.dns_mapping_mismatch'
        ) {
            return $action;
        }

        $remainingIssue = collect($remainingIssues)->first(
            fn (DoctorIssue $issue): bool => (
                $issue->family === 'node'
                && $issue->key === 'node.dns_mapping_mismatch'
                && (
                    $this->stringValue($action, 'node') === null
                    || $this->stringValue($action, 'node') === $issue->node
                )
            ),
        );

        if (! $remainingIssue instanceof DoctorIssue) {
            return $action;
        }

        $node = $remainingIssue->node ?? $this->stringValue($action, 'node') ?? 'unknown';
        $issueDetail = $remainingIssue->detail;
        $details = is_array($action['details'] ?? null) ? $action['details'] : [];

        return [
            ...$action,
            'status' => 'failed',
            'summary' => "Node DNS restore verification failed on node '{$node}'.",
            'details' => [
                ...$details,
                ...$issueDetail,
                'node' => $node,
                'operation' => 'verify node.dns_mapping_mismatch',
                'error' => "Node DNS drift remained after restore on node '{$node}'.",
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  list<DoctorIssue>  $remainingIssues
     * @return array<string, mixed>
     */
    private function verifyCompletedProxyAction(array $action, array $remainingIssues): array
    {
        if (
            ($action['status'] ?? null) !== 'completed'
            || ($action['family'] ?? null) !== 'proxy'
        ) {
            return $action;
        }

        $remainingIssue = collect($remainingIssues)->first(
            fn (DoctorIssue $issue): bool => $this->actionMatchesRemainingIssue($action, $issue),
        );

        if (! $remainingIssue instanceof DoctorIssue) {
            return $action;
        }

        return $this->failedProxyAction($action, $remainingIssue);
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  list<DoctorIssue>  $remainingIssues
     * @return array<string, mixed>
     */
    private function verifyCompletedWebSocketAction(array $action, array $remainingIssues): array
    {
        if (
            ($action['status'] ?? null) !== 'completed'
            || ($action['family'] ?? null) !== 'node'
            || ! in_array($action['key'] ?? null, self::VERIFIED_WEBSOCKET_RESTORE_KEYS, true)
        ) {
            return $action;
        }

        $remainingIssue = collect($remainingIssues)->first(
            fn (DoctorIssue $issue): bool => (
                $issue->family === 'node'
                && (
                    $this->stringValue($action, 'node') === null
                    || $this->stringValue($action, 'node') === $issue->node
                )
                && $this->issueResolutionId($action) === $this->doctorIssueResolutionId($issue)
            ),
        );

        if (! $remainingIssue instanceof DoctorIssue) {
            return $action;
        }

        $node = $remainingIssue->node ?? $this->stringValue($action, 'node') ?? 'unknown';
        $key = $remainingIssue->key;
        $issueDetail = $remainingIssue->detail;
        $details = is_array($action['details'] ?? null) ? $action['details'] : [];

        return [
            ...$action,
            'status' => 'failed',
            'summary' => "WebSocket restore verification failed on node '{$node}'.",
            'details' => [
                ...$details,
                ...$issueDetail,
                'node' => $node,
                'operation' => "verify {$key}",
                'error' => "WebSocket drift '{$key}' remained after restore on node '{$node}'.",
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    private function failedProxyAction(array $action, DoctorIssue $remainingIssue): array
    {
        $node = $remainingIssue->node ?? $this->stringValue($action, 'node') ?? 'unknown';
        $key = $remainingIssue->key;
        $operation = "verify {$key}";
        $issueDetail = $remainingIssue->detail;
        $details = is_array($action['details'] ?? null) ? $action['details'] : [];

        return [
            ...$action,
            'status' => 'failed',
            'summary' => "Proxy restore verification failed on node '{$node}' during '{$operation}'.",
            'details' => [
                ...$details,
                ...$issueDetail,
                'node' => $node,
                'operation' => $operation,
                'error' => "Drift remained after repair on node '{$node}' during '{$operation}'.",
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function actionMatchesRemainingIssue(array $action, DoctorIssue $issue): bool
    {
        if (($action['family'] ?? null) !== 'proxy' || $issue->family !== 'proxy') {
            return false;
        }

        $actionDetails = is_array($action['details'] ?? null) ? $action['details'] : [];
        $issueDetail = $issue->detail;
        $actionDomain = is_string($actionDetails['route'] ?? null) ? $actionDetails['route'] : null;
        $issueDomain = is_string($issueDetail['domain'] ?? null) ? $issueDetail['domain'] : null;

        if ($actionDomain !== null) {
            return $actionDomain === $issueDomain;
        }

        $actionNode = is_string($action['node'] ?? null) ? $action['node'] : null;
        $issueNode = $issue->node;

        return (
            ($actionNode === null || $actionNode === $issueNode)
            && $this->issueResolutionId($action) === $this->doctorIssueResolutionId($issue)
        );
    }

    /**
     * @param  array<string, mixed>  $probe
     * @return list<DoctorIssue>
     */
    private function issuesFromProbe(array $probe): array
    {
        $probeIssues = is_array($probe['issues'] ?? null) ? $probe['issues'] : [];

        return $this->issuesFromValues($probeIssues);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<DoctorIssue>
     */
    private function issuesFromValues(array $values): array
    {
        $issues = [];

        foreach ($values as $value) {
            if ($value instanceof DoctorIssue) {
                $issues[] = $value;
            } elseif (is_array($value)) {
                /** @var array<string, mixed> $value */
                $issues[] = $this->doctorIssueFactory->fromArray($value);
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function restoreProbe(array $report): DoctorRestoreProbe
    {
        return new DoctorRestoreProbe(
            report: $report,
            issues: $this->issuesFromProbe($report),
        );
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function stringValue(array $item, string $key): ?string
    {
        $value = $item[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function issueResolutionId(array $item): ?string
    {
        $family = is_string($item['family'] ?? null) ? $item['family'] : null;
        $key = is_string($item['key'] ?? null) ? $item['key'] : null;

        if ($family === null || $key === null) {
            return null;
        }

        $code = is_string($item['code'] ?? null) ? $item['code'] : $key;

        return "{$family}:{$key}:{$code}";
    }

    private function doctorIssueResolutionId(DoctorIssue $issue): string
    {
        return "{$issue->family}:{$issue->key}:{$issue->code}";
    }

    /**
     * @param  list<string>  $resolvedTargets
     */
    private function databaseConnectionIssueResolved(DoctorIssue $issue, array $resolvedTargets): bool
    {
        if ($issue->family !== 'database_connection') {
            return false;
        }

        $detail = $issue->detail;
        $key = implode(':', [
            (string) ($detail['target_type'] ?? ''),
            (string) ($detail['target_id'] ?? ''),
            (string) ($detail['env_prefix'] ?? ''),
        ]);

        return in_array($key, $resolvedTargets, true);
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function databaseConnectionResolutionKey(array $action): ?string
    {
        if (
            ($action['family'] ?? null) !== 'database_connection'
            || ! in_array($action['status'] ?? null, ['completed', 'created', 'updated'], true)
        ) {
            return null;
        }

        $detail = is_array($action['details'] ?? null) ? $action['details'] : [];

        return implode(':', [
            (string) ($detail['target_type'] ?? ''),
            (string) ($detail['target_id'] ?? ''),
            (string) ($detail['env_prefix'] ?? ''),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function handleProxyAction(string $mode, ProxyRoute $route, DriftEntry $entry): ?array
    {
        if ($mode === 'verify') {
            return null;
        }

        try {
            return $this->proxyRouteFixer->fix($route, $entry);
        } catch (Throwable $e) {
            $route->loadMissing('node');

            return [
                'family' => $entry->family,
                'node' => $route->node->name,
                'code' => $entry->key,
                'key' => $entry->key,
                'mode' => $mode,
                'status' => 'failed',
                'summary' => "Failed to fix {$entry->key}.",
                'details' => [
                    'error' => $e->getMessage(),
                ],
            ];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function handleProxyExtraAction(string $mode, Node $node, DriftEntry $entry): ?array
    {
        if ($mode === 'verify') {
            return null;
        }

        try {
            return $this->proxyRouteFixer->removeExtra($node, $entry->key);
        } catch (Throwable $e) {
            return [
                'family' => $entry->family,
                'node' => $node->name,
                'code' => $entry->key,
                'key' => $entry->key,
                'mode' => $mode,
                'status' => 'failed',
                'summary' => "Failed to remove extra proxy route {$entry->key}.",
                'details' => [
                    'error' => $e->getMessage(),
                ],
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function handleWorkspaceAction(Workspace $workspace, DriftEntry $entry): array
    {
        $workspace->loadMissing('app.node');

        return [
            'family' => $entry->family,
            'node' => $this->workspacePlacement->nodeForWorkspace($workspace)?->name,
            'code' => $entry->key,
            'key' => $entry->key,
            'mode' => 'restore',
            'status' => 'skipped',
            'summary' => "Skipped fix for {$entry->key}: workspace auto-fix is not supported in the Docker-first runtime.",
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function handleAppAction(App $app, DriftEntry $entry): ?array
    {
        try {
            return $this->appsFixer->fix($app, $entry);
        } catch (Throwable $e) {
            $app->loadMissing('node');

            return [
                'family' => $entry->family,
                'node' => $app->node?->name,
                'code' => $entry->key,
                'key' => $entry->key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to fix {$entry->key}.",
                'details' => [
                    'error' => $e->getMessage(),
                ],
            ];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function handleFirewallAction(string $mode, FirewallRule $rule, DriftEntry $entry): ?array
    {
        if ($mode === 'verify') {
            return null;
        }

        try {
            return $this->firewallRuleFixer->fix($rule, $entry);
        } catch (Throwable $e) {
            $rule->loadMissing('node');

            return [
                'family' => $entry->family,
                'node' => $rule->node->name,
                'code' => $entry->key,
                'key' => $entry->key,
                'mode' => $mode,
                'status' => 'failed',
                'summary' => "Failed to fix {$entry->key}.",
                'details' => [
                    'error' => $e->getMessage(),
                ],
            ];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function handleToolAction(string $mode, NodeTool $tool, DriftEntry $entry): ?array
    {
        if ($mode === 'verify') {
            return null;
        }

        try {
            return $this->toolsFixer->fix($tool, $entry);
        } catch (Throwable $e) {
            $tool->loadMissing('node');

            return [
                'family' => $entry->family,
                'node' => $tool->node?->name,
                'code' => $entry->key,
                'key' => $entry->key,
                'mode' => $mode,
                'status' => 'failed',
                'summary' => "Failed to fix {$entry->key}.",
                'details' => [
                    'error' => $e->getMessage(),
                ],
            ];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function handleScheduleAction(string $mode, Schedule $schedule, DriftEntry $entry): ?array
    {
        if ($mode === 'verify') {
            return null;
        }

        try {
            return $this->schedulesFixer->fix($schedule, $entry);
        } catch (Throwable $e) {
            return [
                'family' => $entry->family,
                'node' => $this->scheduleNodeResolver->nameFor($schedule),
                'code' => $entry->key,
                'key' => $entry->key,
                'mode' => $mode,
                'status' => 'failed',
                'summary' => "Failed to fix {$entry->key}.",
                'details' => [
                    'error' => $e->getMessage(),
                ],
            ];
        }
    }

    private function gatewayNode(): ?Node
    {
        $node = $this->nodeRoleAssignments->activeGatewayNodeQuery()->first();

        return $node instanceof Node ? $node : null;
    }

    /**
     * @param  list<DoctorIssue>  $issues
     * @param  list<array<string, mixed>>  $existingActions
     * @return list<array<string, mixed>>
     */
    private function actionsForUnsupportedMode(string $mode, array $issues, array $existingActions): array
    {
        if ($mode === 'verify') {
            return [];
        }

        $actionIds = array_filter(array_map(
            $this->issueResolutionId(...),
            $existingActions,
        ));

        return array_values(array_map(
            fn (DoctorIssue $issue): array => $this->unsupportedAction($mode, $issue),
            array_filter(
                $issues,
                fn (DoctorIssue $issue): bool => ! in_array(
                    $this->doctorIssueResolutionId($issue),
                    $actionIds,
                    true,
                ),
            ),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function unsupportedAction(string $mode, DoctorIssue $issue): array
    {
        return [
            'family' => $issue->family,
            'node' => $issue->node,
            'code' => $issue->code,
            'key' => $issue->key,
            'mode' => $mode,
            'status' => 'skipped',
            'summary' => "No {$mode} action is registered for {$issue->code}.",
            'details' => [
                'reason' => 'mode_not_supported',
            ],
        ];
    }

    /**
     * @param  list<DoctorIssue>  $issues
     * @return list<array<string, mixed>>
     */
    private function plannedActions(string $mode, array $issues): array
    {
        return array_values(array_map(
            fn (DoctorIssue $issue): array => $this->issueSupportsMode($issue, $mode)
                ? $this->plannedAction($mode, $issue)
                : $this->unsupportedAction($mode, $issue),
            $issues,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function plannedAction(string $mode, DoctorIssue $issue): array
    {
        return [
            'family' => $issue->family,
            'node' => $issue->node,
            'code' => $issue->code,
            'key' => $issue->key,
            'mode' => $mode,
            'status' => 'planned',
            'summary' => "Would {$mode} {$issue->code}.",
            'details' => [
                ...$issue->detail,
                'dry_run' => true,
            ],
        ];
    }
}
