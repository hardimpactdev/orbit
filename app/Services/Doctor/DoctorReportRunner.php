<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DriftEntry;
use App\Enums\DriftKind;
use App\Models\App;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\Process;
use App\Models\ProxyRoute;
use App\Models\Schedule;
use App\Models\Workspace;
use App\Services\Apps\AppsProbe;
use App\Services\Firewall\FirewallRuleFixer;
use App\Services\Firewall\FirewallRuleProbe;
use App\Services\Nodes\NodesProbe;
use App\Services\Processes\ProcessesProbe;
use App\Services\Proxy\ProxyRouteAdopter;
use App\Services\Proxy\ProxyRouteFixer;
use App\Services\Proxy\ProxyRouteProbe;
use App\Services\Schedules\SchedulesFixer;
use App\Services\Schedules\SchedulesProbe;
use App\Services\Tools\ToolsFixer;
use App\Services\Tools\ToolsProbe;
use App\Services\Workspaces\WorkspacesProbe;

final readonly class DoctorReportRunner
{
    private const array SUPPORTED_FAMILIES = ['node', 'app', 'workspace', 'process', 'proxy', 'firewall_rule', 'tool', 'schedule'];

    public function __construct(
        private NodesProbe $nodesProbe,
        private AppsProbe $appsProbe,
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
    ) {}

    /**
     * @return list<string>
     */
    public function supportedFamilies(): array
    {
        return self::SUPPORTED_FAMILIES;
    }

    /**
     * @param  list<string>  $families
     * @return array<string, mixed>
     */
    public function run(Node $node, string $mode = 'verify', array $families = []): array
    {
        $selectedFamilies = $families === [] ? self::SUPPORTED_FAMILIES : $families;
        $issues = [];
        $actions = [];

        foreach ($selectedFamilies as $family) {
            if ($family !== 'node') {
                continue;
            }

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
            foreach (App::query()->with('node')->get() as $app) {
                $snapshot = $this->appsProbe->introspect($app);

                foreach ($this->appsProbe->diff($app, $snapshot) as $entry) {
                    $issues[] = $this->appIssuePayload($entry, $app);
                }
            }
        }

        if (in_array('workspace', $selectedFamilies, true)) {
            foreach (Workspace::query()->with('app.node')->get() as $workspace) {
                $snapshot = $this->workspacesProbe->introspect($workspace);

                foreach ($this->workspacesProbe->diff($workspace, $snapshot) as $entry) {
                    $issues[] = $this->workspaceIssuePayload($entry, $workspace);
                }
            }
        }

        if (in_array('process', $selectedFamilies, true)) {
            foreach (Process::query()->with(['app.node', 'app.workspaces'])->get() as $process) {
                $snapshot = $this->processesProbe->introspect($process);

                foreach ($this->processesProbe->diff($process, $snapshot) as $entry) {
                    $issues[] = $this->processIssuePayload($entry, $process);
                }
            }
        }

        if (in_array('proxy', $selectedFamilies, true)) {
            if ($mode === 'adopt') {
                $proxyNodes = Node::query()
                    ->where('status', 'active')
                    ->whereIn('role', ['gateway', 'app'])
                    ->get();

                foreach ($proxyNodes as $proxyNode) {
                    $snapshot = $this->proxyRouteProbe->snapshotForAdopt($proxyNode);

                    foreach ($this->proxyRouteAdopter->adopt($proxyNode, $snapshot) as $result) {
                        $actions[] = [
                            'family' => $result->family,
                            'node' => $proxyNode->name,
                            'code' => $result->key,
                            'key' => $result->key,
                            'mode' => 'adopt',
                            'status' => $result->action->value,
                            'summary' => $result->summary,
                            'details' => $result->detail,
                        ];
                    }
                }
            } else {
                foreach (ProxyRoute::query()->with(['node', 'app', 'workspace'])->get() as $route) {
                    $snapshot = $this->proxyRouteProbe->introspect($route);

                    foreach ($this->proxyRouteProbe->diff($route, $snapshot) as $entry) {
                        $action = $this->handleProxyAction($mode, $route, $entry);

                        if ($action !== null && ($action['status'] ?? null) === 'completed') {
                            $actions[] = $action;

                            continue;
                        }

                        $issue = $this->proxyIssuePayload($entry, $route);
                        $issues[] = $issue;

                        if ($action !== null) {
                            $actions[] = $action;
                        }
                    }
                }

                $scannedNodeIds = [];

                foreach (ProxyRoute::query()->distinct()->pluck('node_id') as $nodeId) {
                    $proxyNode = Node::query()->find($nodeId);

                    if (! $proxyNode instanceof Node || $proxyNode->status !== 'active' || ! in_array($proxyNode->role, ['gateway', 'app'], true)) {
                        continue;
                    }

                    if (in_array($proxyNode->id, $scannedNodeIds, true)) {
                        continue;
                    }

                    $scannedNodeIds[] = $proxyNode->id;

                    $snapshot = $this->proxyRouteProbe->introspectNode($proxyNode);
                    $dbDomains = ProxyRoute::query()->where('node_id', $proxyNode->id)->pluck('domain')->all();

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

                        $action = $this->handleProxyExtraAction($mode, $proxyNode, $entry);

                        if ($action !== null && ($action['status'] ?? null) === 'completed') {
                            $actions[] = $action;

                            continue;
                        }

                        $issues[] = [
                            'family' => 'proxy',
                            'node' => $proxyNode->name,
                            'key' => $domain,
                            'kind' => 'extra',
                            'summary' => $entry->summary,
                            'detail' => [],
                        ];

                        if ($action !== null) {
                            $actions[] = $action;
                        }
                    }
                }
            }
        }

        if (in_array('firewall_rule', $selectedFamilies, true)) {
            if ($mode === 'adopt') {
                $firewallNodes = Node::query()
                    ->where('status', 'active')
                    ->where('platform', 'ubuntu')
                    ->whereIn('role', ['gateway', 'app'])
                    ->get();

                foreach ($firewallNodes as $firewallNode) {
                    $snapshot = $this->firewallRuleProbe->introspectNode($firewallNode);

                    foreach ($this->firewallRuleProbe->adopt($firewallNode, $snapshot) as $result) {
                        $actions[] = [
                            'family' => $result->family,
                            'node' => $firewallNode->name,
                            'code' => $result->key,
                            'key' => $result->key,
                            'mode' => 'adopt',
                            'status' => $result->action->value,
                            'summary' => $result->summary,
                            'details' => $result->detail,
                        ];
                    }
                }
            } else {
                foreach (FirewallRule::query()->with('node')->get() as $rule) {
                    $snapshot = $this->firewallRuleProbe->introspect($rule);

                    foreach ($this->firewallRuleProbe->diff($rule, $snapshot) as $entry) {
                        $action = $this->handleFirewallAction($mode, $rule, $entry);

                        if ($action !== null && ($action['status'] ?? null) === 'completed') {
                            $actions[] = $action;

                            continue;
                        }

                        $issue = $this->firewallIssuePayload($entry, $rule);
                        $issues[] = $issue;

                        if ($action !== null) {
                            $actions[] = $action;
                        }
                    }
                }
            }
        }

        if (in_array('tool', $selectedFamilies, true)) {
            foreach (NodeTool::query()->with('node')->get() as $tool) {
                $snapshot = $this->toolsProbe->introspect($tool);

                foreach ($this->toolsProbe->diff($tool, $snapshot) as $entry) {
                    $action = $this->handleToolAction($mode, $tool, $entry);

                    if ($action !== null && ($action['status'] ?? null) === 'completed') {
                        $actions[] = $action;

                        continue;
                    }

                    $issue = $this->toolIssuePayload($entry, $tool);
                    $issues[] = $issue;

                    if ($action !== null) {
                        $actions[] = $action;
                    }
                }
            }
        }

        if (in_array('schedule', $selectedFamilies, true)) {
            foreach (Schedule::query()->with(['app.node', 'node'])->get() as $schedule) {
                $snapshot = $this->schedulesProbe->introspect($schedule);

                foreach ($this->schedulesProbe->diff($schedule, $snapshot) as $entry) {
                    $action = $this->handleScheduleAction($mode, $schedule, $entry);

                    if ($action !== null && ($action['status'] ?? null) === 'completed') {
                        $actions[] = $action;

                        continue;
                    }

                    $issue = $this->scheduleIssuePayload($entry, $schedule);
                    $issues[] = $issue;

                    if ($action !== null) {
                        $actions[] = $action;
                    }
                }
            }
        }

        $actions = [
            ...$actions,
            ...$this->actionsForUnsupportedMode($mode, $issues, $actions),
        ];
        $summary = $this->summary($issues, $actions);

        return [
            'healthy' => $issues === [],
            'mode' => $mode,
            'scope' => [
                'families' => $selectedFamilies,
                'node' => $node->name,
                'self' => false,
                'app' => null,
                'workspace' => null,
            ],
            'summary' => $summary,
            'issues' => $issues,
            'actions' => $actions,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function appIssuePayload(DriftEntry $entry, App $app): array
    {
        $app->loadMissing('node');

        return [
            'family' => $entry->family,
            'node' => $app->node?->name,
            'key' => $entry->key,
            'kind' => $entry->kind->value,
            'summary' => $entry->summary,
            'detail' => $entry->detail,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function workspaceIssuePayload(DriftEntry $entry, Workspace $workspace): array
    {
        $workspace->loadMissing('app.node');

        return [
            'family' => $entry->family,
            'node' => $workspace->app?->node?->name,
            'key' => $entry->key,
            'kind' => $entry->kind->value,
            'summary' => $entry->summary,
            'detail' => $entry->detail,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function processIssuePayload(DriftEntry $entry, Process $process): array
    {
        $process->loadMissing('app.node');

        return [
            'family' => $entry->family,
            'node' => $process->app->node?->name,
            'key' => $entry->key,
            'kind' => $entry->kind->value,
            'summary' => $entry->summary,
            'detail' => $entry->detail,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function issuePayload(DriftEntry $entry, Node $node): array
    {
        return [
            'family' => 'node',
            'node' => $node->name,
            'key' => $entry->key,
            'kind' => $entry->kind->value,
            'summary' => $entry->summary,
            'detail' => $entry->detail,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function proxyIssuePayload(DriftEntry $entry, ProxyRoute $route): array
    {
        return [
            'family' => $entry->family,
            'node' => $route->node->name,
            'key' => $entry->key,
            'kind' => $entry->kind->value,
            'summary' => $entry->summary,
            'detail' => $entry->detail,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function handleProxyAction(string $mode, ProxyRoute $route, DriftEntry $entry): ?array
    {
        if ($mode !== 'fix') {
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
        if ($mode !== 'fix') {
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

        return [
            'family' => $entry->family,
            'node' => $rule->node->name,
            'key' => $entry->key,
            'kind' => $entry->kind->value,
            'summary' => $entry->summary,
            'detail' => $entry->detail,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toolIssuePayload(DriftEntry $entry, NodeTool $tool): array
    {
        $tool->loadMissing('node');

        return [
            'family' => $entry->family,
            'node' => $tool->node?->name,
            'key' => $entry->key,
            'kind' => $entry->kind->value,
            'summary' => $entry->summary,
            'detail' => $entry->detail,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduleIssuePayload(DriftEntry $entry, Schedule $schedule): array
    {
        return [
            'family' => $entry->family,
            'node' => $this->scheduleNodeName($schedule),
            'key' => $entry->key,
            'kind' => $entry->kind->value,
            'summary' => $entry->summary,
            'detail' => $entry->detail,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function handleFirewallAction(string $mode, FirewallRule $rule, DriftEntry $entry): ?array
    {
        if ($mode !== 'fix') {
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
        if ($mode !== 'fix') {
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
        if ($mode !== 'fix') {
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
                ->where('role', 'gateway')
                ->where('status', 'active')
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
    private function summary(array $issues, array $actions): array
    {
        return [
            'issues' => count($issues),
            'fixed' => count(array_filter($actions, fn (array $action): bool => ($action['mode'] ?? null) === 'fix' && ($action['status'] ?? null) === 'completed')),
            'adopted' => count(array_filter($actions, fn (array $action): bool => ($action['mode'] ?? null) === 'adopt' && in_array($action['status'] ?? null, ['completed', 'created', 'updated'], true))),
            'skipped' => count(array_filter($actions, fn (array $action): bool => ($action['status'] ?? null) === 'skipped')),
            'conflicts' => count(array_filter($actions, fn (array $action): bool => ($action['status'] ?? null) === 'conflict')),
            'failed' => count(array_filter($actions, fn (array $action): bool => ($action['status'] ?? null) === 'failed')),
        ];
    }
}
