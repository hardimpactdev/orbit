<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DriftEntry;
use App\Enums\DriftKind;
use App\Enums\Nodes\NodeRoleName;
use App\Models\App;
use App\Models\DatabaseConnectionTarget;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\Process;
use App\Models\ProxyRoute;
use App\Models\Schedule;
use App\Models\Workspace;
use App\Services\Apps\AppsProbe;
use App\Services\DatabaseConnections\DatabaseConnectionAdopter;
use App\Services\DatabaseConnections\DatabaseConnectionProbe;
use App\Services\DatabaseConnections\DatabaseConnectionRestorer;
use App\Services\Firewall\FirewallRuleFixer;
use App\Services\Firewall\FirewallRuleProbe;
use App\Services\Nodes\NodesProbe;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Processes\ProcessesProbe;
use App\Services\Proxy\ProxyRouteAdopter;
use App\Services\Proxy\ProxyRouteFixer;
use App\Services\Proxy\ProxyRouteProbe;
use App\Services\Schedules\SchedulesFixer;
use App\Services\Schedules\SchedulesProbe;
use App\Services\Tools\ToolsFixer;
use App\Services\Tools\ToolsProbe;
use App\Services\Workspaces\WorkspacesFixer;
use App\Services\Workspaces\WorkspacesProbe;

