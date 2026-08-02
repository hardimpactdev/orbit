<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Actions\Apps\EnsureAppProcessRuntimeUnits;
use App\Contracts\SiteCertificateInstaller;
use App\Data\Doctor\DoctorRunRequest;
use App\Data\Doctor\DoctorTargetScope;
use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppRuntimeKind;
use App\Enums\Apps\NodeRuntimeConfigsProbeStatus;
use App\Enums\DriftKind;
use App\Enums\Nodes\NodeConvergenceContext;
use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Nodes\NodeStatus;
use App\Exceptions\RemoteShellFailed;
use App\Models\AppInstance;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Models\Process;
use App\Models\Project;
use App\Models\ProxyRoute;
use App\Models\Schedule;
use App\Models\Workspace;
use App\Services\Analytics\AnalyticsProxyDoctorProbe;
use App\Services\Analytics\AnalyticsPublicProxyDoctorProbe;
use App\Services\Apps\AppDevelopmentInnerTlsPolicy;
use App\Services\Apps\AppRuntimeContainer;
use App\Services\Apps\AppRuntimeContainerManager;
use App\Services\Apps\AppRuntimeContainerRenderer;
use App\Services\Apps\AppRuntimeRequirementProbe;
use App\Services\Apps\AppsFixer;
use App\Services\Apps\AppsProbe;
use App\Services\Ca\OrbitCaService;
use App\Services\DatabaseConnections\DatabaseConnectionAdopter;
use App\Services\DatabaseConnections\DatabaseConnectionProbe;
use App\Services\DatabaseConnections\DatabaseConnectionRestorer;
use App\Services\Dns\DnsmasqReconciler;
use App\Services\Firewall\FirewallRuleFixer;
use App\Services\Firewall\FirewallRuleProbe;
use App\Services\Nodes\NodeConverger;
use App\Services\Nodes\NodeHostPaths;
use App\Services\Nodes\NodesProbe;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Processes\EnsureFrankenPhpRuntimeProcess;
use App\Services\Processes\ProcessDockerRuntimeManager;
use App\Services\Processes\ProcessesProbe;
use App\Services\Processes\ProcessEventNotifierRenderer;
use App\Services\Processes\ProcessOwnerContext;
use App\Services\Processes\ProcessRuntimeDriverRegistry;
use App\Services\Processes\ProcessServiceCatalog;
use App\Services\Proxy\ProxyRouteAdopter;
use App\Services\Proxy\ProxyRouteFixer;
use App\Services\Proxy\ProxyRouteProbe;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\RemoteShell\RemoteLocalExecutorTransportFailed;
use App\Services\Runtime\DockerCommandBuilder;
use App\Services\S3\S3DoctorProbe;
use App\Services\S3\S3ProxyDoctorProbe;
use App\Services\Schedules\SchedulesFixer;
use App\Services\Schedules\SchedulesProbe;
use App\Services\Tools\ToolsFixer;
use App\Services\Tools\ToolsProbe;
use App\Services\WebSockets\WebSocketDoctorProbe;
use App\Services\WebSockets\WebSocketProxyDoctorProbe;
use App\Services\Workspaces\WorkspacePlacement;
use App\Services\Workspaces\WorkspaceRuntimeContainer;
use App\Services\Workspaces\WorkspaceRuntimeContainerManager;
use App\Services\Workspaces\WorkspaceRuntimeContainerRenderer;
use App\Services\Workspaces\WorkspacesProbe;
use Closure;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process as ProcessFacade;
use JsonException;
use LogicException;
use Orbit\Core\Enums\InternalCommand;
use RuntimeException;
use Throwable;

