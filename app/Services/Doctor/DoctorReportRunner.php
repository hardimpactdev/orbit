<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DriftEntry;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Models\Schedule;
use App\Services\Firewall\FirewallRuleFixer;
use App\Services\Firewall\FirewallRuleProbe;
use App\Services\Nodes\NodesProbe;
use App\Services\Proxy\ProxyRouteFixer;
use App\Services\Proxy\ProxyRouteProbe;
use App\Services\Schedules\SchedulesProbe;
use App\Services\Tools\ToolsFixer;
use App\Services\Tools\ToolsProbe;

final readonly class DoctorReportRunner
{
    private const array SUPPORTED_FAMILIES = ['node', 'proxy', 'firewall_rule', 'tool', 'schedule'];

    public function __construct(
        private NodesProbe $nodesProbe,
        private ProxyRouteProbe $proxyRouteProbe,
        private FirewallRuleProbe $firewallRuleProbe,
        private FirewallRuleFixer $firewallRuleFixer,
        private ProxyRouteFixer $proxyRouteFixer,
        private ToolsProbe $toolsProbe,
        private ToolsFixer $toolsFixer,
        private SchedulesProbe $schedulesProbe,
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

        if (in_array('proxy', $selectedFamilies, true)) {
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
        }

        if (in_array('firewall_rule', $selectedFamilies, true)) {
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
                $issues = [
                    ...$issues,
                    ...array_map(
                        fn (DriftEntry $entry): array => $this->scheduleIssuePayload($entry, $schedule),
                        $this->schedulesProbe->diff($schedule, $snapshot),
                    ),
                ];
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
            'adopted' => count(array_filter($actions, fn (array $action): bool => ($action['mode'] ?? null) === 'adopt' && ($action['status'] ?? null) === 'completed')),
            'skipped' => count(array_filter($actions, fn (array $action): bool => ($action['status'] ?? null) === 'skipped')),
            'conflicts' => count(array_filter($actions, fn (array $action): bool => ($action['status'] ?? null) === 'conflict')),
            'failed' => count(array_filter($actions, fn (array $action): bool => ($action['status'] ?? null) === 'failed')),
        ];
    }
}