final readonly class DoctorReportRunner
{
    private const array SUPPORTED_FAMILIES = ['node', 'app', 'workspace', 'process', 'proxy', 'firewall_rule', 'tool', 'schedule', 'database_connection'];

    private const array CONTROL_CATEGORIES = ['node'];

    private const array GATEWAY_CATEGORIES = ['node'];

    private const array APP_CATEGORIES = ['node', 'app', 'workspace', 'process', 'proxy', 'firewall_rule', 'tool', 'schedule', 'database_connection'];

    private const array DATABASE_CATEGORIES = ['node', 'tool'];

    public function __construct(
        private NodesProbe $nodesProbe,
        private AppsProbe $appsProbe,
        private DatabaseConnectionProbe $databaseConnectionProbe,
        private DatabaseConnectionRestorer $databaseConnectionRestorer,
        private DatabaseConnectionAdopter $databaseConnectionAdopter,
        private WorkspacesProbe $workspacesProbe,
        private ProcessesProbe $processesProbe,
        private ProxyRouteProbe $proxyRouteProbe,
        private FirewallRuleProbe $firewallRuleProbe,
        private FirewallRuleFixer $firewallRuleFixer,
        private ProxyRouteFixer $proxyRouteFixer,
        private ProxyRouteAdopter $proxyRouteAdopter,
        private ToolsProbe $toolsProbe,
        private ToolsFixer $toolsFixer,
        private SchedulesProbe $schedulesProbe,
        private SchedulesFixer $schedulesFixer,
        private WorkspacesFixer $workspacesFixer,
        private NodeRoleAssignments $nodeRoleAssignments,
    ) {}

    /**
     * @return list<string>
     */
    public function supportedFamilies(): array
    {
        return self::SUPPORTED_FAMILIES;
    }

    /**
     * @return list<string>
     */
    public function categoriesForRole(string $role): array
    {
        return match ($role) {
            'control' => self::CONTROL_CATEGORIES,
            'gateway' => self::GATEWAY_CATEGORIES,
            'app' => self::APP_CATEGORIES,
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    public function categoriesForNode(Node $node): array
    {
        if ($this->nodeRoleAssignments->nodeIsGateway($node)) {
            return self::GATEWAY_CATEGORIES;
        }

        if ($this->nodeRoleAssignments->nodeHasActiveAppHostRole($node)) {
            return self::APP_CATEGORIES;
        }

        if ($this->nodeRoleAssignments->nodeHasActiveRole($node, NodeRoleName::Database->value)) {
            return self::DATABASE_CATEGORIES;
        }

        return self::CONTROL_CATEGORIES;
    }

    /**
     * @param  list<string>  $families
     * @return array<string, mixed>
     */
    public function run(Node $node, string $mode = 'verify', array $families = []): array
    {
        $probe = $this->probe($node, $families);

        if ($mode === 'verify') {
            return $probe;
        }

        $actions = $mode === 'adopt'
            ? $this->adoptSelectedFamilies($node, $probe['scope']['families'] ?? [])
            : $this->apply($node, $mode, $probe['issues'] ?? []);

        if ($mode !== 'adopt') {
            $actions = [
                ...$actions,
                ...$this->actionsForUnsupportedMode($mode, $probe['issues'] ?? [], $actions),
            ];
        }

        return $this->finalize($probe, $mode, $actions);
    }

    /**
     * @param  list<string>  $families
     * @return array<string, mixed>
     */
    public function probe(Node $node, array $families = []): array
    {
        $roleCategories = $this->categoriesForNode($node);
        $selectedFamilies = $families === [] ? $roleCategories : array_values(array_intersect($families, $roleCategories));
        $issues = [];

        if (in_array('node', $selectedFamilies, true)) {
            $snapshot = $this->nodesProbe->introspect($node);
            $issues = [
                ...$issues,
                ...array_map(
                    fn (DriftEntry $entry): array => $this->issuePayload($entry, $node),
                    $this->nodesProbe->diff($node, $snapshot),
                ),
            ];
        }

        if (in_array('app', $selectedFamilies, true)) {
            foreach (App::query()->with('node')->where('node_id', $node->id)->get() as $app) {
                $snapshot = $this->appsProbe->introspect($app);

                foreach ($this->appsProbe->diff($app, $snapshot) as $entry) {
                    $issues[] = $this->appIssuePayload($entry, $app);
                }
            }
        }

        if (in_array('workspace', $selectedFamilies, true)) {
            foreach (Workspace::query()->with('app.node')->whereHas('app', fn ($query) => $query->where('node_id', $node->id))->get() as $workspace) {
                $snapshot = $this->workspacesProbe->introspect($workspace);

                foreach ($this->workspacesProbe->diff($workspace, $snapshot) as $entry) {
                    $issues[] = $this->workspaceIssuePayload($entry, $workspace);
                }
            }
        }

        if (in_array('process', $selectedFamilies, true)) {
            foreach (Process::query()->with(['app.node', 'app.workspaces'])->whereHas('app', fn ($query) => $query->where('node_id', $node->id))->get() as $process) {
                $snapshot = $this->processesProbe->introspect($process);

                foreach ($this->processesProbe->diff($process, $snapshot) as $entry) {
                    $issues[] = $this->processIssuePayload($entry, $process);
                }
            }
        }

        if (in_array('proxy', $selectedFamilies, true)) {
            foreach (ProxyRoute::query()->with(['node', 'app', 'workspace'])->where('node_id', $node->id)->get() as $route) {
                $snapshot = $this->proxyRouteProbe->introspect($route);

                foreach ($this->proxyRouteProbe->diff($route, $snapshot) as $entry) {
                    $issues[] = $this->proxyIssuePayload($entry, $route);
                }
            }

            if ($node->status === 'active' && $this->canServeGatewayOrAppHost($node)) {
                $snapshot = $this->proxyRouteProbe->introspectNode($node);
                $dbDomains = ProxyRoute::query()->where('node_id', $node->id)->pluck('domain')->all();

                foreach ($snapshot->keys() as $domain) {
                    $domain = (string) $domain;

                    if (in_array($domain, $dbDomains, true)) {
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
            }
        }

        if (in_array('firewall_rule', $selectedFamilies, true)) {
            foreach (FirewallRule::query()->with('node')->where('node_id', $node->id)->get() as $rule) {
                $snapshot = $this->firewallRuleProbe->introspect($rule);

                foreach ($this->firewallRuleProbe->diff($rule, $snapshot) as $entry) {
                    $issues[] = $this->firewallIssuePayload($entry, $rule);
                }
            }
        }

        if (in_array('tool', $selectedFamilies, true)) {
            foreach (NodeTool::query()->with('node')->where('node_id', $node->id)->get() as $tool) {
                $snapshot = $this->toolsProbe->introspect($tool);

                foreach ($this->toolsProbe->diff($tool, $snapshot) as $entry) {
                    $issues[] = $this->toolIssuePayload($entry, $tool);
                }
            }
        }

        if (in_array('schedule', $selectedFamilies, true)) {
            $scheduleQuery = Schedule::query()
                ->with(['app.node', 'node'])
                ->where(function ($query) use ($node): void {
                    $query
                        ->where('node_id', $node->id)
                        ->orWhereHas('app', fn ($appQuery) => $appQuery->where('node_id', $node->id));
                });

            foreach ($scheduleQuery->get() as $schedule) {
                $snapshot = $this->schedulesProbe->introspect($schedule);

                foreach ($this->schedulesProbe->diff($schedule, $snapshot) as $entry) {
                    $issues[] = $this->scheduleIssuePayload($entry, $schedule);
                }
            }
        }

        if (in_array('database_connection', $selectedFamilies, true)) {
            foreach ($this->databaseConnectionProbe->probe($node) as $issue) {
                $issues[] = $this->annotateIssue([
                    ...$issue,
                    'node' => $node->name,
                ]);
            }
        }

        $summary = $this->summary('verify', $issues, []);

        return [
            'healthy' => $issues === [],
            'mode' => 'verify',
            'scope' => [
                'families' => $selectedFamilies,
                'node' => $node->name,
                'role' => $node->role,
                'self' => false,
                'app' => null,
                'workspace' => null,
            ],
            'summary' => $summary,
            'issues' => $issues,
            'actions' => [],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @return list<array<string, mixed>>
     */
    public function apply(Node $node, string $mode, array $issues): array
    {
        $actions = [];

        foreach ($issues as $issue) {
            if (! $this->issueSupportsMode($issue, $mode)) {
                continue;
            }

            $action = $this->applyIssue($node, $mode, $issue);

            if ($action !== null) {
                $actions[] = $action;
            }
        }

        return array_map(fn (array $action): array => $this->normalizeActionMode($action, $mode), $actions);
    }

    /**
     * @param  array<string, mixed>  $probe
     * @param  list<array<string, mixed>>  $actions
     * @return array<string, mixed>
     */
    public function finalize(array $probe, string $mode, array $actions): array
    {
        $issues = $probe['issues'] ?? [];
        $issues = is_array($issues) ? array_values(array_filter($issues, is_array(...))) : [];
        $remainingIssues = $this->remainingIssues($issues, $actions);
        $summary = $this->summary($mode, $remainingIssues, $actions);

        return [
            ...$probe,
            'healthy' => $summary['issues'] === 0 && $summary['failed'] === 0 && $summary['conflicts'] === 0 && $summary['skipped'] === 0,
            'mode' => $mode,
            'summary' => $summary,
            'issues' => $remainingIssues,
            'actions' => $actions,
        ];
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
            'detail' => $entry->detail,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function workspaceIssuePayload(DriftEntry $entry, Workspace $workspace): array
    {
        $workspace->loadMissing('app.node');

        return $this->annotateIssue([
            'family' => $entry->family,
            'node' => $workspace->app?->node?->name,
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
        $process->loadMissing('app.node');

        return $this->annotateIssue([
            'family' => $entry->family,
            'node' => $process->app->node?->name,
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
        return $this->annotateIssue([
            'family' => 'node',
            'node' => $node->name,
            'key' => $entry->key,
            'kind' => $entry->kind->value,
            'summary' => $entry->summary,
            'detail' => $entry->detail,
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
    private function adoptSelectedFamilies(Node $node, array $families): array
    {
        $actions = [];

        if (in_array('proxy', $families, true) && $node->status === 'active' && $this->canServeGatewayOrAppHost($node)) {
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

        if (in_array('firewall_rule', $families, true) && $node->status === 'active' && $node->platform === 'ubuntu' && $this->canServeGatewayOrAppHost($node)) {
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
            foreach ($this->databaseConnectionAdopter->adopt($node) as $result) {
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

    /**
     * @param  array<string, mixed>  $issue
     * @return array<string, mixed>|null
     */
    private function applyIssue(Node $node, string $mode, array $issue): ?array
    {
        $family = $issue['family'] ?? null;
        $key = is_string($issue['key'] ?? null) ? $issue['key'] : null;
        $detail = is_array($issue['detail'] ?? null) ? $issue['detail'] : [];

        if ($key === null) {
            return null;
        }

        return match ($family) {
            'node' => $this->applyNodeIssue($node, $key, $detail, $issue),
            'database_connection' => $this->applyDatabaseConnectionIssue($key, $detail),
            'workspace' => $this->applyWorkspaceIssue($node, $key, $detail),
            'proxy' => $this->applyProxyIssue($node, $mode, $key, $detail, $issue),
            'firewall_rule' => $this->applyFirewallIssue($key, $detail),
            'tool' => $this->applyToolIssue($key, $detail),
            'schedule' => $this->applyScheduleIssue($key, $detail),
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
        $targetId = is_int($detail['target_id'] ?? null) ? $detail['target_id'] : (is_numeric($detail['target_id'] ?? null) ? (int) $detail['target_id'] : null);
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
            return null;
        }

        $nodeName = $target->app?->node?->name ?? $target->workspace?->app?->node?->name;

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
     * @param  array<string, mixed>  $issue
     * @return array<string, mixed>
     */
    private function applyNodeIssue(Node $node, string $key, array $detail, array $issue): array
    {
        $targetNode = $this->nodeFromIssue($issue) ?? $node;
        $entry = $this->driftEntryFromStoredParts('node', $key, $detail, $issue);

        try {
            $this->nodesProbe->reconcile($targetNode, $entry);
        } catch (\Throwable $e) {
            return [
                'family' => 'node',
                'node' => $targetNode->name,
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
            'family' => 'node',
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
     * @return array<string, mixed>|null
     */
    private function applyWorkspaceIssue(Node $node, string $key, array $detail): ?array
    {
        $workspaceName = is_string($detail['workspace'] ?? null) ? $detail['workspace'] : null;

        if ($workspaceName === null) {
            return null;
        }

        $appName = is_string($detail['app'] ?? null) ? $detail['app'] : null;
        $workspace = Workspace::query()
            ->with('app.node')
            ->where('name', $workspaceName)
            ->whereHas('app', function ($query) use ($node, $appName): void {
                $query->where('node_id', $node->id);

                if ($appName !== null) {
                    $query->where('name', $appName);
                }
            })
            ->first();

        if (! $workspace instanceof Workspace) {
            return null;
        }

        return $this->handleWorkspaceAction($workspace, $this->driftEntryFromStoredParts('workspace', $key, $detail));
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
                summary: (string) ($issue['summary'] ?? "Proxy route '{$key}' exists on node but not in gateway registry."),
            ));
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
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>|null
     */
    private function applyFirewallIssue(string $key, array $detail): ?array
    {
        $ruleName = is_string($detail['rule'] ?? null) ? $detail['rule'] : null;

        if ($ruleName === null) {
            return null;
        }

        $rule = FirewallRule::query()->where('name', $ruleName)->first();

        return $rule instanceof FirewallRule
            ? $this->handleFirewallAction('restore', $rule, $this->driftEntryFromStoredParts('firewall_rule', $key, $detail))
            : null;
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>|null
     */
    private function applyToolIssue(string $key, array $detail): ?array
    {
        $toolName = is_string($detail['tool'] ?? null) ? $detail['tool'] : null;

        if ($toolName === null) {
            return null;
        }

        $tool = NodeTool::query()->where('name', $toolName)->first();

        return $tool instanceof NodeTool
            ? $this->handleToolAction('restore', $tool, $this->driftEntryFromStoredParts('tool', $key, $detail))
            : null;
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>|null
     */
    private function applyScheduleIssue(string $key, array $detail): ?array
    {
        $scheduleKey = is_string($detail['schedule_key'] ?? null) ? $detail['schedule_key'] : null;

        if ($scheduleKey === null) {
            return null;
        }

        $schedule = Schedule::query()->where('schedule_key', $scheduleKey)->first();

        return $schedule instanceof Schedule
            ? $this->handleScheduleAction('restore', $schedule, $this->driftEntryFromStoredParts('schedule', $key, $detail))
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
    private function driftEntryFromStoredParts(string $family, string $key, array $detail, array $issue = []): DriftEntry
    {
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
        $kind = is_string($issue['kind'] ?? null) ? $issue['kind'] : '';
        $restorableKeys = [
            'proxy.route_missing',
            'proxy.route_mismatch',
            'proxy.tls_missing',
            'proxy.tls_mismatch',
            'workspace.fpm_config_missing',
            'workspace.fpm_config_mismatch',
            'firewall_rule.rule_missing',
            'firewall_rule.rule_mismatch',
            'tool.capability_missing',
            'tool.lifecycle_state_mismatch',
            'tool.version_mismatch',
            'tool.config_missing',
            'tool.config_mismatch',
            'tool.credentials_missing',
            'tool.credentials_mismatch',
            'schedule.scheduler_missing',
            'schedule.scheduler_stopped',
            'schedule.run_history_hook_missing',
            'schedule.run_history_hook_mismatch',
            'schedule.lock_stuck',
            'node.role_convergence_failed',
            'node.role_baseline_mismatch',
            'database_connection.env_missing',
            'database_connection.env_mismatch',
        ];

        return [
            ...$issue,
            'restorable' => in_array($key, $restorableKeys, true) || ($family === 'proxy' && $kind === DriftKind::Extra->value),
            'adoptable' => (($family === 'proxy' || $family === 'firewall_rule') && $kind === DriftKind::Extra->value)
                || ($family === 'database_connection' && in_array($key, [
                    'database_connection.env_extra',
                    'database_connection.target_extra',
                    'database_connection.env_mismatch',
                ], true)),
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
        $resolvedKeys = array_filter(array_map(
            fn (array $action): ?string => in_array($action['status'] ?? null, ['completed', 'created', 'updated'], true) && is_string($action['key'] ?? null)
                ? $action['key']
                : null,
            $actions,
        ));
        $resolvedDatabaseTargets = array_values(array_filter(array_map(
            fn (array $action): ?string => $this->databaseConnectionResolutionKey($action),
            $actions,
        )));

        return array_values(array_filter(
            $issues,
            fn (array $issue): bool => ! in_array($issue['key'] ?? null, $resolvedKeys, true)
                && ! $this->databaseConnectionIssueResolved($issue, $resolvedDatabaseTargets),
        ));
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
        if (($action['family'] ?? null) !== 'database_connection' || ! in_array($action['status'] ?? null, ['completed', 'created', 'updated'], true)) {
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
     * @return array<string, mixed>|null
     */
    private function handleWorkspaceAction(Workspace $workspace, DriftEntry $entry): ?array
    {
        try {
            return $this->workspacesFixer->fix($workspace, $entry);
        } catch (\Throwable $e) {
            $workspace->loadMissing('app.node');

            return [
                'family' => $entry->family,
                'node' => $workspace->app?->node?->name,
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
            $node = Node::query()
                ->where('status', 'active')
                ->where(function ($query): void {
                    $query
                        ->where('role', 'gateway')
                        ->orWhereIn('id', app(NodeRoleAssignments::class)->activeNodeIdsForRole('gateway'));
                })
                ->first();

            return $node instanceof Node ? $node->name : null;
        }

        return null;
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

        $actionKeys = array_filter(array_map(
            fn (array $action): ?string => is_string($action['key'] ?? null) ? $action['key'] : null,
            $existingActions,
        ));

        return array_values(array_map(
            fn (array $issue): array => $this->unsupportedAction($mode, $issue),
            array_filter(
                $issues,
                fn (array $issue): bool => ! in_array($issue['key'] ?? null, $actionKeys, true),
            ),
        ));
    }

    /**
     * @param  array<string, mixed>  $issue
     * @return array<string, mixed>
     */
    private function unsupportedAction(string $mode, array $issue): array
    {
        return [
            'family' => $issue['family'] ?? null,
            'node' => $issue['node'] ?? null,
            'code' => $issue['key'] ?? null,
            'key' => $issue['key'] ?? null,
            'mode' => $mode,
            'status' => 'skipped',
            'summary' => "No {$mode} action is registered for ".(string) ($issue['key'] ?? 'this issue').'.',
            'details' => [
                'reason' => 'mode_not_supported',
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @param  list<array<string, mixed>>  $actions
     * @return array{issues: int, fixed: int, adopted: int, skipped: int, conflicts: int, failed: int}
     */
    private function summary(string $mode, array $issues, array $actions): array
    {
        return [
            'issues' => count($issues),
            'fixed' => count(array_filter($actions, fn (array $action): bool => in_array($action['mode'] ?? null, ['fix', 'restore'], true) && ($action['status'] ?? null) === 'completed')),
            'adopted' => count(array_filter($actions, fn (array $action): bool => ($action['mode'] ?? null) === 'adopt' && in_array($action['status'] ?? null, ['completed', 'created', 'updated'], true))),
            'skipped' => count(array_filter($actions, fn (array $action): bool => ($action['status'] ?? null) === 'skipped')),
            'conflicts' => count(array_filter($actions, fn (array $action): bool => ($action['status'] ?? null) === 'conflict')),
            'failed' => count(array_filter($actions, fn (array $action): bool => ($action['status'] ?? null) === 'failed')),
        ];
    }
}