final readonly class DoctorReportRunner
{
    public const int FLEET_PROBE_BATCH_SIZE = 5;

    private const int FLEET_PROBE_POLL_INTERVAL_MICROSECONDS = 50_000;

    private const array WORKSPACE_PROCESS_OWNER_TYPES = [Workspace::class, 'workspace'];

    private const array WORKSPACE_PROXY_OWNER_TYPES = ['workspace', Workspace::class];

    private const array SUPPORTED_FAMILIES = [
        'node',
        'app',
        'workspace',
        'process',
        'proxy',
        'firewall_rule',
        'tool',
        'schedule',
        'database_connection',
    ];

    private const array CONTROL_CATEGORIES = ['node'];

    private const array GATEWAY_CATEGORIES = ['node', 'schedule'];

    private const array APP_CATEGORIES = [
        'node',
        'app',
        'workspace',
        'process',
        'proxy',
        'tool',
        'database_connection',
    ];

    private const array APP_PRODUCTION_CATEGORIES = [
        'node',
        'app',
        'process',
        'proxy',
        'tool',
        'database_connection',
    ];

    private const array DATABASE_CATEGORIES = ['node', 'tool', 'process'];

    private const array AGENT_CATEGORIES = ['node', 'tool', 'proxy'];

    private const array INGRESS_CATEGORIES = ['node', 'proxy', 'tool'];

    private const array ROUTER_CATEGORIES = ['node', 'proxy'];

    private const array WEBSOCKET_CATEGORIES = ['node', 'tool'];

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

    private const array S3_CATEGORIES = ['node', 'tool', 'proxy'];

    private const array METRICS_CATEGORIES = ['node', 'tool', 'process', 'proxy'];

    private const array ROLE_CATEGORY_PRIORITY = [
        NodeRoleName::Gateway->value,
        NodeRoleName::AppDevelopment->value,
        NodeRoleName::AppProduction->value,
        NodeRoleName::Database->value,
        NodeRoleName::Agent->value,
        NodeRoleName::Ingress->value,
        NodeRoleName::Router->value,
        NodeRoleName::WebSocket->value,
        NodeRoleName::S3->value,
        NodeRoleName::Metrics->value,
    ];

    public function __construct(
        private NodesProbe $nodesProbe,
        private AppsProbe $appsProbe,
        private AppsFixer $appsFixer,
        private DatabaseConnectionProbe $databaseConnectionProbe,
        private DatabaseConnectionRestorer $databaseConnectionRestorer,
        private DatabaseConnectionAdopter $databaseConnectionAdopter,
        private WorkspacesProbe $workspacesProbe,
        private ProcessesProbe $processesProbe,
        private ProcessRuntimeDriverRegistry $processRuntimeDrivers,
        private ProcessServiceCatalog $processServiceCatalog,
        private ProxyRouteProbe $proxyRouteProbe,
        private FirewallRuleProbe $firewallRuleProbe,
        private FirewallRuleFixer $firewallRuleFixer,
        private ProxyRouteFixer $proxyRouteFixer,
        private ProxyRouteAdopter $proxyRouteAdopter,
        private NodeConverger $nodeConverger,
        private ToolsProbe $toolsProbe,
        private ToolsFixer $toolsFixer,
        private SchedulesProbe $schedulesProbe,
        private SchedulesFixer $schedulesFixer,
        private NodeRoleAssignments $nodeRoleAssignments,
        private WebSocketDoctorProbe $webSocketDoctorProbe,
        private WebSocketProxyDoctorProbe $webSocketProxyDoctorProbe,
        private S3DoctorProbe $s3DoctorProbe,
        private S3ProxyDoctorProbe $s3ProxyDoctorProbe,
        private AnalyticsProxyDoctorProbe $analyticsProxyDoctorProbe,
        private AnalyticsPublicProxyDoctorProbe $analyticsPublicProxyDoctorProbe,
        private AppRuntimeRequirementProbe $appRuntimeRequirementProbe,
        private DnsRuntimeProbe $dnsRuntimeProbe,
        private NodeDnsProjectionProbe $nodeDnsProjectionProbe,
        private ProxyDnsProjectionProbe $proxyDnsProjectionProbe,
        private DnsmasqReconciler $dnsmasqReconciler,
        private WorkspacePlacement $workspacePlacement = new WorkspacePlacement,
        private NodeHostPaths $nodeHostPaths = new NodeHostPaths,
    ) {}

    /**
     * @return list<string>
     */
    public function supportedFamilies(): array
    {
        return self::SUPPORTED_FAMILIES;
    }

    /**
     * @return Collection<int, Workspace>
     */
    private function workspacesForNode(Node $node): Collection
    {
        $workspaces = Workspace::query()
            ->with(['app.node', 'app.instances', 'appInstance'])
            ->get()
            ->filter(
                fn (Workspace $workspace): bool => (
                    $this->workspacePlacement->nodeForWorkspace($workspace)?->id === $node->id
                ),
            )
            ->values();

        /** @var Collection<int, Workspace> $workspaces */
        return $workspaces;
    }

    /**
     * @return Collection<int, Process>
     */
    /**
     * @return Collection<int, Process>
     */
    private function processesForNode(Node $node): Collection
    {
        $placement = app(WorkspacePlacement::class);
        /** @var list<int> $placedInstanceIds */
        $placedInstanceIds = [];

        foreach (AppInstance::query()->with('app')->get() as $instance) {
            if ($placement->nodeForInstance($instance)?->is($node) === true) {
                $placedInstanceIds[] = $instance->id;
            }
        }

        /** @var Collection<int, Process> $candidates */
        $candidates = $this
            ->processQueryForNode($node, $placedInstanceIds)
            ->with(['owner', 'appInstance', 'node'])
            ->get();

        /** @var Collection<int, Process> $filtered */
        $filtered = $candidates
            ->filter(fn (Process $process): bool => $this->processBelongsToNode($process, $node, $placement))
            ->values();

        return $filtered;
    }

    /**
     * @param  list<int>  $placedInstanceIds
     * @return Builder<Process>
     */
    private function processQueryForNode(Node $node, array $placedInstanceIds = []): Builder
    {
        // Candidate set: denormalized node_id match, or current instance
        // placement. Final membership is decided by processBelongsToNode().
        /** @var Builder<Process> $query */
        $query = Process::query()->where(function (Builder $builder) use ($node, $placedInstanceIds): void {
            $builder->where('node_id', $node->id);

            if ($placedInstanceIds !== []) {
                $builder->orWhereIn('app_instance_id', $placedInstanceIds);
            }
        });

        if ($this->productionNodeExcludesWorkspaces($node)) {
            $query->whereNotIn('owner_type', self::WORKSPACE_PROCESS_OWNER_TYPES);
        }

        return $query;
    }

    private function processBelongsToNode(
        Process $process,
        Node $node,
        ?WorkspacePlacement $placement = null,
    ): bool {
        $process->loadMissing(['owner', 'appInstance']);
        $placement ??= app(WorkspacePlacement::class);

        if ($process->owner instanceof Node) {
            return $process->owner->is($node);
        }

        if ($process->owner instanceof Workspace) {
            $placed = $placement->nodeForWorkspace($process->owner);

            return $placed instanceof Node && $placed->is($node);
        }

        if ($process->appInstance instanceof AppInstance) {
            $placed = $placement->nodeForInstance($process->appInstance);

            return $placed instanceof Node && $placed->is($node);
        }

        return $process->node_id === $node->id;
    }

    /**
     * @return list<string>
     */
    public function categoriesForRole(string $role): array
    {
        return match ($role) {
            'operator' => self::CONTROL_CATEGORIES,
            NodeRoleName::Gateway->value => self::GATEWAY_CATEGORIES,
            NodeRoleName::AppDevelopment->value => self::APP_CATEGORIES,
            NodeRoleName::AppProduction->value => self::APP_PRODUCTION_CATEGORIES,
            NodeRoleName::Database->value => self::DATABASE_CATEGORIES,
            NodeRoleName::Agent->value => self::AGENT_CATEGORIES,
            NodeRoleName::Ingress->value => self::INGRESS_CATEGORIES,
            NodeRoleName::Router->value => self::ROUTER_CATEGORIES,
            NodeRoleName::WebSocket->value => self::WEBSOCKET_CATEGORIES,
            NodeRoleName::S3->value => self::S3_CATEGORIES,
            NodeRoleName::Metrics->value => self::METRICS_CATEGORIES,
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    public function categoriesForNode(Node $node): array
    {
        $categories = [];
        $hasActiveRole = false;

        foreach (self::ROLE_CATEGORY_PRIORITY as $role) {
            if (! $this->nodeRoleAssignments->nodeHasActiveRole($node, $role)) {
                continue;
            }

            $hasActiveRole = true;
            $categories = [
                ...$categories,
                ...$this->categoriesForRole($role),
            ];
        }

        if (! $hasActiveRole) {
            $hasActiveRole = $this->nodeHasAnyActiveRole($node);
        }

        if ($categories === []) {
            $categories = self::CONTROL_CATEGORIES;
        }

        if ($hasActiveRole) {
            $categories[] = 'process';
        }

        if (
            $this->nodeRoleAssignments->nodeIsGateway($node)
            && $this->nodeRoleAssignments->nodeHasActiveVpnRole($node)
        ) {
            $categories[] = 'tool';
        }

        if (
            ! in_array('tool', $categories, true)
            && NodeTool::query()->where('node_id', $node->id)->exists()
        ) {
            $categories[] = 'tool';
        }

        if (
            $node->isActive()
            && $this->isUbuntuPlatform($node)
            && $this->nodeRoleAssignments->nodeCanOwnFirewallRules($node)
        ) {
            $categories[] = 'firewall_rule';
        }

        if (
            ! in_array('schedule', $categories, true)
            && $this->expectedSchedulesTargetingNode($node)->exists()
        ) {
            $categories[] = 'schedule';
        }

        return array_values(array_unique($categories));
    }

    /**
     * @param  list<string>  $families
     * @return Collection<int, Node>
     */
    public function fleetTargetsForFamilies(array $families = []): Collection
    {
        /** @var Collection<int, Node> $nodes */
        $nodes = Node::query()
            ->where('status', NodeStatus::Active->value)
            ->whereHas('roleAssignments', fn ($query) => $query->where('status', NodeRoleStatus::Active->value))
            ->with('roleAssignments')
            ->orderBy('name')
            ->get()
            ->filter(fn (Node $node): bool => $this->nodeSupportsFamilies($node, $families))
            ->values();

        return $nodes;
    }

    /**
     * @param  list<string>  $families
     * @param  (callable(Node, 'running'|'done', ?array<string, mixed>=): void)|null  $onNodeProgress
     * @return array<string, mixed>
     */
    public function probeFleet(array $families = [], ?string $key = null, ?callable $onNodeProgress = null): array
    {
        $targets = $this->fleetTargetsForFamilies($families);
        $targetNames = $targets
            ->map(fn (Node $node): string => $node->name)
            ->values()
            ->all();
        /** @var list<array{node: string, status: string, completed?: int, total?: int}> $nodeProgressStatuses */
        $nodeProgressStatuses = array_map(
            static fn (string $nodeName): array => ['node' => $nodeName, 'status' => 'queued'],
            $targetNames,
        );
        /** @var list<array<string, mixed>> $issues */
        $issues = [];
        /** @var list<array<string, mixed>> $nodes */
        $nodes = [];
        $state = new FleetProbeRunState(
            scope: new FleetProbeScope(
                targets: $targets,
                families: $families,
                key: $key,
                onNodeProgress: $onNodeProgress === null ? null : Closure::fromCallable($onNodeProgress),
            ),
            nodeProgressStatuses: $nodeProgressStatuses,
            issues: $issues,
            nodes: $nodes,
        );

        $this->probeFleetTargets($state);

        $nodeProgressStatuses = $state->nodeProgressStatuses;
        $issues = $state->issues;
        $nodes = $state->nodes;

        $summary = $this->summary('verify', $issues, []);

        return [
            'healthy' => $issues === [],
            'mode' => 'verify',
            'scope' => [
                'families' => $this->fleetFamilies($targets, $families),
                'node' => null,
                'role' => 'fleet',
                'self' => false,
                'app' => null,
                'workspace' => null,
                'key' => $key,
                'targets' => $targets
                    ->map(fn (Node $node): string => $node->name)
                    ->values()
                    ->all(),
            ],
            'summary' => $summary,
            'issues' => $issues,
            'actions' => [],
            'nodes' => $nodes,
        ];
    }

    private function probeFleetTargets(FleetProbeRunState $state): void
    {
        if ($this->canRunFleetProbeProcessWorkers()) {
            $this->probeFleetTargetsConcurrently($state);

            return;
        }

        $this->probeFleetTargetsSequentially($state);
    }

    private function probeFleetTargetsSequentially(FleetProbeRunState $state): void
    {
        foreach ($state->scope->targets as $nodeIndex => $node) {
            $this->probeFleetTarget(
                node: $node,
                nodeIndex: $nodeIndex,
                state: $state,
            );
        }
    }

    private function probeFleetTargetsConcurrently(FleetProbeRunState $state): void
    {
        $nodeList = array_values($state->scope->targets->all());
        /** @var array<int, int> $doneFamiliesByWorkerIndex */
        $doneFamiliesByWorkerIndex = [];
        /** @var array<int, array{node: Node, process: InvokedProcess, outputBuffer: string, onFamilyProgress: callable}> $workers */
        $workers = [];
        $nextIndex = 0;

        while ($nextIndex < count($nodeList) || $workers !== []) {
            while (count($workers) < self::FLEET_PROBE_BATCH_SIZE && $nextIndex < count($nodeList)) {
                $node = $nodeList[$nextIndex];
                $state->nodeProgressStatuses[$nextIndex]['status'] = 'running';

                if ($state->scope->onNodeProgress !== null) {
                    ($state->scope->onNodeProgress)($node, 'running');
                }

                $process = $this->startFleetProbeProcessWorker($node, $state->scope->families, $state->scope->key);

                if ($process === null) {
                    $this->completeFleetProbeTarget(
                        node: $node,
                        nodeIndex: $nextIndex,
                        state: $state,
                        report: $this->probeFleetTargetReport($node, $state->scope->families, $state->scope->key),
                    );
                    $nextIndex++;

                    continue;
                }

                $roleCategories = $this->categoriesForNode($node);
                $selectedFamilies = $state->scope->families === []
                    ? $roleCategories
                    : array_values(array_intersect($state->scope->families, $roleCategories));
                $doneFamiliesByWorkerIndex[$nextIndex] = 0;

                $workers[$nextIndex] = [
                    'node' => $node,
                    'process' => $process,
                    'outputBuffer' => '',
                    'onFamilyProgress' => $this->fleetNodeFamilyProgressReporter(
                        node: $node,
                        nodeIndex: $nextIndex,
                        totalFamilies: count($selectedFamilies),
                        doneFamilies: $doneFamiliesByWorkerIndex[$nextIndex],
                        state: $state,
                    ),
                ];
                $nextIndex++;
            }

            foreach (array_keys($workers) as $index) {
                $worker = $workers[$index];
                $process = $worker['process'];

                try {
                    if (method_exists($process, 'ensureNotTimedOut')) {
                        $process->ensureNotTimedOut();
                    }

                    if ($process->running()) {
                        $this->pollFleetProbeProcessWorkerProgress($workers[$index]);

                        continue;
                    }

                    $this->pollFleetProbeProcessWorkerProgress($workers[$index]);
                } catch (ProcessTimedOutException) {
                    $report = $this->probeFleetTargetReport(
                        node: $worker['node'],
                        families: $state->scope->families,
                        key: $state->scope->key,
                    );
                    $this->completeFleetProbeTarget(
                        node: $worker['node'],
                        nodeIndex: $index,
                        state: $state,
                        report: $report,
                    );
                    unset($workers[$index]);
                    unset($doneFamiliesByWorkerIndex[$index]);

                    continue;
                }

                $report = $this->resolveFleetProbeProcessReport(
                    node: $worker['node'],
                    process: $process,
                    families: $state->scope->families,
                    key: $state->scope->key,
                );

                $this->completeFleetProbeTarget(
                    node: $worker['node'],
                    nodeIndex: $index,
                    state: $state,
                    report: $report,
                );

                unset($workers[$index]);
                unset($doneFamiliesByWorkerIndex[$index]);
            }

            if ($workers !== []) {
                usleep(self::FLEET_PROBE_POLL_INTERVAL_MICROSECONDS);
            }
        }

        $state->nodes = $this->orderedFleetNodeSummaries($state->scope->targets, $state->nodesByIndex);
        $state->issues = $this->orderedFleetIssues($state->scope->targets, $state->issuesByIndex);
    }

    private function probeFleetTarget(
        Node $node,
        int $nodeIndex,
        FleetProbeRunState $state,
    ): void {
        $state->nodeProgressStatuses[$nodeIndex]['status'] = 'running';

        if ($state->scope->onNodeProgress !== null) {
            ($state->scope->onNodeProgress)($node, 'running');
        }

        $roleCategories = $this->categoriesForNode($node);
        $selectedFamilies = $state->scope->families === []
            ? $roleCategories
            : array_values(array_intersect($state->scope->families, $roleCategories));
        $totalFamilies = count($selectedFamilies);
        $doneFamilies = 0;
        $onFamilyProgress = $this->fleetNodeFamilyProgressReporter(
            node: $node,
            nodeIndex: $nodeIndex,
            totalFamilies: $totalFamilies,
            doneFamilies: $doneFamilies,
            state: $state,
        );

        $this->completeFleetProbeTarget(
            node: $node,
            nodeIndex: $nodeIndex,
            state: $state,
            report: $this->probeFleetTargetReport(
                node: $node,
                families: $state->scope->families,
                key: $state->scope->key,
                onFamilyProgress: $onFamilyProgress,
            ),
        );
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
        try {
            return $this->probe($node, families: $families, key: $key, onFamilyProgress: $onFamilyProgress);
        } catch (RemoteShellFailed $exception) {
            return $this->nodeProbeFailedReport($node, $families, $key, $exception);
        } catch (RemoteLocalExecutorTransportFailed $exception) {
            return $this->nodeLocalExecutorProbeFailedReport($node, $families, $key, $exception);
        }
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function completeFleetProbeTarget(
        Node $node,
        int $nodeIndex,
        FleetProbeRunState $state,
        array $report,
    ): void {
        $nodeSummary = $this->fleetNodeSummary($node, $report);
        $nodeIssues = $this->fleetNodeIssues($report);

        $state->issuesByIndex[$nodeIndex] = $nodeIssues;
        $state->issues = $this->orderedFleetIssues($state->scope->targets, $state->issuesByIndex);
        $state->nodesByIndex[$nodeIndex] = $nodeSummary;
        $state->nodes = $this->orderedFleetNodeSummaries($state->scope->targets, $state->nodesByIndex);
        $state->nodeProgressStatuses[$nodeIndex]['status'] = 'done';

        if ($state->scope->onNodeProgress !== null) {
            ($state->scope->onNodeProgress)(
                $node,
                'done',
                $this->fleetProgressReport(
                    targets: $state->scope->targets,
                    scope: [
                        'families' => $state->scope->families,
                        'key' => $state->scope->key,
                    ],
                    issues: $state->issues,
                    nodes: $state->nodes,
                    nodeProgressStatuses: $state->nodeProgressStatuses,
                ),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function fleetNodeSummary(Node $node, array $report): array
    {
        $reportSummary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
        $reportScope = is_array($report['scope'] ?? null) ? $report['scope'] : [];

        return [
            'node' => $node->name,
            'role' => $node->displayRole(),
            'healthy' => ($report['healthy'] ?? false) === true,
            'families' => is_array($reportScope['families'] ?? null) ? $reportScope['families'] : [],
            'summary' => $reportSummary,
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<array<string, mixed>>
     */
    private function fleetNodeIssues(array $report): array
    {
        $reportIssues = is_array($report['issues'] ?? null) ? $report['issues'] : [];
        $issues = [];

        foreach ($reportIssues as $reportIssue) {
            if (! is_array($reportIssue)) {
                continue;
            }

            /** @var array<string, mixed> $reportIssue */
            $issues[] = $reportIssue;
        }

        return $issues;
    }

    /**
     * @param  Collection<int, Node>  $targets
     * @param  array<int, array<string, mixed>>  $nodesByIndex
     * @return list<array<string, mixed>>
     */
    private function orderedFleetNodeSummaries(Collection $targets, array $nodesByIndex): array
    {
        $nodes = [];

        foreach ($targets->values() as $index => $target) {
            if (! isset($nodesByIndex[$index])) {
                continue;
            }

            $nodes[] = $nodesByIndex[$index];
        }

        return $nodes;
    }

    /**
     * @param  Collection<int, Node>  $targets
     * @param  array<int, list<array<string, mixed>>>  $issuesByIndex
     * @return list<array<string, mixed>>
     */
    private function orderedFleetIssues(Collection $targets, array $issuesByIndex): array
    {
        $issues = [];

        foreach ($targets->values() as $index => $_target) {
            if (! isset($issuesByIndex[$index])) {
                continue;
            }

            foreach ($issuesByIndex[$index] as $issue) {
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    private function canRunFleetProbeProcessWorkers(): bool
    {
        if (! function_exists('proc_open')) {
            return false;
        }

        $defaultConnection = (string) config('database.default');
        $database = config("database.connections.{$defaultConnection}.database");

        return is_string($database) && $database !== '' && $database !== ':memory:';
    }

    /**
     * @param  list<string>  $families
     */
    private function startFleetProbeProcessWorker(Node $node, array $families, ?string $key): ?InvokedProcess
    {
        $command = [
            'php',
            'artisan',
            'orbit:internal:doctor-fleet-probe-node',
            '--node='.$node->name,
            '--no-ansi',
        ];

        foreach ($families as $family) {
            $command[] = '--families='.$family;
        }

        if ($key !== null) {
            $command[] = '--key='.$key;
        }

        try {
            return ProcessFacade::path(base_path())
                ->timeout(600)
                ->env($this->fleetProbeProcessEnvironment())
                ->start($command);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, string>
     */
    private function fleetProbeProcessEnvironment(): array
    {
        $defaultConnection = (string) config('database.default');
        $environment = [
            'APP_ENV' => (string) config('app.env'),
            'DB_CONNECTION' => $defaultConnection,
        ];

        $database = config("database.connections.{$defaultConnection}.database");

        if (is_string($database) && $database !== '') {
            $environment['DB_DATABASE'] = $database;
        }

        $appKey = config('app.key');

        if (is_string($appKey) && $appKey !== '') {
            $environment['APP_KEY'] = $appKey;
        }

        return $environment;
    }

    /**
     * @param  list<string>  $families
     * @return array<string, mixed>
     */
    private function resolveFleetProbeProcessReport(
        Node $node,
        InvokedProcess $process,
        array $families,
        ?string $key,
    ): array {
        try {
            $result = $process->wait();
        } catch (Throwable) {
            return $this->probeFleetTargetReport($node, $families, $key);
        }

        if (! $result->successful()) {
            return $this->probeFleetTargetReport($node, $families, $key);
        }

        $report = $this->decodeFleetProbeProcessReport($result->output());

        if ($report === null) {
            return $this->probeFleetTargetReport($node, $families, $key);
        }

        return $report;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeFleetProbeProcessReport(string $output): ?array
    {
        $lines = array_values(array_filter(array_map(trim(...), explode("\n", $output))));

        foreach (array_reverse($lines) as $line) {
            try {
                /** @var array<string, mixed> $payload */
                $payload = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            $report = $payload['report'] ?? null;

            if (! is_array($report)) {
                continue;
            }

            /** @var array<string, mixed> $report */
            return $report;
        }

        return null;
    }

    /**
     * @param  array{node: Node, process: InvokedProcess, outputBuffer: string, onFamilyProgress: callable}  $worker
     */
    private function pollFleetProbeProcessWorkerProgress(array &$worker): void
    {
        $chunk = $worker['process']->latestOutput();

        if ($chunk === '') {
            return;
        }

        $worker['outputBuffer'] .= $chunk;

        while (($newlinePosition = strpos($worker['outputBuffer'], needle: "\n")) !== false) {
            $line = substr($worker['outputBuffer'], offset: 0, length: $newlinePosition);
            $worker['outputBuffer'] = substr($worker['outputBuffer'], offset: $newlinePosition + 1);

            $this->applyFleetProbeProcessWorkerProgressLine($line, $worker['onFamilyProgress']);
        }
    }

    /**
     * @param  callable(string, 'running'|'done', list<array<string, mixed>>, ?int, ?int): void  $onFamilyProgress
     */
    private function applyFleetProbeProcessWorkerProgressLine(string $line, callable $onFamilyProgress): void
    {
        $progress = $this->decodeFleetProbeProcessProgressLine($line);

        if ($progress === null) {
            return;
        }

        $family = is_string($progress['family'] ?? null) ? $progress['family'] : '';
        $phase = is_string($progress['phase'] ?? null) ? $progress['phase'] : '';

        if ($family === '' || $phase !== 'running' && $phase !== 'done') {
            return;
        }

        $completedValue = $progress['completed'] ?? null;
        $totalValue = $progress['total'] ?? null;

        $completed = is_int($completedValue) ? $completedValue : null;
        $total = is_int($totalValue) ? $totalValue : null;

        $onFamilyProgress($family, $phase, [], $completed, $total);
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function decodeFleetProbeProcessProgressLine(string $line): ?array
    {
        $line = trim($line);

        if ($line === '') {
            return null;
        }

        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($line, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! array_key_exists('progress', $payload) || ! is_array($payload['progress'])) {
            return null;
        }

        return $payload['progress'];
    }

    /**
     * @param  array<string, mixed>|null  $partialFleetReport
     */
    private function invokeFleetNodeProgress(
        mixed $onNodeProgress,
        Node $node,
        string $phase,
        ?array $partialFleetReport = null,
    ): void {
        if (! is_callable($onNodeProgress)) {
            return;
        }

        if ($phase !== 'running' && $phase !== 'done') {
            return;
        }

        $onNodeProgress($node, $phase, $partialFleetReport);
    }

    private function fleetNodeFamilyProgressReporter(
        Node $node,
        int $nodeIndex,
        int $totalFamilies,
        int &$doneFamilies,
        FleetProbeRunState $state,
    ): callable {
        return function (
            string $family,
            string $phase,
            array $familyIssues = [],
            ?int $completed = null,
            ?int $total = null,
        ) use ($node, $nodeIndex, $totalFamilies, &$doneFamilies, $state): void {
            if ($phase === 'running') {
                if ($completed !== null && $total !== null && $total > 0) {
                    $nodeCompleted = ($doneFamilies * $total) + $completed;
                    $nodeTotal = $totalFamilies * $total;

                    if ($nodeCompleted < $nodeTotal) {
                        $this->emitFleetNodeProgress($node, $nodeIndex, $state, $nodeCompleted, $nodeTotal);
                    }
                } elseif ($totalFamilies > 0 && $doneFamilies < $totalFamilies) {
                    $this->emitFleetNodeProgress($node, $nodeIndex, $state, $doneFamilies, $totalFamilies);
                }
            }

            if ($phase === 'done') {
                $doneFamilies++;
            }
        };
    }

    private function emitFleetNodeProgress(
        Node $node,
        int $nodeIndex,
        FleetProbeRunState $state,
        int $completed,
        int $total,
    ): void {
        $entry = ['node' => $node->name, 'status' => 'running'];

        if ($total > 0 && $completed < $total) {
            $entry['completed'] = $completed;
            $entry['total'] = $total;
        }

        $state->nodeProgressStatuses[$nodeIndex] = $entry;
        $runningPhase = 'running';

        $this->invokeFleetNodeProgress(
            $state->scope->onNodeProgress,
            $node,
            $runningPhase,
            $this->fleetProgressReport(
                targets: $state->scope->targets,
                scope: [
                    'families' => $state->scope->families,
                    'key' => $state->scope->key,
                ],
                issues: $state->issues,
                nodes: $state->nodes,
                nodeProgressStatuses: $state->nodeProgressStatuses,
            ),
        );
    }

    /**
     * @param  Collection<int, Node>  $targets
     * @param  array{families: list<string>, key: string|null}  $scope
     * @param  list<array<string, mixed>>  $issues
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array{node: string, status: string, completed?: int, total?: int}>  $nodeProgressStatuses
     * @return array<string, mixed>
     */
    private function fleetProgressReport(
        Collection $targets,
        array $scope,
        array $issues,
        array $nodes,
        array $nodeProgressStatuses,
    ): array {
        return [
            'healthy' => $issues === [],
            'mode' => 'verify',
            'scope' => [
                'families' => $this->fleetFamilies($targets, $scope['families']),
                'node' => null,
                'role' => 'fleet',
                'self' => false,
                'app' => null,
                'workspace' => null,
                'key' => $scope['key'],
                'targets' => $targets
                    ->map(fn (Node $node): string => $node->name)
                    ->values()
                    ->all(),
            ],
            'summary' => $this->summary('verify', $issues, []),
            'issues' => $issues,
            'actions' => [],
            'nodes' => $this->fleetProgressNodes($targets, $scope['families'], $nodes),
            'progress' => [
                'state' => 'running',
                'nodes' => $nodeProgressStatuses,
            ],
        ];
    }

    /**
     * @param  Collection<int, Node>  $targets
     * @param  list<string>  $families
     * @param  list<array<string, mixed>>  $completedNodes
     * @return list<array<string, mixed>>
     */
    private function fleetProgressNodes(
        Collection $targets,
        array $families,
        array $completedNodes,
    ): array {
        /** @var array<string, array<string, mixed>> $completedByName */
        $completedByName = [];

        foreach ($completedNodes as $node) {
            $name = is_string($node['node'] ?? null) ? trim($node['node']) : '';

            if ($name !== '') {
                $completedByName[$name] = $node;
            }
        }

        $fleetFamilies = $this->fleetFamilies($targets, $families);

        $nodes = [];

        foreach ($targets as $target) {
            $nodes[] = $completedByName[$target->name] ?? [
                'node' => $target->name,
                'role' => $target->displayRole(),
                'healthy' => true,
                'families' => $fleetFamilies,
                'summary' => ['issues' => 0],
            ];
        }

        return $nodes;
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

        if ($mode === 'verify') {
            return $probe;
        }

        if ($dryRun) {
            return $this->finalize($probe, $mode, $this->plannedActions($mode, $probe['issues'] ?? []), dryRun: true);
        }

        $actions = $mode === 'adopt'
            ? (
                $key === 'node.updates'
                    ? []
                    : $this->adoptSelectedFamilies(
                        $node,
                        $probe['scope']['families'] ?? [],
                        $scope,
                    )
            )
            : $this->apply($node, $mode, $probe['issues'] ?? []);

        if ($mode !== 'adopt' || $key === 'node.updates') {
            $actions = [
                ...$actions,
                ...$this->actionsForUnsupportedMode($mode, $probe['issues'] ?? [], $actions),
            ];
        }

        if ($this->restoreRequiresVerification($mode, $key, $probe)) {
            return $this->finalizeRestore($node, $families, $key, $scope, $actions);
        }

        return $this->finalize($probe, $mode, $actions);
    }

    /**
     * @param  list<string>  $families
     * @param  list<array<string, mixed>>  $actions
     * @return array<string, mixed>
     */
    public function finalizeRestore(
        Node $node,
        array $families,
        ?string $key,
        DoctorTargetScope $scope,
        array $actions,
    ): array {
        $probe = $this->probe($node, $families, $key, scope: $scope);

        return $this->finalize(
            $probe,
            'restore',
            $this->markVerifiedRestoreActionsWithRemainingDriftAsFailed(
                $actions,
                $this->issuesFromProbe($probe),
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $probe
     */
    public function restoreRequiresVerification(string $mode, ?string $key, array $probe): bool
    {
        if ($mode !== 'restore') {
            return false;
        }

        if ($key === 'node.updates') {
            return true;
        }

        $scope = is_array($probe['scope'] ?? null) ? $probe['scope'] : [];
        $families = is_array($scope['families'] ?? null) ? $scope['families'] : [];

        if (in_array('proxy', $families, true)) {
            return true;
        }

        if (is_string($key) && in_array($key, self::VERIFIED_WEBSOCKET_RESTORE_KEYS, true)) {
            return true;
        }

        if ($key === 'node.dns_mapping_mismatch') {
            return true;
        }

        if (is_string($key) && in_array($key, self::VERIFIED_DNS_TOOL_RESTORE_KEYS, true)) {
            return true;
        }

        return array_any(
            $this->issuesFromProbe($probe),
            fn (array $issue): bool => (
                ($issue['key'] ?? null) === 'node.dns_mapping_mismatch'
                || in_array($issue['key'] ?? null, self::VERIFIED_DNS_TOOL_RESTORE_KEYS, true)
                || in_array(
                    $issue['key'] ?? null,
                    self::VERIFIED_WEBSOCKET_RESTORE_KEYS,
                    true,
                )
            ),
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
        $scope ??= DoctorTargetScope::none();
        $roleCategories = $this->categoriesForNode($node);
        $selectedFamilies = $families === []
            ? $roleCategories
            : array_values(array_intersect($families, $roleCategories));
        $issues = [];

        if (in_array('node', $selectedFamilies, true)) {
            $familyIssueOffset = count($issues);
            $nodeCheckTotal =
                2
                + ($this->activeWebSocketAssignment($node) instanceof NodeRoleAssignment ? 1 : 0)
                + ($this->activeS3Assignment($node) instanceof NodeRoleAssignment ? 1 : 0);

            $this->runFamilyCheckPlan($onFamilyProgress, 'node', $nodeCheckTotal, function (callable $advance) use (
                $node,
                $key,
                &$issues,
            ): void {
                $snapshot = $this->nodesProbe->introspect($node);
                $issues = [
                    ...$issues,
                    ...array_map(
                        fn (DriftEntry $entry): array => $this->issuePayload($entry, $node),
                        $this->nodesProbe->diff($node, $snapshot, $key),
                    ),
                ];
                $advance();

                // Fleet node DNS projection is only consumed by the DNS runtime on
                // the VPN/gateway host. Probe once there and attribute each source
                // node's fragment mismatch to that source — never fan out the same
                // shared artifact path across non-consumer nodes.
                if ($this->shouldProbeNodeDnsProjection($node)) {
                    foreach ($this->nodeDnsProjectionSources() as $source) {
                        foreach ($this->nodeDnsProjectionProbe->drift($source) as $entry) {
                            $issues[] = $this->nodeScopedIssuePayload($entry, $source);
                        }
                    }
                }

                $advance();

                $webSocketAssignment = $this->activeWebSocketAssignment($node);

                if ($webSocketAssignment instanceof NodeRoleAssignment) {
                    foreach ($this->webSocketDoctorProbe->nodeDrift($node, $webSocketAssignment) as $entry) {
                        $issues[] = $this->nodeScopedIssuePayload($entry, $node);
                    }

                    $advance();
                }

                $s3Assignment = $this->activeS3Assignment($node);

                if ($s3Assignment instanceof NodeRoleAssignment) {
                    foreach ($this->s3DoctorProbe->nodeDrift($node, $s3Assignment) as $entry) {
                        $issues[] = $this->nodeScopedIssuePayload($entry, $node);
                    }

                    $advance();
                }
            });

            $this->reportFamilyProgress(
                $onFamilyProgress,
                'node',
                'done',
                $this->filterIssuesByKey(array_slice($issues, $familyIssueOffset), $key),
            );
        }

        if (in_array('app', $selectedFamilies, true)) {
            $familyIssueOffset = count($issues);
            $appInstances = $this->scopedAppInstances($this->appInstancesForNode($node), $scope);
            $includeNodeConfigInventory = $scope->app === null && $scope->appInstanceId === null;
            $appCheckTotal = $appInstances->count() + ($includeNodeConfigInventory ? 1 : 0);

            $this->runFamilyCheckPlan($onFamilyProgress, 'app', $appCheckTotal, function (callable $advance) use (
                $appInstances,
                $includeNodeConfigInventory,
                $node,
                &$issues,
            ): void {
                $this->probeAppFamily(
                    $node,
                    $appInstances,
                    $includeNodeConfigInventory,
                    $issues,
                    $advance,
                );
            });

            $this->reportFamilyProgress(
                $onFamilyProgress,
                'app',
                'done',
                $this->filterIssuesByKey(array_slice($issues, $familyIssueOffset), $key),
            );
        }

        if (in_array('workspace', $selectedFamilies, true)) {
            $familyIssueOffset = count($issues);
            $workspaces = $this->workspacesForNode($node);

            $this->runFamilyCheckPlan(
                $onFamilyProgress,
                'workspace',
                $workspaces->count(),
                function (callable $advance) use ($workspaces, &$issues): void {
                    foreach ($workspaces as $workspace) {
                        $snapshot = $this->workspacesProbe->introspect($workspace);

                        foreach ($this->workspacesProbe->diff($workspace, $snapshot) as $entry) {
                            $issues[] = $this->workspaceIssuePayload($entry, $workspace);
                        }

                        $advance();
                    }
                },
            );

            $this->reportFamilyProgress(
                $onFamilyProgress,
                'workspace',
                'done',
                $this->filterIssuesByKey(array_slice($issues, $familyIssueOffset), $key),
            );
        }

        if (in_array('process', $selectedFamilies, true)) {
            $familyIssueOffset = count($issues);
            $missingRuntimeProcessIssues = $this->missingFrankenPhpRuntimeProcessIssues($node);
            $processes = $this->processesForNode($node);
            $this->runFamilyCheckPlan(
                $onFamilyProgress,
                'process',
                count($missingRuntimeProcessIssues) + $processes->count() + 1,
                function (callable $advance) use ($missingRuntimeProcessIssues, $node, $processes, &$issues): void {
                    foreach ($processes as $process) {
                        $snapshot = $this->processesProbe->introspect($process);

                        foreach ($this->processesProbe->diff($process, $snapshot) as $entry) {
                            $issues[] = $this->processIssuePayload($entry, $process);
                        }

                        $advance();
                    }

                    foreach ($missingRuntimeProcessIssues as $issue) {
                        $issues[] = $issue;
                        $advance();
                    }

                    $runtimeContainers = $this->processesProbe->introspectNodeRuntimeContainers($node);

                    foreach ($this->processesProbe->diffNodeRuntimeContainers(
                        $node,
                        $runtimeContainers,
                        $this->activePhpRuntimeSlugsForNode($node),
                    ) as $entry) {
                        $issues[] = $this->nodeScopedIssuePayload($entry, $node);
                    }

                    $advance();
                },
            );

            $this->reportFamilyProgress(
                $onFamilyProgress,
                'process',
                'done',
                $this->filterIssuesByKey(array_slice($issues, $familyIssueOffset), $key),
            );
        }

        if (in_array('proxy', $selectedFamilies, true)) {
            $familyIssueOffset = count($issues);
            $proxyRoutes = $this->proxyRoutesForScope($node, $scope);
            $proxyCheckTotal =
                $proxyRoutes->count()
                + 2
                + ($scope->app === null && $scope->workspace === null ? 1 : 0)
                + ($this->shouldProbeProxyDnsProjection($node, $scope) ? 1 : 0)
                + ($node->isActive() && $this->nodeRoleAssignments->nodeHostsOrbitCaddy($node) ? 1 : 0)
                + ($node->isActive() && $this->nodeRoleAssignments->nodeHostsOrbitCaddy($node) ? 1 : 0);

            $this->runFamilyCheckPlan($onFamilyProgress, 'proxy', $proxyCheckTotal, function (callable $advance) use (
                $node,
                $proxyRoutes,
                $scope,
                &$issues,
            ): void {
                $this->probeProxyFamily($node, $proxyRoutes, $scope, $issues, $advance);
            });

            $this->reportFamilyProgress(
                $onFamilyProgress,
                'proxy',
                'done',
                $this->filterIssuesByKey(array_slice($issues, $familyIssueOffset), $key),
            );
        }

        if (in_array('firewall_rule', $selectedFamilies, true)) {
            $familyIssueOffset = count($issues);
            $this->runFamilyCheckPlan(
                $onFamilyProgress,
                'firewall_rule',
                FirewallRule::query()->with('node')->where('node_id', $node->id)->count(),
                function (callable $advance) use ($node, &$issues): void {
                    foreach (FirewallRule::query()->with('node')->where('node_id', $node->id)->get() as $rule) {
                        $snapshot = $this->firewallRuleProbe->introspect($rule);

                        foreach ($this->firewallRuleProbe->diff($rule, $snapshot) as $entry) {
                            $issues[] = $this->firewallIssuePayload($entry, $rule);
                        }

                        $advance();
                    }
                },
            );

            $this->reportFamilyProgress(
                $onFamilyProgress,
                'firewall_rule',
                'done',
                $this->filterIssuesByKey(array_slice($issues, $familyIssueOffset), $key),
            );
        }

        if (in_array('tool', $selectedFamilies, true)) {
            $familyIssueOffset = count($issues);
            $toolCheckTotal =
                NodeTool::query()->where('node_id', $node->id)->count()
                + ($this->activeWebSocketAssignment($node) instanceof NodeRoleAssignment ? 1 : 0)
                + ($this->activeS3Assignment($node) instanceof NodeRoleAssignment ? 1 : 0)
                + ($this->shouldProbeDnsRuntime($node) ? 1 : 0);

            $this->runFamilyCheckPlan($onFamilyProgress, 'tool', $toolCheckTotal, function (callable $advance) use (
                $node,
                &$issues,
            ): void {
                foreach (NodeTool::query()->with('node')->where('node_id', $node->id)->get() as $tool) {
                    $snapshot = $this->toolsProbe->introspect($tool);

                    foreach ($this->toolsProbe->diff($tool, $snapshot) as $entry) {
                        $issues[] = $this->toolIssuePayload($entry, $tool);
                    }

                    $advance();
                }

                $webSocketAssignment = $this->activeWebSocketAssignment($node);

                if ($webSocketAssignment instanceof NodeRoleAssignment) {
                    foreach ($this->webSocketDoctorProbe->toolDrift($node, $webSocketAssignment) as $entry) {
                        $issues[] = $this->nodeScopedIssuePayload($entry, $node);
                    }

                    $advance();
                }

                $s3Assignment = $this->activeS3Assignment($node);

                if ($s3Assignment instanceof NodeRoleAssignment) {
                    foreach ($this->s3DoctorProbe->toolDrift($node, $s3Assignment) as $entry) {
                        $issues[] = $this->nodeScopedIssuePayload($entry, $node);
                    }

                    $advance();
                }

                if ($this->shouldProbeDnsRuntime($node)) {
                    foreach ($this->dnsRuntimeProbe->probe() as $entry) {
                        $issues[] = $this->nodeScopedIssuePayload($entry, $node);
                    }

                    $advance();
                }
            });

            $this->reportFamilyProgress(
                $onFamilyProgress,
                'tool',
                'done',
                $this->filterIssuesByKey(array_slice($issues, $familyIssueOffset), $key),
            );
        }

        if (in_array('schedule', $selectedFamilies, true)) {
            $familyIssueOffset = count($issues);
            $scheduleInventory = $this->schedulesForNode($node);

            if ($this->nodeRoleAssignments->nodeIsGateway($node)) {
                $scheduleInventory = collect([$node])->concat($scheduleInventory)->values();
            }

            $this->runFamilyCheckPlan(
                $onFamilyProgress,
                'schedule',
                $scheduleInventory->count(),
                function (callable $advance) use ($scheduleInventory, $node, &$issues): void {
                    foreach ($scheduleInventory as $target) {
                        if ($target instanceof Node) {
                            $snapshot = $this->schedulesProbe->introspectGateway($target);

                            foreach ($this->schedulesProbe->diffGateway($target, $snapshot) as $entry) {
                                $issues[] = $this->scheduleGatewayIssuePayload($entry, $node);
                            }

                            $advance();

                            continue;
                        }

                        $snapshot = $this->schedulesProbe->introspect($target);

                        foreach ($this->schedulesProbe->diff($target, $snapshot) as $entry) {
                            $issues[] = $this->scheduleIssuePayload($entry, $target);
                        }

                        $advance();
                    }
                },
            );

            $this->reportFamilyProgress(
                $onFamilyProgress,
                'schedule',
                'done',
                $this->filterIssuesByKey(array_slice($issues, $familyIssueOffset), $key),
            );
        }

        if (in_array('database_connection', $selectedFamilies, true)) {
            $familyIssueOffset = count($issues);

            $this->runFamilyCheckPlan($onFamilyProgress, 'database_connection', 1, function (callable $advance) use (
                $node,
                $scope,
                &$issues,
            ): void {
                foreach ($this->databaseConnectionProbe->probe($node, $scope) as $issue) {
                    $issues[] = $this->annotateIssue([
                        ...$issue,
                        'node' => $node->name,
                    ]);
                }

                $advance();
            });

            $this->reportFamilyProgress(
                $onFamilyProgress,
                'database_connection',
                'done',
                $this->filterIssuesByKey(array_slice($issues, $familyIssueOffset), $key),
            );
        }

        $issues = $this->filterIssuesByKey($issues, $key);
        $summary = $this->summary('verify', $issues, []);

        return [
            'healthy' => $issues === [],
            'mode' => 'verify',
            'scope' => $this->reportScope($selectedFamilies, $node, $key, $scope),
            'summary' => $summary,
            'issues' => $issues,
            'actions' => [],
        ];
    }

    /**
     * @param  list<string>  $families
     * @return array<string, mixed>
     */
    private function nodeProbeFailedReport(
        Node $node,
        array $families,
        ?string $key,
        RemoteShellFailed $exception,
        ?DoctorTargetScope $scope = null,
    ): array {
        $scope ??= DoctorTargetScope::none();
        $roleCategories = $this->categoriesForNode($node);
        $selectedFamilies = $families === []
            ? $roleCategories
            : array_values(array_intersect($families, $roleCategories));
        $issue = $this->remoteShellProbeFailedIssue(
            node: $node,
            family: 'node',
            key: 'node.remote_shell_probe_failed',
            exception: $exception,
            summary: "Doctor probe failed on node '{$node->name}': {$exception->getMessage()}",
        );
        $issues = $this->filterIssuesByKey([$issue], $key);
        $summary = $this->summary('verify', $issues, []);

        return [
            'healthy' => false,
            'mode' => 'verify',
            'scope' => $this->reportScope($selectedFamilies, $node, $key, $scope),
            'summary' => $summary,
            'issues' => $issues,
            'actions' => [],
        ];
    }

    /**
     * @param  list<string>  $families
     * @return array<string, mixed>
     */
    private function nodeLocalExecutorProbeFailedReport(
        Node $node,
        array $families,
        ?string $key,
        RemoteLocalExecutorTransportFailed $exception,
        ?DoctorTargetScope $scope = null,
    ): array {
        $scope ??= DoctorTargetScope::none();
        $roleCategories = $this->categoriesForNode($node);
        $selectedFamilies = $families === []
            ? $roleCategories
            : array_values(array_intersect($families, $roleCategories));
        $issue = $this->annotateIssue([
            'family' => 'node',
            'node' => $node->name,
            'key' => 'node.local_executor_probe_failed',
            'kind' => DriftKind::Unverifiable->value,
            'summary' => "Doctor probe failed on node '{$node->name}': {$exception->getMessage()}",
            'detail' => [
                'error' => $exception->getMessage(),
            ],
        ]);
        $issues = $this->filterIssuesByKey([$issue], $key);
        $summary = $this->summary('verify', $issues, []);

        return [
            'healthy' => false,
            'mode' => 'verify',
            'scope' => $this->reportScope($selectedFamilies, $node, $key, $scope),
            'summary' => $summary,
            'issues' => $issues,
            'actions' => [],
        ];
    }

    /**
     * @param  list<string>  $families
     * @return array{families: list<string>, node: string, role: string, self: false, app: string|null, app_instance: string|null, workspace: string|null, key: string|null}
     */
    private function reportScope(
        array $families,
        Node $node,
        ?string $key,
        DoctorTargetScope $scope,
    ): array {
        return [
            'families' => $families,
            'node' => $node->name,
            'role' => $node->displayRole(),
            'self' => false,
            'app' => $scope->app,
            'app_instance' => $scope->appInstance,
            'workspace' => $scope->workspace,
            'key' => $key,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function remoteShellProbeFailedIssue(
        Node $node,
        string $family,
        string $key,
        RemoteShellFailed $exception,
        string $summary,
    ): array {
        return $this->annotateIssue([
            'family' => $family,
            'node' => $node->name,
            'key' => $key,
            'kind' => DriftKind::Unverifiable->value,
            'summary' => $summary,
            'detail' => [
                'error' => $exception->getMessage(),
                'exit_code' => $exception->result->exitCode,
            ],
        ]);
    }

    /**
     * @param  Collection<int, AppInstance>  $appInstances
     * @param  list<array<string, mixed>>  $issues
     */
    private function probeAppFamily(
        Node $node,
        Collection $appInstances,
        bool $includeNodeConfigInventory,
        array &$issues,
        callable $advance,
    ): void {
        foreach ($appInstances as $instance) {
            $app = $instance->app;

            $snapshot = $this->appsProbe->introspectInstance($app, $instance);

            foreach ($this->appsProbe->diffInstance($app, $instance, $snapshot) as $entry) {
                $issues[] = $this->appIssuePayload($entry, $app);
            }

            foreach ($this->appRuntimeRequirementProbe->drift($instance) as $entry) {
                $issues[] = $this->appIssuePayload($entry, $app);
            }

            $advance();
        }

        if (! $includeNodeConfigInventory) {
            return;
        }

        $activePhpAppSlugs = $this
            ->appInstancesForNode($node)
            ->filter(fn (AppInstance $instance): bool => $instance->app->runtimeKind() === AppRuntimeKind::Php)
            ->map(fn (AppInstance $instance): string => app(AppRuntimeContainerRenderer::class)->instanceSlug(
                $instance->app,
                $instance,
            ))
            ->all();

        $configProbe = $this->appsProbe->introspectNodeRuntimeConfigs($node);
        $configSnapshot = $configProbe->configs;

        if ($configProbe->status === NodeRuntimeConfigsProbeStatus::Error) {
            $issues[] = $this->annotateIssue([
                'family' => 'app',
                'node' => $node->name,
                'key' => 'app.runtime_config_probe_failed',
                'kind' => DriftKind::Unverifiable->value,
                'summary' => "Managed runtime config directory probe failed on node '{$node->name}'; stale orphan configs cannot be detected.",
                'detail' => [
                    'path' => "{$this->nodeHostPaths->userConfigRoot($node)}/apps",
                    'error' => $configProbe->error,
                ],
            ]);
        } elseif ($configProbe->status === NodeRuntimeConfigsProbeStatus::Present) {
            foreach ($configSnapshot->keys() as $appSlug) {
                if (in_array($appSlug, $activePhpAppSlugs, true)) {
                    continue;
                }

                $observed = $configSnapshot->get($appSlug) ?? [];
                $path = is_string($observed['path'] ?? null)
                    ? $observed['path']
                    : $this->nodeHostPaths->appRuntimeConfigPath($node, $appSlug);

                $issues[] = $this->annotateIssue([
                    'family' => 'app',
                    'node' => $node->name,
                    'key' => 'app.runtime_config_extra',
                    'kind' => DriftKind::Extra->value,
                    'summary' => "Managed runtime config for '{$appSlug}' exists on node but no matching active PHP app record.",
                    'detail' => [
                        'app' => $appSlug,
                        'path' => $path,
                    ],
                ]);
            }
        }

        $advance();
    }

    /**
     * @return Collection<int, AppInstance>
     */
    private function appInstancesForNode(Node $node): Collection
    {
        /** @var Collection<int, AppInstance> $instances */
        $instances = AppInstance::query()
            ->with(['app.node', 'app.instances'])
            ->get()
            ->filter(
                fn (AppInstance $instance): bool => (
                    $this->workspacePlacement->nodeForInstance($instance)?->id === $node->id
                ),
            )
            ->values();

        return $instances;
    }

    /**
     * @param  Collection<int, AppInstance>  $instances
     * @return Collection<int, AppInstance>
     */
    private function scopedAppInstances(Collection $instances, DoctorTargetScope $scope): Collection
    {
        /** @var list<AppInstance> $scoped */
        $scoped = [];

        foreach ($instances as $instance) {
            if ($scope->appInstanceId !== null && $instance->id !== $scope->appInstanceId) {
                continue;
            }

            if ($scope->appInstanceId === null && $scope->app !== null && $instance->app->name !== $scope->app) {
                continue;
            }

            $scoped[] = $instance;
        }

        return new Collection($scoped);
    }

    /**
     * @return list<string>
     */
    private function activePhpRuntimeSlugsForNode(Node $node): array
    {
        $slugs = [];

        foreach ($this->appInstancesForNode($node) as $instance) {
            if ($instance->app->runtimeKind() !== AppRuntimeKind::Php) {
                continue;
            }

            $slugs[] = app(AppRuntimeContainerRenderer::class)->instanceSlug($instance->app, $instance);
        }

        return array_values(array_unique($slugs));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function missingFrankenPhpRuntimeProcessIssues(Node $node): array
    {
        $ensure = app(EnsureFrankenPhpRuntimeProcess::class);
        $renderer = app(AppRuntimeContainerRenderer::class);
        $issues = [];

        foreach ($this->appInstancesForNode($node) as $instance) {
            $app = $instance->app;

            if (! $this->appHasManagedFrankenPhpRuntimeIntent($app)) {
                continue;
            }

            $processName = $ensure->appProcessName($app);
            $exists = Process::query()
                ->where('owner_type', $app->getMorphClass())
                ->where('owner_id', $app->getKey())
                ->where('app_instance_id', $instance->id)
                ->where('name', $processName)
                ->exists();

            if ($exists) {
                continue;
            }

            $issues[] = $this->annotateIssue([
                'family' => 'process',
                'node' => $node->name,
                'key' => 'process.runtime_unit_missing',
                'kind' => DriftKind::Missing->value,
                'summary' => "FrankenPHP runtime intent is missing for instance {$app->name}.{$instance->name}.",
                'detail' => [
                    'app' => $app->name,
                    'app_instance' => $instance->name,
                    'process' => $processName,
                    'runtime_unit' => $renderer->containerNameForInstance($app, $instance),
                    'reason' => 'runtime_process_missing',
                ],
            ]);
        }

        return $issues;
    }

    private function appHasManagedFrankenPhpRuntimeIntent(Project $app): bool
    {
        return Process::query()
            ->where('owner_type', $app->getMorphClass())
            ->where('owner_id', $app->getKey())
            ->get()
            ->contains(function (Process $process): bool {
                $config = $process->runtime_config;

                return ($config['container_spec_hash_label'] ?? null) === AppRuntimeContainer::SpecHashLabel;
            });
    }

    /**
     * @return Collection<int, ProxyRoute>
     */
    private function proxyRoutesForScope(Node $node, DoctorTargetScope $scope): Collection
    {
        $query = ProxyRoute::query()
            ->with(['node', 'app', 'workspace'])
            ->where('node_id', $node->id);

        if ($this->productionNodeExcludesWorkspaces($node)) {
            $query
                ->whereNull('workspace_id')
                ->whereNotIn('owner_type', self::WORKSPACE_PROXY_OWNER_TYPES)
                ->where('kind', '!=', 'workspace');
        }

        if ($scope->app !== null) {
            $query->whereHas('app', static fn (Builder $appQuery): Builder => $appQuery->where('name', $scope->app));
        }

        if ($scope->workspace !== null) {
            $query->whereHas(
                'workspace',
                static fn (Builder $workspaceQuery): Builder => $workspaceQuery->where('name', $scope->workspace),
            );
        }

        return $query->get();
    }

    /**
     * @param  Collection<int, ProxyRoute>  $routes
     * @param  list<array<string, mixed>>  $issues
     */
    private function probeProxyFamily(
        Node $node,
        Collection $routes,
        DoctorTargetScope $scope,
        array &$issues,
        callable $advance,
    ): void {
        foreach ($routes as $route) {
            $snapshot = $this->proxyRouteProbe->introspect($route);

            foreach ($this->proxyRouteProbe->diff($route, $snapshot) as $entry) {
                $issues[] = $this->proxyIssuePayload($entry, $route);
            }

            $advance();
        }

        if ($scope->app === null && $scope->workspace === null) {
            foreach ($this->proxyRouteProbe->diffAgentToolRouteIntent($node) as $entry) {
                $issues[] = $this->nodeScopedIssuePayload($entry, $node);
            }

            $advance();
        }

        if ($this->shouldProbeProxyDnsProjection($node, $scope)) {
            foreach ($this->proxyDnsProjectionProbe->drift($node) as $entry) {
                $issues[] = $this->nodeScopedIssuePayload($entry, $node);
            }

            $advance();
        }

        if ($scope->workspace === null) {
            foreach ($this->webSocketProxyDoctorProbe->drift($node, $scope->app) as $entry) {
                $issues[] = $this->nodeScopedIssuePayload($entry, $node);
            }
        }

        $advance();

        if ($scope->app === null && $scope->workspace === null) {
            foreach ($this->s3ProxyDoctorProbe->drift($node) as $entry) {
                $issues[] = $this->nodeScopedIssuePayload($entry, $node);
            }

            foreach ($this->analyticsProxyDoctorProbe->drift($node) as $entry) {
                $issues[] = $this->nodeScopedIssuePayload($entry, $node);
            }
        }

        if ($scope->workspace === null) {
            foreach ($this->analyticsPublicProxyDoctorProbe->drift($node, $scope->app) as $entry) {
                $issues[] = $this->nodeScopedIssuePayload($entry, $node);
            }
        }

        $advance();

        if ($node->isActive() && $this->nodeRoleAssignments->nodeHostsOrbitCaddy($node)) {
            $caddySnapshot = $this->proxyRouteProbe->introspectCaddyContainer($node);

            foreach ($this->proxyRouteProbe->diffCaddyContainer($node, $caddySnapshot) as $entry) {
                $issues[] = $this->annotateIssue([
                    'family' => $entry->family,
                    'node' => $node->name,
                    'key' => $entry->key,
                    'kind' => $entry->kind->value,
                    'summary' => $entry->summary,
                    'detail' => $entry->detail ?? [],
                ]);
            }

            $advance();
        }

        if (! $node->isActive() || ! $this->nodeRoleAssignments->nodeHostsOrbitCaddy($node)) {
            return;
        }

        try {
            $snapshot = $this->proxyRouteProbe->introspectNode($node);
            $expectedDomains = $this->proxyRouteProbe->expectedDomainsForNode($node);

            foreach ($this->proxyRouteProbe->observedRouteDomainsForNode($node, $snapshot) as $domain) {
                if (in_array($domain, $expectedDomains, true)) {
                    continue;
                }

                $entry = new DriftEntry(
                    family: 'proxy',
                    key: $domain,
                    kind: DriftKind::Extra,
                    summary: "Proxy route '{$domain}' exists on node but not in gateway registry.",
                );

                $issues[] = $this->annotateIssue([
                    'family' => 'proxy',
                    'node' => $node->name,
                    'key' => $domain,
                    'kind' => 'extra',
                    'summary' => $entry->summary,
                    'detail' => [
                        'domain' => $domain,
                    ],
                ]);
            }

            $globalSnapshot = $this->proxyRouteProbe->introspectGlobalConfig($node);

            foreach ($this->proxyRouteProbe->diffGlobalConfig($node, $globalSnapshot) as $entry) {
                $issues[] = $this->annotateIssue([
                    'family' => 'proxy',
                    'node' => $node->name,
                    'key' => $entry->key,
                    'kind' => $entry->kind->value,
                    'summary' => $entry->summary,
                    'detail' => $entry->detail ?? [],
                ]);
            }
        } catch (RemoteShellFailed $exception) {
            $issues[] = $this->remoteShellProbeFailedIssue(
                node: $node,
                family: 'proxy',
                key: 'proxy.node_probe_failed',
                exception: $exception,
                summary: "Proxy node route scan failed on node '{$node->name}'; extra backend routes on the node cannot be detected.",
            );
        }

        $advance();
    }

    /**
     * @param  callable(callable(): void): void  $runner
     */
    private function runFamilyCheckPlan(
        ?callable $onFamilyProgress,
        string $family,
        int $total,
        callable $runner,
    ): void {
        if ($total === 0) {
            $this->reportFamilyProgress($onFamilyProgress, $family, 'running');
            $runner(static function (): void {});

            return;
        }

        $completed = 0;
        $this->reportFamilyProgress(
            $onFamilyProgress,
            $family,
            'running',
            [],
            [
                'completed' => 0,
                'total' => $total,
            ],
        );

        $advance = function () use (&$completed, $onFamilyProgress, $family, $total): void {
            $completed++;

            if ($completed >= $total) {
                return;
            }

            $this->reportFamilyProgress(
                $onFamilyProgress,
                $family,
                'running',
                [],
                [
                    'completed' => $completed,
                    'total' => $total,
                ],
            );
        };

        $runner($advance);
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @param  array{completed: int, total: int}|null  $progress
     */
    private function reportFamilyProgress(
        ?callable $onFamilyProgress,
        string $family,
        string $phase,
        array $issues = [],
        ?array $progress = null,
    ): void {
        if ($onFamilyProgress === null) {
            return;
        }

        $onFamilyProgress($family, $phase, $issues, $progress['completed'] ?? null, $progress['total'] ?? null);
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @return list<array<string, mixed>>
     */
    public function apply(Node $node, string $mode, array $issues): array
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
                && ($issue['family'] ?? null) === 'tool'
                && is_string($issue['key'] ?? null)
                && $this->dnsRuntimeProbe->isRestorable($issue['key'])
            ) {
                $action = $this->applyDnsRuntimeIssue(
                    $node,
                    $issue['key'],
                    is_array($issue['detail'] ?? null) ? $issue['detail'] : [],
                    $issue,
                );

                if ($action !== null) {
                    $actions[] = $action;
                }

                continue;
            }

            if (
                $mode === 'restore'
                && in_array(
                    $issue['key'] ?? null,
                    ['node.dns_mapping_mismatch', 'proxy.dns_mapping_mismatch'],
                    true,
                )
            ) {
                $actions[] = $this->applyDnsProjectionIssue($node, $issue);

                continue;
            }

            if (
                $mode === 'restore'
                && (($issue['family'] ?? null) === 'tool'
                || ($issue['family'] ?? null) === 'node'
                && ($issue['key'] ?? null) === 'node.role_baseline_mismatch')
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
                $convergenceRestoreIssues,
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
    public function finalize(array $probe, string $mode, array $actions, bool $dryRun = false): array
    {
        $issues = $probe['issues'] ?? [];
        $issues = is_array($issues) ? array_values(array_filter($issues, is_array(...))) : [];
        $remainingIssues = $this->remainingIssues($issues, $actions);
        $summary = $this->summary($mode, $remainingIssues, $actions);

        $result = [
            ...$probe,
            'healthy' =>
                $summary['issues'] === 0
                    && $summary['failed'] === 0
                    && $summary['conflicts'] === 0
                    && $summary['skipped'] === 0,
            'mode' => $mode,
            'summary' => $summary,
            'issues' => $remainingIssues,
            'actions' => $actions,
        ];

        if ($dryRun) {
            $result['dry_run'] = true;
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function appIssuePayload(DriftEntry $entry, Project $app): array
    {
        $app->loadMissing('node');

        return $this->annotateIssue([
            'family' => $entry->family,
            'node' => $app->node?->name,
            'key' => $entry->key,
            'kind' => $entry->kind->value,
            'summary' => $entry->summary,
            'detail' => [
                ...($entry->detail ?? []),
                'app' => $app->name,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function workspaceIssuePayload(DriftEntry $entry, Workspace $workspace): array
    {
        $workspace->loadMissing(['app.node', 'app.instances', 'appInstance']);

        return $this->annotateIssue([
            'family' => $entry->family,
            'node' => $this->workspacePlacement->nodeForWorkspace($workspace)?->name,
            'key' => $entry->key,
            'kind' => $entry->kind->value,
            'summary' => $entry->summary,
            'detail' => [
                ...($entry->detail ?? []),
                'workspace' => $workspace->name,
                'app' => $workspace->app?->name,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function processIssuePayload(DriftEntry $entry, Process $process): array
    {
        $app = $process->ownerApp();
        $app?->loadMissing('node');
        $node = $app instanceof Project ? $app->node : $process->node;

        return $this->annotateIssue([
            'family' => $entry->family,
            'node' => $node instanceof Node ? $node->name : null,
            'key' => $entry->key,
            'kind' => $entry->kind->value,
            'summary' => $entry->summary,
            'detail' => $entry->detail,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function issuePayload(DriftEntry $entry, Node $node): array
    {
        $detail = $entry->detail ?? [];
        $code = is_string($detail['code'] ?? null) ? $detail['code'] : $entry->key;

        return $this->annotateIssue([
            'family' => 'node',
            'node' => $node->name,
            'key' => $entry->key,
            'code' => $code,
            'kind' => $entry->kind->value,
            'summary' => $entry->summary,
            'detail' => $detail,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function nodeScopedIssuePayload(DriftEntry $entry, Node $node): array
    {
        $detail = $entry->detail ?? [];
        $code = is_string($detail['code'] ?? null) ? $detail['code'] : $entry->key;

        return $this->annotateIssue([
            'family' => $entry->family,
            'node' => $node->name,
            'key' => $entry->key,
            'code' => $code,
            'kind' => $entry->kind->value,
            'summary' => $entry->summary,
            'detail' => $detail,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function proxyIssuePayload(DriftEntry $entry, ProxyRoute $route): array
    {
        return $this->annotateIssue([
            'family' => $entry->family,
            'node' => $route->node->name,
            'key' => $entry->key,
            'kind' => $entry->kind->value,
            'summary' => $entry->summary,
            'detail' => [
                ...($entry->detail ?? []),
                'domain' => $route->domain,
            ],
        ]);
    }

    /**
     * @param  list<string>  $families
     * @return list<array<string, mixed>>
     */
    private function adoptSelectedFamilies(
        Node $node,
        array $families,
        DoctorTargetScope $scope,
    ): array {
        $actions = [];

        if (in_array('node', $families, true)) {
            $snapshot = $this->nodesProbe->snapshotForAdopt($node);

            foreach ($this->nodesProbe->adopt($node, $snapshot) as $result) {
                $actions[] = [
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

        if (in_array('proxy', $families, true) && $node->isActive() && $this->canServeGatewayOrAppHost($node)) {
            $snapshot = $this->proxyAdoptSnapshotForScope($node, $scope);

            foreach ($this->proxyRouteAdopter->adopt($node, $snapshot) as $result) {
                $actions[] = [
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

        if (
            in_array('firewall_rule', $families, true)
            && $node->isActive()
            && $this->isUbuntuPlatform($node)
            && $this->canServeGatewayOrAppHost($node)
        ) {
            $snapshot = $this->firewallRuleProbe->introspectNode($node);

            foreach ($this->firewallRuleProbe->adopt($node, $snapshot) as $result) {
                $actions[] = [
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

        if (in_array('database_connection', $families, true)) {
            foreach ($this->databaseConnectionAdopter->adopt($node, $scope) as $result) {
                $actions[] = [
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

        return $actions;
    }

    private function proxyAdoptSnapshotForScope(Node $node, DoctorTargetScope $scope): ProbeSnapshot
    {
        $snapshot = $this->proxyRouteProbe->snapshotForAdopt($node);

        if ($this->productionNodeExcludesWorkspaces($node)) {
            /** @var list<string> $excludedDomains */
            $excludedDomains = ProxyRoute::query()
                ->where('node_id', $node->id)
                ->where(function (Builder $query): void {
                    $query
                        ->whereNotNull('workspace_id')
                        ->orWhereIn('owner_type', self::WORKSPACE_PROXY_OWNER_TYPES)
                        ->orWhere('kind', 'workspace');
                })
                ->pluck('domain')
                ->all();
            $snapshot = new ProbeSnapshot(array_diff_key($snapshot->items, array_fill_keys($excludedDomains, true)));
        }

        if ($scope->app === null && $scope->workspace === null) {
            return $snapshot;
        }

        $domains = $this
            ->proxyRoutesForScope($node, $scope)
            ->map(static fn (ProxyRoute $route): string => $route->domain)
            ->all();

        return new ProbeSnapshot(array_intersect_key($snapshot->items, array_fill_keys($domains, true)));
    }

    private function canServeGatewayOrAppHost(Node $node): bool
    {
        return $this->nodeRoleAssignments->nodeCanServeGatewayOrAppHostWorkloads($node);
    }

    private function productionNodeExcludesWorkspaces(Node $node): bool
    {
        return $this->nodeRoleAssignments->nodeHasActiveRole($node, NodeRoleName::AppProduction->value);
    }

    private function isUbuntuPlatform(Node $node): bool
    {
        return $node->platform === 'ubuntu' || str_starts_with((string) $node->platform, 'ubuntu_');
    }

    private function activeWebSocketAssignment(Node $node): ?NodeRoleAssignment
    {
        return $this->nodeRoleAssignments->activeAssignment($node, NodeRoleName::WebSocket->value);
    }

    private function activeS3Assignment(Node $node): ?NodeRoleAssignment
    {
        return $this->nodeRoleAssignments->activeAssignment($node, NodeRoleName::S3->value);
    }

    private function shouldProbeDnsRuntime(Node $node): bool
    {
        return (
            $this->nodeRoleAssignments->nodeIsGateway($node) && $this->nodeRoleAssignments->nodeHasActiveVpnRole($node)
        );
    }

    /**
     * Node-owned dnsmasq projection content is verified only on the DNS-serving
     * host (gateway-coupled VPN role), matching proxy DNS projection scope.
     */
    private function shouldProbeNodeDnsProjection(Node $node): bool
    {
        return $this->shouldProbeDnsRuntime($node);
    }

    /**
     * @return list<Node>
     */
    private function nodeDnsProjectionSources(): array
    {
        $nodes = Node::query()
            ->with('roleAssignments')
            ->where('status', NodeStatus::Active->value)
            ->orderBy('id')
            ->get();

        $sources = [];

        foreach ($nodes as $node) {
            if ($node instanceof Node) {
                $sources[] = $node;
            }
        }

        return $sources;
    }

    private function shouldProbeProxyDnsProjection(Node $node, DoctorTargetScope $scope): bool
    {
        return (
            $scope->app === null
            && $scope->workspace === null
            && $this->nodeRoleAssignments->nodeHasActiveRole($node, NodeRoleName::Router->value)
        );
    }

    private function nodeHasAnyActiveRole(Node $node): bool
    {
        return $this->nodeRoleAssignments->nodeHasAnyActiveRole(
            $node,
            array_map(
                static fn (NodeRoleName $role): string => $role->value,
                NodeRoleName::cases(),
            ),
        );
    }

    /**
     * @param  list<string>  $families
     */
    private function nodeSupportsFamilies(Node $node, array $families): bool
    {
        if ($families === []) {
            return true;
        }

        $categories = $this->categoriesForNode($node);

        return array_all($families, fn (string $family): bool => in_array($family, $categories, true));
    }

    /**
     * @param  Collection<int, Node>  $targets
     * @param  list<string>  $families
     * @return list<string>
     */
    private function fleetFamilies(Collection $targets, array $families): array
    {
        if ($families !== []) {
            return $families;
        }

        $resolved = [];

        foreach ($targets as $node) {
            foreach ($this->categoriesForNode($node) as $family) {
                if (in_array($family, $resolved, true)) {
                    continue;
                }

                $resolved[] = $family;
            }
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    private function productionNodeWorkspaceIssue(Node $node, array $issue): bool
    {
        if (! $this->productionNodeExcludesWorkspaces($node)) {
            return false;
        }

        $family = is_string($issue['family'] ?? null) ? $issue['family'] : null;
        /** @var array<string, mixed> $detail */
        $detail = is_array($issue['detail'] ?? null) ? $issue['detail'] : [];

        return match ($family) {
            'process' => $this->processIssueTargetsWorkspace($node, $detail),
            'proxy' => $this->proxyIssueTargetsWorkspace($detail),
            'database_connection' => $this->databaseConnectionIssueTargetsWorkspace($detail),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function processIssueTargetsWorkspace(Node $node, array $detail): bool
    {
        if (is_string($detail['workspace'] ?? null)) {
            return true;
        }

        $processName = is_string($detail['process'] ?? null) ? $detail['process'] : null;

        if ($processName === null) {
            return false;
        }

        /** @var Builder<Process> $query */
        $query = Process::query();
        $query
            ->where('node_id', $node->id)
            ->where('name', $processName)
            ->whereIn('owner_type', self::WORKSPACE_PROCESS_OWNER_TYPES);
        $appName = is_string($detail['app'] ?? null) ? $detail['app'] : null;
        $appInstanceName = is_string($detail['app_instance'] ?? null) ? $detail['app_instance'] : null;

        if ($appName !== null && $appInstanceName !== null) {
            $query->whereHas(
                'appInstance',
                fn (Builder $instanceQuery): Builder => $instanceQuery
                    ->where('name', $appInstanceName)
                    ->whereHas(
                        'app',
                        fn (Builder $appQuery): Builder => $appQuery->where('name', $appName),
                    ),
            );
        }

        /** @var Collection<int, Process> $processes */
        $processes = $query->with('appInstance.app')->get();
        $runtimeUnit = is_string($detail['runtime_unit'] ?? null) ? $detail['runtime_unit'] : null;

        if ($runtimeUnit === null) {
            return $processes->isNotEmpty();
        }

        foreach ($processes as $process) {
            if ($process->owner_type !== Workspace::class) {
                return true;
            }

            $process->loadMissing('owner');

            if ($this->runtimeUnitNameForProcess($node, $process) === $runtimeUnit) {
                return true;
            }
        }

        return false;
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

    /**
     * @param  array<string, mixed>  $detail
     */
    private function databaseConnectionIssueTargetsWorkspace(array $detail): bool
    {
        return ($detail['target_type'] ?? null) === 'workspace' || is_string($detail['workspace'] ?? null);
    }

    private function proxyRouteIsWorkspaceOwned(ProxyRoute $route): bool
    {
        return (
            $route->workspace_id !== null
            || in_array($route->owner_type, self::WORKSPACE_PROXY_OWNER_TYPES, true)
            || $route->kind === 'workspace'
        );
    }

    /**
     * @param  array<string, mixed>  $issue
     * @return array<string, mixed>|null
     */
    private function applyIssue(Node $node, string $mode, array $issue): ?array
    {
        $family = is_string($issue['family'] ?? null) ? $issue['family'] : null;
        $key = is_string($issue['key'] ?? null) ? $issue['key'] : null;
        $detail = is_array($issue['detail'] ?? null) ? $issue['detail'] : [];

        if ($key === null) {
            return null;
        }

        return match ($family) {
            'node' => $this->applyNodeIssue($node, $key, $detail, $issue),
            'app' => $this->applyAppIssue($node, $key, $detail),
            'database_connection' => $this->applyDatabaseConnectionIssue($key, $detail),
            'workspace' => $this->applyWorkspaceIssue($node, $key, $detail),
            'process' => $this->applyProcessIssue($node, $key, $detail),
            'proxy' => $this->applyProxyIssue($node, $mode, $key, $detail, $issue),
            'firewall_rule' => $this->applyFirewallIssue($node, $key, $detail),
            'tool' => $this->applyToolIssue($node, $key, $detail),
            'schedule' => $this->applyScheduleIssue($node, $key, $detail, $issue),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>|null
     */
    private function applyDatabaseConnectionIssue(string $key, array $detail): ?array
    {
        $targetType = is_string($detail['target_type'] ?? null) ? $detail['target_type'] : null;
        $targetId = is_int($detail['target_id'] ?? null)
            ? $detail['target_id']
            : (is_numeric($detail['target_id'] ?? null) ? (int) $detail['target_id'] : null);
        $prefix = is_string($detail['env_prefix'] ?? null) ? $detail['env_prefix'] : null;

        if ($targetType === null || $targetId === null || $prefix === null) {
            return null;
        }

        if (! in_array($targetType, ['app_instance', 'workspace'], true)) {
            return null;
        }

        $target = DatabaseConnectionTarget::query()
            ->with(['appInstance.app', 'workspace.appInstance.app'])
            ->where('env_prefix', $prefix)
            ->when($targetType === 'app_instance', fn ($query) => $query->where('app_instance_id', $targetId))
            ->when($targetType === 'workspace', fn ($query) => $query->where('workspace_id', $targetId))
            ->first();

        if (! $target instanceof DatabaseConnectionTarget) {
            if ($key !== 'database_connection.target_missing') {
                return null;
            }

            return $this->restoreMissingDatabaseConnectionTarget($key, $detail, $targetType, $targetId, $prefix);
        }

        $nodeName = $this->databaseConnectionTargetNode($target)?->name;

        try {
            $this->databaseConnectionRestorer->restore($target);
        } catch (Throwable $e) {
            return [
                'family' => 'database_connection',
                'node' => $nodeName,
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

        return [
            'family' => 'database_connection',
            'node' => $nodeName,
            'code' => $key,
            'key' => $key,
            'mode' => 'restore',
            'status' => 'completed',
            'summary' => "Fixed {$key}.",
            'details' => $detail,
        ];
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>|null
     */
    private function restoreMissingDatabaseConnectionTarget(
        string $key,
        array $detail,
        string $targetType,
        int $targetId,
        string $prefix,
    ): ?array {
        $connectionId = is_int($detail['database_connection_id'] ?? null)
            ? $detail['database_connection_id']
            : (is_numeric($detail['database_connection_id'] ?? null) ? (int) $detail['database_connection_id'] : null);

        if ($connectionId === null) {
            return null;
        }

        $connection = DatabaseConnection::query()->find($connectionId);

        if (! $connection instanceof DatabaseConnection) {
            return null;
        }

        DatabaseConnectionTarget::query()->create([
            'database_connection_id' => $connection->id,
            'env_prefix' => $prefix,
            'app_instance_id' => $targetType === 'app_instance' ? $targetId : null,
            'workspace_id' => $targetType === 'workspace' ? $targetId : null,
        ]);

        $nodeName = $this->databaseConnectionTargetNodeName($targetType, $targetId);

        return [
            'family' => 'database_connection',
            'node' => $nodeName,
            'code' => $key,
            'key' => $key,
            'mode' => 'restore',
            'status' => 'completed',
            'summary' => "Fixed {$key}.",
            'details' => $detail,
        ];
    }

    private function databaseConnectionTargetNodeName(string $targetType, int $targetId): ?string
    {
        if ($targetType === 'app_instance') {
            $instance = AppInstance::query()->with('app')->find($targetId);

            return $instance instanceof AppInstance
                ? $this->workspacePlacement->nodeForInstance($instance)?->name
                : null;
        }

        if ($targetType !== 'workspace') {
            return null;
        }

        $workspace = Workspace::query()
            ->with(['appInstance.app'])
            ->find($targetId);

        return $workspace instanceof Workspace
            ? $this->workspacePlacement->nodeForWorkspace($workspace)?->name
            : null;
    }

    private function databaseConnectionTargetNode(DatabaseConnectionTarget $target): ?Node
    {
        if ($target->appInstance instanceof AppInstance) {
            return $this->workspacePlacement->nodeForInstance($target->appInstance);
        }

        if ($target->workspace instanceof Workspace) {
            return $this->workspacePlacement->nodeForWorkspace($target->workspace);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $detail
     * @param  array<string, mixed>  $issue
     * @return array<string, mixed>
     */
    private function applyNodeIssue(Node $node, string $key, array $detail, array $issue): array
    {
        $targetNode = $this->nodeFromIssue($issue) ?? $node;
        $entry = $this->driftEntryFromStoredParts('node', $key, $detail, $issue);
        $code = is_string($issue['code'] ?? null) ? $issue['code'] : $key;

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
            'summary' => is_string($issue['summary'] ?? null) ? $issue['summary'] : "Fixed {$code}.",
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
            ->with(['app.node', 'app.instances', 'appInstance'])
            ->where('name', $workspaceName)
            ->whereHas('app', function ($query) use ($appName): void {
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
    private function applyProcessIssue(Node $node, string $key, array $detail): ?array
    {
        if ($key === 'process.runtime_unit_extra') {
            return $this->removeExtraManagedProcessRuntime($node, $key, $detail);
        }

        if ($key === 'process.runtime_unit_unrenderable') {
            return $this->restoreUnrenderableProcessIssue($node, $key, $detail);
        }

        if (in_array($key, ['process.event_notifier_missing', 'process.event_notifier_mismatch'], true)) {
            return $this->restoreProcessEventNotifierIssue($node, $key);
        }

        if (! in_array(
            $key,
            ['process.runtime_unit_missing', 'process.runtime_unit_mismatch', 'process.runtime_unit_down'],
            true,
        )) {
            return null;
        }

        if (($detail['reason'] ?? null) === 'runtime_process_missing') {
            return $this->restoreMissingFrankenPhpRuntimeProcess($node, $key, $detail);
        }

        $process = $this->processFromIssueDetail($node, $detail);

        if (! $process instanceof Process) {
            return null;
        }

        $managedRuntimeAction = $this->restoreManagedFrankenPhpProcessRuntime($node, $key, $process);

        if ($managedRuntimeAction !== null) {
            return $managedRuntimeAction;
        }

        if ($key === 'process.runtime_unit_down') {
            return $this->startAlwaysOnProcessRuntime($node, $key, $process);
        }

        $app = $process->ownerApp();

        if (! $app instanceof Project) {
            return $this->applyNodeOwnedProcessIssue($node, $key, $process);
        }

        $process->loadMissing('appInstance');
        $appInstance = $process->appInstance;

        if (! $appInstance instanceof AppInstance) {
            return null;
        }

        try {
            $this->refreshManagedFrankenPhpProcessIntent($process);
            $warnings = app(EnsureAppProcessRuntimeUnits::class)->handle($app, $appInstance);
        } catch (Throwable $e) {
            return [
                'family' => 'process',
                'node' => $node->name,
                'code' => $key,
                'key' => $key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to restore {$key}.",
                'details' => [
                    'error' => $e->getMessage(),
                ],
            ];
        }

        if ($warnings !== []) {
            return [
                'family' => 'process',
                'node' => $node->name,
                'code' => $key,
                'key' => $key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Process runtime restore for {$app->name}.{$appInstance->name} completed with warnings.",
                'details' => [
                    'warnings' => $warnings,
                ],
            ];
        }

        return [
            'family' => 'process',
            'node' => $node->name,
            'code' => $key,
            'key' => $key,
            'mode' => 'restore',
            'status' => 'completed',
            'summary' => "Restored process runtime units for {$app->name}.{$appInstance->name}.",
            'details' => [
                'app' => $app->name,
                'app_instance' => $appInstance->name,
                'process' => $process->name,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function startAlwaysOnProcessRuntime(Node $node, string $key, Process $process): ?array
    {
        $context = $this->processOwnerContext($node, $process);

        if (! $context instanceof ProcessOwnerContext) {
            return null;
        }

        $runtimeApp = $context->runtimeApp();
        $workspace = $context->runtimeWorkspaceFor($process);
        $driver = $this->processRuntimeDrivers->forProcess($process);
        $runtimeUnit = $driver->runtimeUnitName($runtimeApp, $process, $workspace);
        $started = $driver->start($node, $runtimeUnit);

        return [
            'family' => 'process',
            'node' => $node->name,
            'code' => $key,
            'key' => $key,
            'mode' => 'restore',
            'status' => $started ? 'completed' : 'failed',
            'summary' => $started
                ? "Started always-on process runtime unit {$runtimeUnit}."
                : "Failed to start always-on process runtime unit {$runtimeUnit}.",
            'details' => [
                'node' => $node->name,
                'process' => $process->name,
                'runtime_unit' => $runtimeUnit,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>|null
     */
    private function restoreMissingFrankenPhpRuntimeProcess(Node $node, string $key, array $detail): ?array
    {
        $appName = is_string($detail['app'] ?? null) ? $detail['app'] : null;
        $instanceName = is_string($detail['app_instance'] ?? null) ? $detail['app_instance'] : null;

        if ($appName === null || $instanceName === null) {
            return null;
        }

        $app = Project::query()
            ->with('instances')
            ->where('name', $appName)
            ->first();
        $instance = $app instanceof Project
            ? $app->instances->firstWhere('name', $instanceName)
            : null;

        if (
            ! $app instanceof Project
            || ! $instance instanceof AppInstance
            || $this->workspacePlacement->nodeForInstance($instance)?->id !== $node->id
        ) {
            return null;
        }

        try {
            $process = app(EnsureFrankenPhpRuntimeProcess::class)->forApp($app, $instance);
        } catch (Throwable $exception) {
            return [
                'family' => 'process',
                'node' => $node->name,
                'code' => $key,
                'key' => $key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to restore {$key}.",
                'details' => [
                    ...$detail,
                    'error' => $exception->getMessage(),
                ],
            ];
        }

        return $this->restoreManagedFrankenPhpAppRuntime($node, $key, $process, $app);
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>|null
     */
    private function removeExtraManagedProcessRuntime(Node $node, string $key, array $detail): ?array
    {
        $runtimeUnit = is_string($detail['runtime_unit'] ?? null) ? trim($detail['runtime_unit']) : '';

        if (
            ($detail['reason'] ?? null) !== 'orphaned_managed_app_runtime'
            || ! str_starts_with($runtimeUnit, 'orbit-app-')
        ) {
            return null;
        }

        try {
            $removed = app(ProcessDockerRuntimeManager::class)->remove($node, $runtimeUnit);
        } catch (Throwable $exception) {
            $removed = false;
            $detail['error'] = $exception->getMessage();
        }

        return [
            'family' => 'process',
            'node' => $node->name,
            'code' => $key,
            'key' => $key,
            'mode' => 'restore',
            'status' => $removed ? 'completed' : 'failed',
            'summary' => $removed
                ? "Removed orphaned managed process runtime {$runtimeUnit}."
                : "Failed to remove orphaned managed process runtime {$runtimeUnit}.",
            'details' => [
                ...$detail,
                'runtime_unit' => $runtimeUnit,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function restoreManagedFrankenPhpProcessRuntime(Node $node, string $key, Process $process): ?array
    {
        $process->loadMissing('owner');

        $config = $process->runtime_config;
        $hashLabel = is_string($config['container_spec_hash_label'] ?? null)
            ? trim($config['container_spec_hash_label'])
            : '';

        if ($hashLabel === '') {
            return null;
        }

        if ($hashLabel === AppRuntimeContainer::SpecHashLabel && $process->owner instanceof Project) {
            return $this->restoreManagedFrankenPhpAppRuntime($node, $key, $process, $process->owner);
        }

        if ($hashLabel === WorkspaceRuntimeContainer::SpecHashLabel && $process->owner instanceof Workspace) {
            return $this->restoreManagedFrankenPhpWorkspaceRuntime($node, $key, $process, $process->owner);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function restoreManagedFrankenPhpAppRuntime(Node $node, string $key, Process $process, Project $app): array
    {
        $process->loadMissing('appInstance');
        $appInstance = $process->appInstance;
        $instanceNode = $appInstance instanceof AppInstance
            ? $this->workspacePlacement->nodeForInstance($appInstance)
            : null;

        if (
            ! $appInstance instanceof AppInstance
            || $appInstance->app_id !== $app->id
            || ! $instanceNode instanceof Node
            || $instanceNode->id !== $node->id
        ) {
            return [
                'family' => 'process',
                'node' => $node->name,
                'code' => $key,
                'key' => $key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to restore {$key}.",
                'details' => [
                    'app' => $app->name,
                    'app_instance' => $appInstance?->name,
                    'process' => $process->name,
                    'error' => 'Process instance has no active serving node.',
                ],
            ];
        }

        try {
            app(EnsureFrankenPhpRuntimeProcess::class)->forApp($app, $appInstance);
            $runtimeApp = app(AppRuntimeContainerRenderer::class)->runtimeAppForInstance($app, $appInstance);
            $this->ensureAppRuntimeTlsMaterial($runtimeApp, $instanceNode);

            $container = app(AppRuntimeContainerRenderer::class)->renderForInstance($app, $appInstance);
            $outcome = $this->appRuntimeContainerManagerForAgentPush()->apply($instanceNode, $container);
        } catch (Throwable $e) {
            return [
                'family' => 'process',
                'node' => $node->name,
                'code' => $key,
                'key' => $key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to restore {$key}.",
                'details' => [
                    'app' => $app->name,
                    'app_instance' => $appInstance->name,
                    'process' => $process->name,
                    'error' => $e->getMessage(),
                ],
            ];
        }

        return [
            'family' => 'process',
            'node' => $node->name,
            'code' => $key,
            'key' => $key,
            'mode' => 'restore',
            'status' => 'completed',
            'summary' => "Restored managed FrankenPHP runtime container for {$app->name}.{$appInstance->name}.",
            'details' => [
                'app' => $app->name,
                'app_instance' => $appInstance->name,
                'process' => $process->name,
                'container' => $container->name(),
                'outcome' => $outcome->value,
            ],
        ];
    }

    private function appRuntimeContainerManagerForAgentPush(): AppRuntimeContainerManager
    {
        return new AppRuntimeContainerManager(
            app(DockerCommandBuilder::class),
            app(OrbitCaService::class),
            app(AppDevelopmentInnerTlsPolicy::class),
            localExecutor: app(RemoteLocalExecutor::class),
        );
    }

    private function workspaceRuntimeContainerManagerForAgentPush(): WorkspaceRuntimeContainerManager
    {
        return new WorkspaceRuntimeContainerManager(
            app(DockerCommandBuilder::class),
            app(OrbitCaService::class),
            app(AppDevelopmentInnerTlsPolicy::class),
            localExecutor: app(RemoteLocalExecutor::class),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function restoreManagedFrankenPhpWorkspaceRuntime(
        Node $node,
        string $key,
        Process $process,
        Workspace $workspace,
    ): array {
        $workspace->loadMissing(['app.node', 'appInstance']);
        $app = $workspace->app;
        $workspaceNode = $this->workspacePlacement->nodeForWorkspace($workspace);

        if (! $app instanceof Project || ! $workspaceNode instanceof Node || $workspaceNode->id !== $node->id) {
            return [
                'family' => 'process',
                'node' => $node->name,
                'code' => $key,
                'key' => $key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to restore {$key}.",
                'details' => [
                    'workspace' => $workspace->name,
                    'process' => $process->name,
                    'error' => 'Process workspace has no active parent project node.',
                ],
            ];
        }

        try {
            app(EnsureFrankenPhpRuntimeProcess::class)->forWorkspace($workspace);
            $this->ensureWorkspaceRuntimeTlsMaterial($workspace, $workspaceNode);

            $container = app(WorkspaceRuntimeContainerRenderer::class)->render($workspace);
            $outcome = $this->workspaceRuntimeContainerManagerForAgentPush()->apply($workspaceNode, $container);
        } catch (Throwable $e) {
            return [
                'family' => 'process',
                'node' => $workspaceNode instanceof Node ? $workspaceNode->name : $node->name,
                'code' => $key,
                'key' => $key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to restore {$key}.",
                'details' => [
                    'app' => $app->name,
                    'workspace' => $workspace->name,
                    'process' => $process->name,
                    'error' => $e->getMessage(),
                ],
            ];
        }

        return [
            'family' => 'process',
            'node' => $workspaceNode->name,
            'code' => $key,
            'key' => $key,
            'mode' => 'restore',
            'status' => 'completed',
            'summary' => "Restored managed FrankenPHP runtime container for workspace {$workspace->name}.",
            'details' => [
                'app' => $app->name,
                'workspace' => $workspace->name,
                'process' => $process->name,
                'container' => $container->name(),
                'outcome' => $outcome->value,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function restoreProcessEventNotifierIssue(Node $node, string $key): array
    {
        $renderer = app(ProcessEventNotifierRenderer::class);
        $gatewayEndpoint = $renderer->expectedGatewayEndpoint();

        if ($gatewayEndpoint === null) {
            return [
                'family' => 'process',
                'node' => $node->name,
                'code' => $key,
                'key' => $key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to restore {$key}.",
                'details' => [
                    'error' => 'Gateway endpoint is not configured.',
                ],
            ];
        }

        try {
            $results = [
                $renderer->installPath() => $this->writeProcessEventNotifierFile(
                    node: $node,
                    path: $renderer->installPath(),
                    content: $renderer->content(),
                    mode: '0755',
                ),
                $renderer->gatewayEndpointPath() => $this->writeProcessEventNotifierFile(
                    node: $node,
                    path: $renderer->gatewayEndpointPath(),
                    content: "{$gatewayEndpoint}\n",
                    mode: '0644',
                ),
            ];
        } catch (Throwable $exception) {
            return [
                'family' => 'process',
                'node' => $node->name,
                'code' => $key,
                'key' => $key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to restore {$key}.",
                'details' => [
                    'script' => $renderer->installPath(),
                    'gateway_endpoint' => $renderer->gatewayEndpointPath(),
                    'error' => $exception->getMessage(),
                ],
            ];
        }

        foreach ($results as $path => $result) {
            if ($result->successful()) {
                continue;
            }

            return [
                'family' => 'process',
                'node' => $node->name,
                'code' => $key,
                'key' => $key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to restore {$key}.",
                'details' => [
                    'script' => $renderer->installPath(),
                    'gateway_endpoint' => $renderer->gatewayEndpointPath(),
                    'failed_path' => $path,
                    'exit_code' => $result->exitCode,
                    'stderr' => trim($result->stderr),
                ],
            ];
        }

        return [
            'family' => 'process',
            'node' => $node->name,
            'code' => $key,
            'key' => $key,
            'mode' => 'restore',
            'status' => 'completed',
            'summary' => 'Restored process crash event notifier material.',
            'details' => [
                'script' => $renderer->installPath(),
                'gateway_endpoint' => $renderer->gatewayEndpointPath(),
            ],
        ];
    }

    private function writeProcessEventNotifierFile(
        Node $node,
        string $path,
        string $content,
        string $mode,
    ): RemoteShellResult {
        return app(RemoteLocalExecutor::class)->runInternal(
            node: $node,
            commandName: InternalCommand::ManagedFile->value,
            arguments: ['write'],
            transportOptions: [
                'input' => json_encode([
                    'path' => $path,
                    'content' => $content,
                    'mode' => $mode,
                    'directory_mode' => '0755',
                ], JSON_THROW_ON_ERROR),
                'metadata' => [
                    'ORBIT_OPERATION_ID' => 'process-event-notifier.restore',
                ],
                'timeout' => 30,
                'throw' => false,
            ],
        );
    }

    private function refreshManagedFrankenPhpProcessIntent(Process $process): void
    {
        $process->loadMissing(['owner', 'appInstance']);

        $config = $process->runtime_config;
        $hashLabel = is_string($config['container_spec_hash_label'] ?? null)
            ? $config['container_spec_hash_label']
            : null;

        if ($hashLabel === null || trim($hashLabel) === '') {
            return;
        }

        $ensureFrankenPhpRuntimeProcess = app(EnsureFrankenPhpRuntimeProcess::class);

        if ($hashLabel === AppRuntimeContainer::SpecHashLabel && $process->owner instanceof Project) {
            if (! $process->appInstance instanceof AppInstance) {
                throw new RuntimeException(
                    'A concrete instance is required to refresh FrankenPHP process intent.',
                );
            }

            $ensureFrankenPhpRuntimeProcess->forApp($process->owner, $process->appInstance);

            return;
        }

        if ($hashLabel === WorkspaceRuntimeContainer::SpecHashLabel && $process->owner instanceof Workspace) {
            $ensureFrankenPhpRuntimeProcess->forWorkspace($process->owner);
        }
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>|null
     */
    private function restoreUnrenderableProcessIssue(Node $node, string $key, array $detail): ?array
    {
        $process = $this->processFromIssueDetail($node, $detail);
        $service = is_string($detail['service'] ?? null) ? $detail['service'] : null;
        $version = is_string($detail['version'] ?? null)
            ? $detail['version']
            : (is_string($detail['version_family'] ?? null) ? $detail['version_family'] : null);

        if (! $process instanceof Process || $service === null) {
            return null;
        }

        $context = $this->processOwnerContext($node, $process);

        if (! $context instanceof ProcessOwnerContext || ! $context->owner instanceof Node) {
            return null;
        }

        try {
            $resolved = $this->processServiceCatalog->resolve(
                service: $service,
                version: $version,
                runtime: $process->runtime,
                node: $node,
                processName: $process->name,
            );

            $process->forceFill([
                'command' => $resolved->command,
                'runtime_config' => $resolved->runtimeConfig,
            ])->save();

            $process->refresh();
            $action = $this->applyNodeOwnedProcessIssue($node, $key, $process);
        } catch (Throwable $e) {
            return [
                'family' => 'process',
                'node' => $node->name,
                'code' => $key,
                'key' => $key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to restore {$key}.",
                'details' => [
                    'node' => $node->name,
                    'process' => $process->name,
                    'service' => $service,
                    'version' => $version,
                    'runtime' => $process->runtime->value,
                    'error' => $e->getMessage(),
                ],
            ];
        }

        if ($action === null) {
            return null;
        }

        $details = is_array($action['details'] ?? null) ? $action['details'] : [];
        $action['details'] = [
            ...$details,
            'service' => $service,
            'version' => $process->runtime_config['version'] ?? $version,
            'runtime' => $process->runtime->value,
        ];

        if (($action['status'] ?? null) === 'completed') {
            $action['summary'] = "Restored managed service runtime config for process {$process->name}.";
        }

        return $action;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function applyNodeOwnedProcessIssue(Node $node, string $key, Process $process): ?array
    {
        $context = $this->processOwnerContext($node, $process);

        if (! $context instanceof ProcessOwnerContext || ! $context->owner instanceof Node) {
            return null;
        }

        try {
            $runtimeApp = $context->runtimeApp();
            $workspace = $context->runtimeWorkspaceFor($process);
            $driver = $this->processRuntimeDrivers->forProcess($process);
            $runtimeUnit = $driver->runtimeUnitName($runtimeApp, $process, $workspace);
            $restored = $driver->apply($node, $runtimeApp, $process, $workspace);
        } catch (Throwable $e) {
            return [
                'family' => 'process',
                'node' => $node->name,
                'code' => $key,
                'key' => $key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to restore {$key}.",
                'details' => [
                    'node' => $node->name,
                    'process' => $process->name,
                    'error' => $e->getMessage(),
                ],
            ];
        }

        if (! $restored) {
            return [
                'family' => 'process',
                'node' => $node->name,
                'code' => $key,
                'key' => $key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to restore process runtime unit {$runtimeUnit}.",
                'details' => [
                    'node' => $node->name,
                    'process' => $process->name,
                    'runtime_unit' => $runtimeUnit,
                ],
            ];
        }

        return [
            'family' => 'process',
            'node' => $node->name,
            'code' => $key,
            'key' => $key,
            'mode' => 'restore',
            'status' => 'completed',
            'summary' => "Restored process runtime unit {$runtimeUnit}.",
            'details' => [
                'node' => $node->name,
                'process' => $process->name,
                'runtime_unit' => $runtimeUnit,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function processFromIssueDetail(Node $node, array $detail): ?Process
    {
        $processName = is_string($detail['process'] ?? null) ? $detail['process'] : null;

        if ($processName === null) {
            return null;
        }

        $appName = is_string($detail['app'] ?? null) ? $detail['app'] : null;
        $appInstanceName = is_string($detail['app_instance'] ?? null) ? $detail['app_instance'] : null;

        if (($appName === null) !== ($appInstanceName === null)) {
            return null;
        }

        $placement = app(WorkspacePlacement::class);
        /** @var list<int> $placedInstanceIds */
        $placedInstanceIds = [];

        foreach (AppInstance::query()->get() as $instance) {
            if ($placement->nodeForInstance($instance)?->is($node) === true) {
                $placedInstanceIds[] = $instance->id;
            }
        }

        /** @var Collection<int, Process> $processes */
        $processes = $this
            ->processQueryForNode($node, $placedInstanceIds)
            ->with(['owner', 'appInstance.app'])
            ->where('name', $processName)
            ->when(
                $appName !== null && $appInstanceName !== null,
                fn (Builder $query): Builder => $query->whereHas(
                    'appInstance',
                    fn (Builder $instanceQuery): Builder => $instanceQuery
                        ->where('name', $appInstanceName)
                        ->whereHas(
                            'app',
                            fn (Builder $appQuery): Builder => $appQuery->where('name', $appName),
                        ),
                ),
            )
            ->get();

        /** @var Collection<int, Process> $processes */
        $processes = $processes
            ->filter(fn (Process $process): bool => $this->processBelongsToNode($process, $node, $placement))
            ->values();
        $runtimeUnit = is_string($detail['runtime_unit'] ?? null) ? $detail['runtime_unit'] : null;
        $runtimeProcess = $this->processForRuntimeUnit($node, $processes, $runtimeUnit);

        if ($runtimeProcess instanceof Process) {
            return $runtimeProcess;
        }

        return $processes->count() === 1 ? $processes->first() : null;
    }

    /**
     * @param  Collection<int, Process>  $processes
     */
    private function processForRuntimeUnit(Node $node, Collection $processes, ?string $runtimeUnit): ?Process
    {
        if ($runtimeUnit === null) {
            return null;
        }

        return $processes->first(
            fn (Process $process): bool => $this->runtimeUnitNameForProcess($node, $process) === $runtimeUnit,
        );
    }

    private function runtimeUnitNameForProcess(Node $node, Process $process): ?string
    {
        $context = $this->processOwnerContext($node, $process);

        if (! $context instanceof ProcessOwnerContext) {
            return null;
        }

        try {
            $driver = $this->processRuntimeDrivers->forProcess($process);

            return $driver->runtimeUnitName($context->runtimeApp(), $process, $context->runtimeWorkspaceFor($process));
        } catch (Throwable) {
            return null;
        }
    }

    private function processOwnerContext(Node $node, Process $process): ?ProcessOwnerContext
    {
        $process->loadMissing(['owner', 'appInstance']);

        if ($process->owner instanceof Node) {
            return new ProcessOwnerContext(
                node: $node,
                app: null,
                workspace: null,
                owner: $process->owner,
            );
        }

        if ($process->owner instanceof Project) {
            if (! $process->appInstance instanceof AppInstance) {
                return null;
            }

            return new ProcessOwnerContext(
                node: $node,
                app: $process->owner,
                workspace: null,
                owner: $process->owner,
                appInstance: $process->appInstance,
            );
        }

        if ($process->owner instanceof Workspace) {
            $process->owner->loadMissing(['app', 'appInstance']);

            if (
                ! $process->owner->app instanceof Project
                || ! $process->appInstance instanceof AppInstance
                || ! $process->owner->appInstance instanceof AppInstance
                || ! $process->appInstance->is($process->owner->appInstance)
            ) {
                return null;
            }

            return new ProcessOwnerContext(
                node: $node,
                app: $process->owner->app,
                workspace: $process->owner,
                owner: $process->owner,
                appInstance: $process->appInstance,
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>|null
     */
    private function applyAppIssue(Node $node, string $key, array $detail): ?array
    {
        if ($key === 'app.runtime_config_probe_failed') {
            return $this->handleAppRuntimeConfigProbeFailed($node);
        }

        $appName = is_string($detail['app'] ?? null) ? $detail['app'] : null;

        if ($appName === null) {
            return null;
        }

        if ($key === 'app.runtime_config_extra') {
            return $this->handleAppConfigExtraAction($node, $appName);
        }

        $appInstanceName = is_string($detail['app_instance'] ?? null) ? $detail['app_instance'] : null;

        if ($appInstanceName !== null) {
            $app = Project::query()
                ->with(['node', 'instances'])
                ->where('name', $appName)
                ->first();
            $instance = $app instanceof Project
                ? $app->instances->firstWhere('name', $appInstanceName)
                : null;

            if (
                $app instanceof Project
                && $instance instanceof AppInstance
                && $this->workspacePlacement->nodeForInstance($instance)?->id === $node->id
            ) {
                return $this->handleAppInstanceAction(
                    $app,
                    $instance,
                    $this->driftEntryFromStoredParts('app', $key, $detail),
                );
            }

            return null;
        }

        $app = Project::query()
            ->with('node')
            ->where('node_id', $node->id)
            ->where('name', $appName)
            ->first();

        if (! $app instanceof Project) {
            return null;
        }

        return $this->handleAppAction($app, $this->driftEntryFromStoredParts('app', $key, $detail));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function handleAppInstanceAction(Project $app, AppInstance $instance, DriftEntry $entry): ?array
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
                    'app_instance' => $instance->name,
                    'error' => $e->getMessage(),
                ],
            ];
        }
    }

    /**
     * Re-probe the managed runtime config directory after a permission/probe
     * failure. If the probe now succeeds the drift clears; if it still fails
     * the doctor run emits the probe-failed drift again so the operator can
     * investigate the underlying daemon/permission issue.
     *
     * @return array<string, mixed>
     */
    private function handleAppRuntimeConfigProbeFailed(Node $node): array
    {
        try {
            $probe = $this->appsProbe->introspectNodeRuntimeConfigs($node);
        } catch (Throwable $e) {
            return [
                'family' => 'app',
                'node' => $node->name,
                'code' => 'app.runtime_config_probe_failed',
                'key' => 'app.runtime_config_probe_failed',
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to re-probe managed runtime config directory on {$node->name}.",
                'details' => [
                    'error' => $e->getMessage(),
                ],
            ];
        }

        if ($probe->status === NodeRuntimeConfigsProbeStatus::Error) {
            return [
                'family' => 'app',
                'node' => $node->name,
                'code' => 'app.runtime_config_probe_failed',
                'key' => 'app.runtime_config_probe_failed',
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Managed runtime config directory probe still failing on {$node->name}.",
                'details' => [
                    'path' => "{$this->nodeHostPaths->userConfigRoot($node)}/apps",
                    'error' => $probe->error,
                ],
            ];
        }

        return [
            'family' => 'app',
            'node' => $node->name,
            'code' => 'app.runtime_config_probe_failed',
            'key' => 'app.runtime_config_probe_failed',
            'mode' => 'restore',
            'status' => 'completed',
            'summary' => "Re-probed managed runtime config directory on {$node->name}.",
            'details' => [
                'path' => "{$this->nodeHostPaths->userConfigRoot($node)}/apps",
                'status' => $probe->status->value,
            ],
        ];
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
     * @param  array<string, mixed>  $issue
     * @return array<string, mixed>|null
     */
    private function applyProxyIssue(Node $fallbackNode, string $mode, string $key, array $detail, array $issue): ?array
    {
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

        if (($issue['kind'] ?? null) === DriftKind::Extra->value) {
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
                summary: (string) (
                    $issue['summary'] ?? "Proxy route '{$key}' exists on node but not in gateway registry."
                ),
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
     * @param  array<string, mixed>  $issue
     * @return array<string, mixed>
     */
    private function applyDnsProjectionIssue(Node $node, array $issue): array
    {
        $key = (string) $issue['key'];
        $family = $key === 'node.dns_mapping_mismatch' ? 'node' : 'proxy';
        $targetNode = $this->nodeFromIssue($issue) ?? $node;
        $detail = is_array($issue['detail'] ?? null) ? $issue['detail'] : [];

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
            'summary' => is_string($issue['summary'] ?? null) ? $issue['summary'] : "Fixed {$key}.",
            'details' => $detail,
        ];
    }

    /**
     * @param  array<string, mixed>  $detail
     * @param  array<string, mixed>  $issue
     * @return array<string, mixed>|null
     */
    private function applyDnsRuntimeIssue(Node $node, string $key, array $detail, array $issue): ?array
    {
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
                ? (is_string($issue['summary'] ?? null) ? $issue['summary'] : "Fixed {$key}.")
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
    private function applyScheduleIssue(Node $node, string $key, array $detail, array $issue): ?array
    {
        $scheduleKey = is_string($detail['schedule_key'] ?? null) ? $detail['schedule_key'] : null;

        if (in_array(
            $key,
            [
                'schedule.scheduler_missing',
                'schedule.scheduler_stopped',
                'schedule.scheduler_image_mismatch',
                'schedule.scheduler_replicas_mismatch',
                'schedule.lock_stuck',
            ],
            true,
        )) {
            $gatewayNode = $this->gatewayNode() ?? $this->nodeFromIssue($issue) ?? $node;
            $schedule = $scheduleKey === null
                ? null
                : Schedule::query()->where('schedule_key', $scheduleKey)->first();

            try {
                return $this->schedulesFixer->fixGateway(
                    $gatewayNode,
                    $this->driftEntryFromStoredParts('schedule', $key, $detail, $issue),
                    $schedule instanceof Schedule ? $schedule : null,
                );
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

    /**
     * @param  array<string, mixed>  $issue
     */
    private function driftEntryFromIssue(array $issue): DriftEntry
    {
        $kind = is_string($issue['kind'] ?? null) ? DriftKind::tryFrom($issue['kind']) : null;

        return new DriftEntry(
            family: is_string($issue['family'] ?? null) ? $issue['family'] : 'unknown',
            key: is_string($issue['key'] ?? null) ? $issue['key'] : 'unknown',
            kind: $kind ?? DriftKind::Unknown,
            summary: is_string($issue['summary'] ?? null) ? $issue['summary'] : '',
            detail: is_array($issue['detail'] ?? null) ? $issue['detail'] : [],
        );
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function driftEntryFromStoredParts(
        string $family,
        string $key,
        array $detail,
        array $issue = [],
    ): DriftEntry {
        $kind = is_string($issue['kind'] ?? null) ? DriftKind::tryFrom($issue['kind']) : null;

        return new DriftEntry(
            family: $family,
            key: $key,
            kind: $kind ?? DriftKind::Divergent,
            summary: is_string($issue['summary'] ?? null) ? $issue['summary'] : '',
            detail: $detail,
        );
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    private function nodeFromIssue(array $issue): ?Node
    {
        $nodeName = is_string($issue['node'] ?? null) ? $issue['node'] : null;

        if ($nodeName === null) {
            return null;
        }

        $node = Node::query()->where('name', $nodeName)->first();

        return $node instanceof Node ? $node : null;
    }

    /**
     * @param  array<string, mixed>  $issue
     * @return array<string, mixed>
     */
    private function annotateIssue(array $issue): array
    {
        $family = is_string($issue['family'] ?? null) ? $issue['family'] : '';
        $key = is_string($issue['key'] ?? null) ? $issue['key'] : '';
        $code = is_string($issue['code'] ?? null) ? $issue['code'] : $key;
        $kind = is_string($issue['kind'] ?? null) ? $issue['kind'] : '';
        $restorableKeys = [
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
            'proxy.caddy_container_missing',
            'proxy.caddy_container_down',
            'proxy.caddy_container_detached',
            'proxy.global_config_missing',
            'proxy.global_config_mismatch',
            'proxy.dns_mapping_mismatch',
            'proxy.agent_tool_route_missing',
            'proxy.agent_tool_route_mismatch',
            WebSocketProxyDoctorProbe::RouterRouteKey,
            WebSocketProxyDoctorProbe::PublicRouteKey,
            S3ProxyDoctorProbe::RouterRouteKey,
            S3ProxyDoctorProbe::RouterBackendKey,
            S3ProxyDoctorProbe::PublicRouteKey,
            AnalyticsProxyDoctorProbe::RouterRouteKey,
            AnalyticsProxyDoctorProbe::RouterRouteOrphanedKey,
            AnalyticsPublicProxyDoctorProbe::PUBLIC_ROUTE_KEY,
            'workspace.security.system_user',
            'workspace.security.fs_permissions',
            'app.runtime_config_missing',
            'app.runtime_config_mismatch',
            'app.runtime_config_extra',
            'app.runtime_config_probe_failed',
            'app.security.system_user',
            'app.security.fs_permissions',
            'firewall_rule.rule_missing',
            'firewall_rule.rule_mismatch',
            'process.runtime_unit_missing',
            'process.runtime_unit_mismatch',
            'process.runtime_unit_down',
            'process.runtime_unit_extra',
            'process.runtime_unit_unrenderable',
            'process.event_notifier_missing',
            'process.event_notifier_mismatch',
            'tool.capability_missing',
            'tool.container_missing',
            'tool.container_not_running',
            'tool.container_spec_mismatch',
            'tool.version_mismatch',
            'tool.config_missing',
            'tool.config_mismatch',
            'tool.credentials_missing',
            'tool.credentials_mismatch',
            'tool.dns_container_missing',
            'tool.dns_port_not_listening',
            'tool.dns_base_config_mismatch',
            'tool.dns_client_dns_drift',
            'tool.dns_forwarding_missing',
            'schedule.scheduler_missing',
            'schedule.scheduler_stopped',
            'schedule.scheduler_image_mismatch',
            'schedule.scheduler_replicas_mismatch',
            'schedule.lock_stuck',
            'node.role_convergence_failed',
            'node.role_baseline_mismatch',
            'node.dns_mapping_mismatch',
            'node.websocket.backend_cert_missing',
            'node.websocket.bind_public_interface',
            'node.security.sshd_config',
            'node.security.sshd_listen',
            'node.security.public_ssh_deny',
            'node.security.sysctl',
            'node.security.home_perms',
            'node.updates_config_missing',
            'node.updates_config_mismatch',
            'node.updates_dry_run_failed',
            'node.updates_last_run_failed',
            'node.updates_unverifiable',
            'database_connection.env_missing',
            'database_connection.env_mismatch',
            'database_connection.target_missing',
        ];

        return [
            ...$issue,
            'code' => $code,
            'restorable' =>
                in_array($code, $restorableKeys, true) || $family === 'proxy' && $kind === DriftKind::Extra->value,
            'adoptable' =>
                ($family === 'proxy' || $family === 'firewall_rule') && $kind === DriftKind::Extra->value
                    || $family === 'database_connection'
                    && in_array(
                        $key,
                        [
                            'database_connection.env_extra',
                            'database_connection.target_extra',
                            'database_connection.env_mismatch',
                        ],
                        true,
                    ),
        ];
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    private function issueSupportsMode(array $issue, string $mode): bool
    {
        if ($mode === 'adopt') {
            return ($issue['adoptable'] ?? false) === true;
        }

        return ($issue['restorable'] ?? false) === true;
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
     * @param  list<array<string, mixed>>  $issues
     * @param  list<array<string, mixed>>  $actions
     * @return list<array<string, mixed>>
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
            fn (array $issue): bool => (
                ! in_array($this->issueResolutionId($issue), $resolvedIssueIds, true)
                && ! $this->databaseConnectionIssueResolved($issue, $resolvedDatabaseTargets)
            ),
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $actions
     * @param  list<array<string, mixed>>  $remainingIssues
     * @return list<array<string, mixed>>
     */
    private function markVerifiedRestoreActionsWithRemainingDriftAsFailed(
        array $actions,
        array $remainingIssues,
    ): array {
        $verifiedActions = [];

        foreach ($actions as $action) {
            $verifiedActions[] = $this->verifyCompletedDnsToolAction(
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

        return $verifiedActions;
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  list<array<string, mixed>>  $remainingIssues
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
            fn (array $issue): bool => ($issue['family'] ?? null) === 'tool'
            && in_array($issue['key'] ?? null, self::VERIFIED_DNS_TOOL_RESTORE_KEYS, true),
        );

        if (! is_array($remainingIssue)) {
            return $action;
        }

        $key = $this->stringValue($remainingIssue, 'key');

        if ($key === null) {
            return $action;
        }

        $issueDetail = is_array($remainingIssue['detail'] ?? null) ? $remainingIssue['detail'] : [];
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
     * @param  list<array<string, mixed>>  $remainingIssues
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
            fn (array $issue): bool => (
                ($issue['family'] ?? null) === 'node'
                && ($issue['key'] ?? null) === 'node.dns_mapping_mismatch'
                && (
                    $this->stringValue($action, 'node') === null
                    || $this->stringValue($action, 'node') === $this->stringValue($issue, 'node')
                )
            ),
        );

        if (! is_array($remainingIssue)) {
            return $action;
        }

        $node = $this->stringValue($remainingIssue, 'node') ?? $this->stringValue($action, 'node') ?? 'unknown';
        $issueDetail = is_array($remainingIssue['detail'] ?? null) ? $remainingIssue['detail'] : [];
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
     * @param  list<array<string, mixed>>  $remainingIssues
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
            fn (array $issue): bool => $this->actionMatchesRemainingIssue($action, $issue),
        );

        if (! is_array($remainingIssue)) {
            return $action;
        }

        return $this->failedProxyAction($action, $remainingIssue);
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  list<array<string, mixed>>  $remainingIssues
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
            fn (array $issue): bool => (
                ($issue['family'] ?? null) === 'node'
                && (
                    $this->stringValue($action, 'node') === null
                    || $this->stringValue($action, 'node') === $this->stringValue($issue, 'node')
                )
                && $this->issueResolutionId($action) === $this->issueResolutionId($issue)
            ),
        );

        if (! is_array($remainingIssue)) {
            return $action;
        }

        $node = $this->stringValue($remainingIssue, 'node') ?? $this->stringValue($action, 'node') ?? 'unknown';
        $key = $this->stringValue($remainingIssue, 'key') ?? $this->stringValue($action, 'key') ?? 'unknown';
        $issueDetail = is_array($remainingIssue['detail'] ?? null) ? $remainingIssue['detail'] : [];
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
     * @param  array<string, mixed>  $remainingIssue
     * @return array<string, mixed>
     */
    private function failedProxyAction(array $action, array $remainingIssue): array
    {
        $node = $this->stringValue($remainingIssue, 'node') ?? $this->stringValue($action, 'node') ?? 'unknown';
        $key = $this->stringValue($remainingIssue, 'key') ?? $this->stringValue($action, 'key') ?? 'unknown';
        $operation = "verify {$key}";
        $issueDetail = is_array($remainingIssue['detail'] ?? null) ? $remainingIssue['detail'] : [];
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
     * @param  array<string, mixed>  $issue
     */
    private function actionMatchesRemainingIssue(array $action, array $issue): bool
    {
        if (($action['family'] ?? null) !== 'proxy' || ($issue['family'] ?? null) !== 'proxy') {
            return false;
        }

        $actionDetails = is_array($action['details'] ?? null) ? $action['details'] : [];
        $issueDetail = is_array($issue['detail'] ?? null) ? $issue['detail'] : [];
        $actionDomain = is_string($actionDetails['route'] ?? null) ? $actionDetails['route'] : null;
        $issueDomain = is_string($issueDetail['domain'] ?? null) ? $issueDetail['domain'] : null;

        if ($actionDomain !== null) {
            return $actionDomain === $issueDomain;
        }

        $actionNode = is_string($action['node'] ?? null) ? $action['node'] : null;
        $issueNode = is_string($issue['node'] ?? null) ? $issue['node'] : null;

        return (
            ($actionNode === null || $actionNode === $issueNode)
            && $this->issueResolutionId($action) === $this->issueResolutionId($issue)
        );
    }

    /**
     * @param  array<string, mixed>  $probe
     * @return list<array<string, mixed>>
     */
    private function issuesFromProbe(array $probe): array
    {
        $probeIssues = is_array($probe['issues'] ?? null) ? $probe['issues'] : [];
        $issues = [];

        foreach ($probeIssues as $issue) {
            if (! is_array($issue)) {
                continue;
            }

            /** @var array<string, mixed> $issue */
            $issues[] = $issue;
        }

        return $issues;
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

    /**
     * @param  array<string, mixed>  $issue
     * @param  list<string>  $resolvedTargets
     */
    private function databaseConnectionIssueResolved(array $issue, array $resolvedTargets): bool
    {
        if (($issue['family'] ?? null) !== 'database_connection') {
            return false;
        }

        $detail = is_array($issue['detail'] ?? null) ? $issue['detail'] : [];
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
    private function firewallIssuePayload(DriftEntry $entry, FirewallRule $rule): array
    {
        $rule->loadMissing('node');

        return $this->annotateIssue([
            'family' => $entry->family,
            'node' => $rule->node->name,
            'key' => $entry->key,
            'kind' => $entry->kind->value,
            'summary' => $entry->summary,
            'detail' => [
                ...($entry->detail ?? []),
                'rule' => $rule->name,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function toolIssuePayload(DriftEntry $entry, NodeTool $tool): array
    {
        $tool->loadMissing('node');

        return $this->annotateIssue([
            'family' => $entry->family,
            'node' => $tool->node?->name,
            'key' => $entry->key,
            'kind' => $entry->kind->value,
            'summary' => $entry->summary,
            'detail' => [
                ...($entry->detail ?? []),
                'tool' => $tool->name,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduleIssuePayload(DriftEntry $entry, Schedule $schedule): array
    {
        return $this->annotateIssue([
            'family' => $entry->family,
            'node' => $this->scheduleNodeName($schedule),
            'key' => $entry->key,
            'kind' => $entry->kind->value,
            'summary' => $entry->summary,
            'detail' => [
                ...($entry->detail ?? []),
                'schedule_key' => $schedule->schedule_key,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduleGatewayIssuePayload(DriftEntry $entry, Node $gatewayNode): array
    {
        return $this->annotateIssue([
            'family' => $entry->family,
            'node' => $gatewayNode->name,
            'key' => $entry->key,
            'kind' => $entry->kind->value,
            'summary' => $entry->summary,
            'detail' => $entry->detail,
        ]);
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

    private function ensureAppRuntimeTlsMaterial(Project $app, Node $node): void
    {
        $innerTlsPolicy = app(AppDevelopmentInnerTlsPolicy::class);

        if (! $innerTlsPolicy->appliesToApp($app)) {
            return;
        }

        app(SiteCertificateInstaller::class)->ensureFor(
            $node,
            $innerTlsPolicy->appRouteDomain($app),
        );
    }

    private function ensureWorkspaceRuntimeTlsMaterial(Workspace $workspace, Node $node): void
    {
        $innerTlsPolicy = app(AppDevelopmentInnerTlsPolicy::class);

        if (! $innerTlsPolicy->appliesToWorkspace($workspace)) {
            return;
        }

        app(SiteCertificateInstaller::class)->ensureFor(
            $node,
            $innerTlsPolicy->workspaceRouteDomain($workspace),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function handleAppAction(Project $app, DriftEntry $entry): ?array
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
                'node' => $this->scheduleNodeName($schedule),
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

    private function scheduleNodeName(Schedule $schedule): ?string
    {
        $schedule->loadMissing(['app', 'appInstance', 'node']);

        if ($schedule->scope === 'app') {
            return $schedule->appInstance instanceof AppInstance
                ? $this->workspacePlacement->nodeForInstance($schedule->appInstance)?->name
                : null;
        }

        if ($schedule->scope === 'node') {
            return $schedule->node?->name;
        }

        if ($schedule->scope === 'orbit') {
            $node = $this->nodeRoleAssignments->activeGatewayNodeQuery()->first();

            return $node instanceof Node ? $node->name : null;
        }

        return null;
    }

    /**
     * @return Collection<int, Schedule>
     */
    private function schedulesForNode(Node $node): Collection
    {
        if ($this->nodeRoleAssignments->nodeIsGateway($node)) {
            return Schedule::query()
                ->with(['app', 'appInstance', 'node'])
                ->where('enabled', true)
                ->where('status', 'expected')
                ->get();
        }

        return $this
            ->expectedSchedulesTargetingNode($node)
            ->with(['app', 'appInstance', 'node'])
            ->get();
    }

    /**
     * @return Builder<Schedule>
     */
    private function expectedSchedulesTargetingNode(Node $node): Builder
    {
        /** @var Builder<Schedule> $query */
        $query = Schedule::query();
        $appInstanceIds = $this->appInstancesForNode($node)->pluck('id')->all();

        return $query
            ->where('enabled', true)
            ->where('status', 'expected')
            ->where(function (Builder $query) use ($node, $appInstanceIds): void {
                $query
                    ->where('node_id', $node->id)
                    ->orWhereIn('app_instance_id', $appInstanceIds);
            });
    }

    private function gatewayNode(): ?Node
    {
        $node = $this->nodeRoleAssignments->activeGatewayNodeQuery()->first();

        return $node instanceof Node ? $node : null;
    }

    /**
     * @param  list<array<string, mixed>>  $issues
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
            fn (array $issue): array => $this->unsupportedAction($mode, $issue),
            array_filter(
                $issues,
                fn (array $issue): bool => ! in_array($this->issueResolutionId($issue), $actionIds, true),
            ),
        ));
    }

    /**
     * @param  array<string, mixed>  $issue
     * @return array<string, mixed>
     */
    private function unsupportedAction(string $mode, array $issue): array
    {
        $key = is_string($issue['key'] ?? null) ? $issue['key'] : null;
        $code = is_string($issue['code'] ?? null) ? $issue['code'] : $key;

        return [
            'family' => $issue['family'] ?? null,
            'node' => $issue['node'] ?? null,
            'code' => $code,
            'key' => $key,
            'mode' => $mode,
            'status' => 'skipped',
            'summary' => "No {$mode} action is registered for ".($code ?? 'this issue').'.',
            'details' => [
                'reason' => 'mode_not_supported',
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @return list<array<string, mixed>>
     */
    private function filterIssuesByKey(array $issues, ?string $key): array
    {
        if ($key === null) {
            return $issues;
        }

        return array_values(array_filter(
            $issues,
            fn (array $issue): bool => ($issue['key'] ?? null) === $key,
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @return list<array<string, mixed>>
     */
    private function plannedActions(string $mode, array $issues): array
    {
        return array_values(array_map(
            fn (array $issue): array => $this->issueSupportsMode($issue, $mode)
                ? $this->plannedAction($mode, $issue)
                : $this->unsupportedAction($mode, $issue),
            $issues,
        ));
    }

    /**
     * @param  array<string, mixed>  $issue
     * @return array<string, mixed>
     */
    private function plannedAction(string $mode, array $issue): array
    {
        $key = (string) ($issue['key'] ?? 'this issue');
        $code = is_string($issue['code'] ?? null) ? $issue['code'] : $key;

        $issueDetail = is_array($issue['detail'] ?? null) ? $issue['detail'] : [];

        return [
            'family' => $issue['family'] ?? null,
            'node' => $issue['node'] ?? null,
            'code' => $code,
            'key' => $issue['key'] ?? null,
            'mode' => $mode,
            'status' => 'planned',
            'summary' => "Would {$mode} {$code}.",
            'details' => [
                ...$issueDetail,
                'dry_run' => true,
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @param  list<array<string, mixed>>  $actions
     * @return array{issues: int, fixed: int, adopted: int, skipped: int, conflicts: int, failed: int, planned: int}
     */
    private function summary(string $mode, array $issues, array $actions): array
    {
        return [
            'issues' => count($issues),
            'fixed' => count(array_filter(
                $actions,
                fn (array $action): bool => in_array($action['mode'] ?? null, ['fix', 'restore'], true)
                && ($action['status'] ?? null) === 'completed',
            )),
            'adopted' => count(array_filter(
                $actions,
                fn (array $action): bool => ($action['mode'] ?? null) === 'adopt'
                && in_array(
                    needle: $action['status'] ?? null,
                    haystack: ['completed', 'created', 'updated'],
                    strict: true,
                ),
            )),
            'skipped' => count(array_filter(
                $actions,
                fn (array $action): bool => ($action['status'] ?? null) === 'skipped',
            )),
            'conflicts' => count(array_filter(
                $actions,
                static fn (array $action): bool => ($action['status'] ?? null) === 'conflict',
            )),
            'failed' => count(array_filter(
                $actions,
                static fn (array $action): bool => ($action['status'] ?? null) === 'failed',
            )),
            'planned' => count(array_filter(
                $actions,
                static fn (array $action): bool => ($action['status'] ?? null) === 'planned',
            )),
        ];
    }
}
