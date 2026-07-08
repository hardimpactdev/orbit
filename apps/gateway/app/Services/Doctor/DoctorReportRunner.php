<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Actions\Apps\EnsureAppProcessRuntimeUnits;
use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Data\Doctor\DoctorRunRequest;
use App\Data\Doctor\DoctorTargetScope;
use App\Data\Doctor\DriftEntry;
use App\Enums\Apps\AppRuntimeKind;
use App\Enums\Apps\NodeRuntimeConfigsProbeStatus;
use App\Enums\Apps\NodeRuntimeContainersProbeStatus;
use App\Enums\DriftKind;
use App\Enums\Nodes\NodeConvergenceContext;
use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Nodes\NodeStatus;
use App\Exceptions\RemoteShellFailed;
use App\Models\App;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Models\Process;
use App\Models\ProxyRoute;
use App\Models\Schedule;
use App\Models\Workspace;
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
use App\Services\Firewall\FirewallRuleFixer;
use App\Services\Firewall\FirewallRuleProbe;
use App\Services\Nodes\NodeConverger;
use App\Services\Nodes\NodeHostPaths;
use App\Services\Nodes\NodesProbe;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Processes\EnsureFrankenPhpRuntimeProcess;
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
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process as ProcessFacade;
use Throwable;

final readonly class DoctorReportRunner
{
    public const int FLEET_PROBE_BATCH_SIZE = 5;

    private const int FLEET_PROBE_POLL_INTERVAL_MICROSECONDS = 50_000;

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
        'firewall_rule',
        'tool',
        'schedule',
        'database_connection',
    ];

    private const array APP_PRODUCTION_CATEGORIES = [
        'node',
        'app',
        'process',
        'proxy',
        'firewall_rule',
        'tool',
        'schedule',
        'database_connection',
    ];

    private const array DATABASE_CATEGORIES = ['node', 'tool', 'process'];

    private const array AGENT_CATEGORIES = ['node', 'tool'];

    private const array INGRESS_CATEGORIES = ['node', 'proxy', 'firewall_rule', 'tool'];

    private const array ROUTER_CATEGORIES = ['node', 'proxy'];

    private const array WEBSOCKET_CATEGORIES = ['node', 'tool'];

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
        private AppRuntimeRequirementProbe $appRuntimeRequirementProbe,
        private DnsRuntimeProbe $dnsRuntimeProbe,
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
            $node->isActive()
            && $this->isUbuntuPlatform($node)
            && $this->nodeRoleAssignments->nodeCanOwnFirewallRules($node)
        ) {
            $categories[] = 'firewall_rule';
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
            } catch (\JsonException) {
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
        } catch (\JsonException) {
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

        if ($mode === 'restore' && $key === 'node.updates') {
            return $this->finalize(
                $this->probe($node, $families, $key, scope: $scope),
                $mode,
                $actions,
            );
        }

        return $this->finalize($probe, $mode, $actions);
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
                1
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
            $apps = App::query()->with('node')->where('node_id', $node->id)->get();
            $appCheckTotal = $apps->count() + 2;

            $this->runFamilyCheckPlan($onFamilyProgress, 'app', $appCheckTotal, function (callable $advance) use (
                $apps,
                $node,
                &$issues,
            ): void {
                $this->probeAppFamily($node, $apps, $issues, $advance);
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
            $this->runFamilyCheckPlan(
                $onFamilyProgress,
                'process',
                Process::query()->with('owner')->where('node_id', $node->id)->count(),
                function (callable $advance) use ($node, &$issues): void {
                    foreach (Process::query()->with('owner')->where('node_id', $node->id)->get() as $process) {
                        $snapshot = $this->processesProbe->introspect($process);

                        foreach ($this->processesProbe->diff($process, $snapshot) as $entry) {
                            $issues[] = $this->processIssuePayload($entry, $process);
                        }

                        $advance();
                    }
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
            $proxyCheckTotal =
                ProxyRoute::query()->where('node_id', $node->id)->count()
                + 2
                + ($node->isActive() && $this->nodeRoleAssignments->nodeHostsOrbitCaddy($node) ? 1 : 0)
                + ($node->isActive() && $this->canServeGatewayOrAppHost($node) ? 1 : 0);

            $this->runFamilyCheckPlan($onFamilyProgress, 'proxy', $proxyCheckTotal, function (callable $advance) use (
                $node,
                &$issues,
            ): void {
                $this->probeProxyFamily($node, $issues, $advance);
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
     * @return array{families: list<string>, node: string, role: string, self: false, app: string|null, workspace: string|null, key: string|null}
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
     * @param  Collection<int, App>  $apps
     * @param  list<array<string, mixed>>  $issues
     */
    private function probeAppFamily(Node $node, Collection $apps, array &$issues, callable $advance): void
    {
        foreach ($apps as $app) {
            $snapshot = $this->appsProbe->introspect($app);

            foreach ($this->appsProbe->diff($app, $snapshot) as $entry) {
                $issues[] = $this->appIssuePayload($entry, $app);
            }

            $app->loadMissing('instances');

            foreach ($app->instances as $instance) {
                foreach ($this->appRuntimeRequirementProbe->drift($instance) as $entry) {
                    $issues[] = $this->appIssuePayload($entry, $app);
                }
            }

            $advance();
        }

        $containerProbe = $this->appsProbe->introspectNode($node);
        $containerSnapshot = $containerProbe->containers;
        $activePhpAppSlugs = App::query()
            ->where('node_id', $node->id)
            ->where('runtime', AppRuntimeKind::Php->value)
            ->pluck('name')
            ->all();

        if ($containerProbe->status === NodeRuntimeContainersProbeStatus::Error) {
            $issues[] = $this->annotateIssue([
                'family' => 'app',
                'node' => $node->name,
                'key' => 'app.runtime_container_probe_failed',
                'kind' => DriftKind::Unverifiable->value,
                'summary' => "App runtime container scan failed on node '{$node->name}'; stale orphan runtime containers cannot be detected.",
                'detail' => [
                    'error' => $containerProbe->error,
                ],
            ]);
        } elseif ($containerProbe->status === NodeRuntimeContainersProbeStatus::Present) {
            foreach ($containerSnapshot->keys() as $appSlug) {
                if (in_array($appSlug, $activePhpAppSlugs, true)) {
                    continue;
                }

                $issues[] = $this->annotateIssue([
                    'family' => 'app',
                    'node' => $node->name,
                    'key' => 'app.runtime_container_extra',
                    'kind' => DriftKind::Extra->value,
                    'summary' => "App runtime container for '{$appSlug}' exists on node but no matching active PHP app record.",
                    'detail' => [
                        'app' => $appSlug,
                        'container' => "orbit-app-{$appSlug}",
                    ],
                ]);
            }
        }

        $advance();

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
     * @param  list<array<string, mixed>>  $issues
     */
    private function probeProxyFamily(Node $node, array &$issues, callable $advance): void
    {
        foreach (ProxyRoute::query()
            ->with(['node', 'app', 'workspace'])
            ->where('node_id', $node->id)
            ->get() as $route) {
            $snapshot = $this->proxyRouteProbe->introspect($route);

            foreach ($this->proxyRouteProbe->diff($route, $snapshot) as $entry) {
                $issues[] = $this->proxyIssuePayload($entry, $route);
            }

            $advance();
        }

        foreach ($this->webSocketProxyDoctorProbe->drift($node) as $entry) {
            $issues[] = $this->nodeScopedIssuePayload($entry, $node);
        }

        $advance();

        foreach ($this->s3ProxyDoctorProbe->drift($node) as $entry) {
            $issues[] = $this->nodeScopedIssuePayload($entry, $node);
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

        if (! $node->isActive() || ! $this->canServeGatewayOrAppHost($node)) {
            return;
        }

        try {
            $snapshot = $this->proxyRouteProbe->introspectNode($node);
            $expectedDomains = $this->proxyRouteProbe->expectedDomainsForNode($node);

            foreach ($snapshot->keys() as $domain) {
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

            if (
                $mode === 'restore'
                && ($issue['family'] ?? null) === 'tool'
                && is_string($issue['key'] ?? null)
                && str_starts_with($issue['key'], 'dns.')
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
    private function appIssuePayload(DriftEntry $entry, App $app): array
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
        $node = $app instanceof App ? $app->node : $process->node;

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
            $snapshot = $this->proxyRouteProbe->snapshotForAdopt($node);

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

    private function canServeGatewayOrAppHost(Node $node): bool
    {
        return $this->nodeRoleAssignments->nodeCanServeGatewayOrAppHostWorkloads($node);
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

        $target = DatabaseConnectionTarget::query()
            ->with(['app.node', 'workspace.app.node'])
            ->where('env_prefix', $prefix)
            ->when($targetType === 'app', fn ($query) => $query->where('app_id', $targetId))
            ->when($targetType === 'workspace', fn ($query) => $query->where('workspace_id', $targetId))
            ->first();

        if (! $target instanceof DatabaseConnectionTarget) {
            if ($key !== 'database_connection.target_missing') {
                return null;
            }

            return $this->restoreMissingDatabaseConnectionTarget($key, $detail, $targetType, $targetId, $prefix);
        }

        $nodeName = null;

        if ($target->app instanceof App) {
            $nodeName = $target->app->node?->name;
        } elseif ($target->workspace instanceof Workspace) {
            $nodeName = $target->workspace->app?->node?->name;
        }

        try {
            $this->databaseConnectionRestorer->restore($target);
        } catch (\Throwable $e) {
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
            'app_id' => $targetType === 'app' ? $targetId : null,
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
        if ($targetType === 'app') {
            $app = App::query()->with('node')->find($targetId);

            return $app instanceof App ? $app->node?->name : null;
        }

        $workspace = Workspace::query()
            ->with(['app.node', 'app.instances', 'appInstance'])
            ->find($targetId);

        return $workspace instanceof Workspace
            ? $this->workspacePlacement->nodeForWorkspace($workspace)?->name
            : null;
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
        } catch (\Throwable $e) {
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
        if ($key === 'process.runtime_unit_unrenderable') {
            return $this->restoreUnrenderableProcessIssue($node, $key, $detail);
        }

        if (in_array($key, ['process.event_notifier_missing', 'process.event_notifier_mismatch'], true)) {
            return $this->restoreProcessEventNotifierIssue($node, $key);
        }

        if (! in_array($key, ['process.runtime_unit_missing', 'process.runtime_unit_mismatch'], true)) {
            return null;
        }

        $process = $this->processFromIssueDetail($node, $detail);

        if (! $process instanceof Process) {
            return null;
        }

        $managedRuntimeAction = $this->restoreManagedFrankenPhpProcessRuntime($node, $key, $process);

        if ($managedRuntimeAction !== null) {
            return $managedRuntimeAction;
        }

        $app = $process->ownerApp();

        if (! $app instanceof App) {
            return $this->applyNodeOwnedProcessIssue($node, $key, $process);
        }

        try {
            $this->refreshManagedFrankenPhpProcessIntent($process);
            $warnings = app(EnsureAppProcessRuntimeUnits::class)->handle($app);
        } catch (\Throwable $e) {
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
                'summary' => "Process runtime restore for {$app->name} completed with warnings.",
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
            'summary' => "Restored process runtime units for {$app->name}.",
            'details' => [
                'app' => $app->name,
                'process' => $process->name,
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

        if ($hashLabel === AppRuntimeContainer::SpecHashLabel && $process->owner instanceof App) {
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
    private function restoreManagedFrankenPhpAppRuntime(Node $node, string $key, Process $process, App $app): array
    {
        $app->loadMissing('node');

        if (! $app->node instanceof Node || $app->node->id !== $node->id) {
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
                    'process' => $process->name,
                    'error' => 'Process app has no active parent node.',
                ],
            ];
        }

        try {
            app(EnsureFrankenPhpRuntimeProcess::class)->forApp($app);
            $this->ensureAppRuntimeTlsMaterial($app, $node);

            $container = app(AppRuntimeContainerRenderer::class)->render($app);
            $outcome = $this->appRuntimeContainerManagerForAgentPush()->apply($node, $container);
        } catch (\Throwable $e) {
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
            'summary' => "Restored managed FrankenPHP runtime container for {$app->name}.",
            'details' => [
                'app' => $app->name,
                'process' => $process->name,
                'container' => $container->name(),
                'outcome' => $outcome->value,
            ],
        ];
    }

    private function appRuntimeContainerManagerForAgentPush(): AppRuntimeContainerManager
    {
        return new AppRuntimeContainerManager(
            app(RemoteShell::class),
            app(DockerCommandBuilder::class),
            app(OrbitCaService::class),
            app(AppDevelopmentInnerTlsPolicy::class),
            localExecutor: app(RemoteLocalExecutor::class),
        );
    }

    private function workspaceRuntimeContainerManagerForAgentPush(): WorkspaceRuntimeContainerManager
    {
        return new WorkspaceRuntimeContainerManager(
            app(RemoteShell::class),
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

        if (! $app instanceof App || ! $workspaceNode instanceof Node || $workspaceNode->id !== $node->id) {
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
                    'error' => 'Process workspace has no active parent app node.',
                ],
            ];
        }

        try {
            app(EnsureFrankenPhpRuntimeProcess::class)->forWorkspace($workspace);
            $this->ensureWorkspaceRuntimeTlsMaterial($workspace, $workspaceNode);

            $container = app(WorkspaceRuntimeContainerRenderer::class)->render($workspace);
            $outcome = $this->workspaceRuntimeContainerManagerForAgentPush()->apply($workspaceNode, $container);
        } catch (\Throwable $e) {
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

        $script = implode(PHP_EOL, [
            'set -eu',
            'tmp="$(mktemp)"',
            'base64 -d > "$tmp" <<\'ORBIT_EVENT_NOTIFIER\'',
            base64_encode($renderer->content()),
            'ORBIT_EVENT_NOTIFIER',
            'sudo install -D -m 0755 "$tmp" '.escapeshellarg($renderer->installPath()),
            'rm -f "$tmp"',
            'sudo install -d -m 0755 '.escapeshellarg(dirname($renderer->gatewayEndpointPath())),
            'printf \'%s\n\' '
                .escapeshellarg($gatewayEndpoint)
                .' | sudo tee '
                .escapeshellarg($renderer->gatewayEndpointPath())
                .' >/dev/null',
            'sudo chmod 0644 '.escapeshellarg($renderer->gatewayEndpointPath()),
            '',
        ]);

        $result = app(RemoteShell::class)->run($node, $script, ['throw' => false]);

        if (! $result->successful()) {
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

    private function refreshManagedFrankenPhpProcessIntent(Process $process): void
    {
        $process->loadMissing('owner');

        $config = $process->runtime_config;
        $hashLabel = is_string($config['container_spec_hash_label'] ?? null)
            ? $config['container_spec_hash_label']
            : null;

        if ($hashLabel === null || trim($hashLabel) === '') {
            return;
        }

        $ensureFrankenPhpRuntimeProcess = app(EnsureFrankenPhpRuntimeProcess::class);

        if ($hashLabel === AppRuntimeContainer::SpecHashLabel && $process->owner instanceof App) {
            $ensureFrankenPhpRuntimeProcess->forApp($process->owner);

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
        } catch (\Throwable $e) {
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
        } catch (\Throwable $e) {
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

        $processes = Process::query()
            ->with('owner')
            ->where('node_id', $node->id)
            ->where('name', $processName)
            ->get();
        $runtimeUnit = is_string($detail['runtime_unit'] ?? null) ? $detail['runtime_unit'] : null;

        return $this->processForRuntimeUnit($node, $processes, $runtimeUnit) ?? $processes->first();
    }

    /**
     * @param  Collection<int, Process>  $processes
     */
    private function processForRuntimeUnit(Node $node, Collection $processes, ?string $runtimeUnit): ?Process
    {
        if ($runtimeUnit === null) {
            return null;
        }

        return $processes->first(function (Process $process) use ($node, $runtimeUnit): bool {
            if ($this->runtimeUnitNameForProcess($node, $process) === $runtimeUnit) {
                return true;
            }

            $app = $process->ownerApp();

            return $app instanceof App && $app->name !== '' && str_starts_with($runtimeUnit, "orbit_{$app->name}_");
        });
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
        } catch (\Throwable) {
            return null;
        }
    }

    private function processOwnerContext(Node $node, Process $process): ?ProcessOwnerContext
    {
        $process->loadMissing('owner');

        if ($process->owner instanceof Node) {
            return new ProcessOwnerContext(
                node: $node,
                app: null,
                workspace: null,
                owner: $process->owner,
            );
        }

        if ($process->owner instanceof App) {
            return new ProcessOwnerContext(
                node: $node,
                app: $process->owner,
                workspace: null,
                owner: $process->owner,
            );
        }

        if ($process->owner instanceof Workspace) {
            $process->owner->loadMissing('app');

            if (! $process->owner->app instanceof App) {
                return null;
            }

            return new ProcessOwnerContext(
                node: $node,
                app: $process->owner->app,
                workspace: $process->owner,
                owner: $process->owner,
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

        if ($key === 'app.runtime_container_probe_failed') {
            return $this->handleAppRuntimeContainerProbeFailed($node);
        }

        $appName = is_string($detail['app'] ?? null) ? $detail['app'] : null;

        if ($appName === null) {
            return null;
        }

        if ($key === 'app.runtime_container_extra') {
            return $this->handleAppExtraAction($node, $appName);
        }

        if ($key === 'app.runtime_config_extra') {
            return $this->handleAppConfigExtraAction($node, $appName);
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
     * @return array<string, mixed>
     */
    private function handleAppExtraAction(Node $node, string $appSlug): array
    {
        try {
            return $this->appsFixer->removeExtra($node, $appSlug);
        } catch (\Throwable $e) {
            return [
                'family' => 'app',
                'node' => $node->name,
                'code' => 'app.runtime_container_extra',
                'key' => 'app.runtime_container_extra',
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to remove extra app runtime container for {$appSlug}.",
                'details' => [
                    'app' => $appSlug,
                    'error' => $e->getMessage(),
                ],
            ];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    /**
     * Re-probe the managed runtime config directory after a sudo/SSH/probe
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
        } catch (\Throwable $e) {
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
     * Re-probe the node-wide app runtime container scan after a docker /
     * SSH / permission failure. If the probe now succeeds the drift clears;
     * if it still fails the doctor run emits the probe-failed drift again so
     * the operator can investigate the underlying daemon/transport issue.
     *
     * @return array<string, mixed>
     */
    private function handleAppRuntimeContainerProbeFailed(Node $node): array
    {
        try {
            $probe = $this->appsProbe->introspectNode($node);
        } catch (\Throwable $e) {
            return [
                'family' => 'app',
                'node' => $node->name,
                'code' => 'app.runtime_container_probe_failed',
                'key' => 'app.runtime_container_probe_failed',
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to re-probe app runtime container scan on {$node->name}.",
                'details' => [
                    'error' => $e->getMessage(),
                ],
            ];
        }

        if ($probe->status === NodeRuntimeContainersProbeStatus::Error) {
            return [
                'family' => 'app',
                'node' => $node->name,
                'code' => 'app.runtime_container_probe_failed',
                'key' => 'app.runtime_container_probe_failed',
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "App runtime container scan still failing on {$node->name}.",
                'details' => [
                    'error' => $probe->error,
                ],
            ];
        }

        return [
            'family' => 'app',
            'node' => $node->name,
            'code' => 'app.runtime_container_probe_failed',
            'key' => 'app.runtime_container_probe_failed',
            'mode' => 'restore',
            'status' => 'completed',
            'summary' => "Re-probed app runtime container scan on {$node->name}.",
            'details' => [
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
        } catch (\Throwable $e) {
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

        if (in_array($key, ['proxy.caddy_container_missing', 'proxy.caddy_container_down'], true)) {
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
        } catch (\Throwable $e) {
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
        } catch (\Throwable $e) {
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
        } catch (\Throwable $e) {
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
        } catch (\Throwable $e) {
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
        } catch (\Throwable $e) {
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
            } catch (\Throwable $e) {
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
            'proxy.caddy_container_missing',
            'proxy.caddy_container_down',
            'proxy.global_config_missing',
            'proxy.global_config_mismatch',
            WebSocketProxyDoctorProbe::RouterRouteKey,
            WebSocketProxyDoctorProbe::PublicRouteKey,
            S3ProxyDoctorProbe::RouterRouteKey,
            S3ProxyDoctorProbe::RouterBackendKey,
            S3ProxyDoctorProbe::PublicRouteKey,
            'workspace.runtime_container_missing',
            'workspace.runtime_container_stopped',
            'workspace.runtime_container_mismatch',
            'workspace.security.system_user',
            'workspace.security.fs_permissions',
            'app.runtime_container_missing',
            'app.runtime_container_mismatch',
            'app.runtime_container_extra',
            'app.runtime_config_missing',
            'app.runtime_config_mismatch',
            'app.runtime_config_extra',
            'app.runtime_config_probe_failed',
            'app.runtime_container_probe_failed',
            'app.security.system_user',
            'app.security.fs_permissions',
            'app.security.runtime_container_isolation',
            'firewall_rule.rule_missing',
            'firewall_rule.rule_mismatch',
            'process.runtime_unit_missing',
            'process.runtime_unit_mismatch',
            'process.runtime_unit_unrenderable',
            'process.event_notifier_missing',
            'process.event_notifier_mismatch',
            'tool.capability_missing',
            'tool.agent_route_missing',
            'tool.container_missing',
            'tool.container_not_running',
            'tool.container_spec_mismatch',
            'tool.version_mismatch',
            'tool.config_missing',
            'tool.config_mismatch',
            'tool.credentials_missing',
            'tool.credentials_mismatch',
            'dns.container_missing',
            'dns.port_not_listening',
            'dns.config_drift',
            'dns.client_dns_drift',
            'schedule.scheduler_missing',
            'schedule.scheduler_stopped',
            'schedule.scheduler_image_mismatch',
            'schedule.scheduler_replicas_mismatch',
            'schedule.lock_stuck',
            'node.role_convergence_failed',
            'node.role_baseline_mismatch',
            'node.security.sshd_config',
            'node.security.sshd_listen',
            'node.security.public_ssh_deny',
            'node.security.sysctl',
            'node.updates_config_missing',
            'node.updates_config_mismatch',
            'node.updates_dry_run_failed',
            'node.updates_last_run_failed',
            'node.updates_unverifiable',
            'database_connection.env_missing',
            'database_connection.env_mismatch',
            'database_connection.target_missing',
        ];
        $isNodeHostKey = str_starts_with($key, 'node.security.host_key.');

        return [
            ...$issue,
            'code' => $code,
            'restorable' =>
                $isNodeHostKey
                    || in_array($code, $restorableKeys, true)
                    || $family === 'proxy' && $kind === DriftKind::Extra->value,
            'adoptable' =>
                $isNodeHostKey
                    || ($family === 'proxy' || $family === 'firewall_rule') && $kind === DriftKind::Extra->value
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
        } catch (\Throwable $e) {
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
        } catch (\Throwable $e) {
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

        if (in_array(
            $entry->key,
            [
                'workspace.runtime_container_missing',
                'workspace.runtime_container_stopped',
                'workspace.runtime_container_mismatch',
            ],
            true,
        )) {
            return $this->restoreWorkspaceRuntimeContainer($workspace, $entry);
        }

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
     * @return array<string, mixed>
     */
    private function restoreWorkspaceRuntimeContainer(Workspace $workspace, DriftEntry $entry): array
    {
        $workspace->loadMissing(['app.node', 'appInstance']);
        $app = $workspace->app;
        $node = $this->workspacePlacement->nodeForWorkspace($workspace);

        if (! $app instanceof App || ! $node instanceof Node) {
            return [
                'family' => $entry->family,
                'node' => $node?->name,
                'code' => $entry->key,
                'key' => $entry->key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to restore {$entry->key}.",
                'details' => [
                    'workspace' => $workspace->name,
                    'error' => 'Workspace has no active parent app node.',
                ],
            ];
        }

        try {
            app(EnsureFrankenPhpRuntimeProcess::class)->forWorkspace($workspace);
            $this->ensureWorkspaceRuntimeTlsMaterial($workspace, $node);

            $container = app(WorkspaceRuntimeContainerRenderer::class)->render($workspace);
            $outcome = $this->workspaceRuntimeContainerManagerForAgentPush()->apply($node, $container);
        } catch (\Throwable $e) {
            return [
                'family' => $entry->family,
                'node' => $node->name,
                'code' => $entry->key,
                'key' => $entry->key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to restore {$entry->key}.",
                'details' => [
                    'app' => $app->name,
                    'workspace' => $workspace->name,
                    'error' => $e->getMessage(),
                ],
            ];
        }

        return [
            'family' => $entry->family,
            'node' => $node->name,
            'code' => $entry->key,
            'key' => $entry->key,
            'mode' => 'restore',
            'status' => 'completed',
            'summary' => "Restored FrankenPHP runtime container for workspace {$workspace->name}.",
            'details' => [
                'app' => $app->name,
                'workspace' => $workspace->name,
                'container' => $container->name(),
                'outcome' => $outcome->value,
            ],
        ];
    }

    private function ensureAppRuntimeTlsMaterial(App $app, Node $node): void
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
    private function handleAppAction(App $app, DriftEntry $entry): ?array
    {
        try {
            return $this->appsFixer->fix($app, $entry);
        } catch (\Throwable $e) {
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
        } catch (\Throwable $e) {
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
        } catch (\Throwable $e) {
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
        } catch (\Throwable $e) {
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
        $schedule->loadMissing(['app.node', 'node']);

        if ($schedule->scope === 'app') {
            return $schedule->app?->node?->name;
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
        $query = Schedule::query()
            ->with(['app.node', 'node'])
            ->where('enabled', true)
            ->where('status', 'expected');

        if ($this->nodeRoleAssignments->nodeIsGateway($node)) {
            return $query->get();
        }

        return $query
            ->where(function ($query) use ($node): void {
                $query
                    ->where('node_id', $node->id)
                    ->orWhereHas('app', fn ($appQuery) => $appQuery->where('node_id', $node->id));
            })
            ->get();
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
