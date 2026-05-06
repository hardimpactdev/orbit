<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DriftEntry;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Services\Firewall\FirewallRuleProbe;
use App\Services\Nodes\NodesProbe;
use App\Services\Proxy\ProxyRouteProbe;

final readonly class DoctorReportRunner
{
    private const array SUPPORTED_FAMILIES = ['node', 'proxy', 'firewall_rule'];

    public function __construct(
        private NodesProbe $nodesProbe,
        private ProxyRouteProbe $proxyRouteProbe,
        private FirewallRuleProbe $firewallRuleProbe,
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
                $issues = [
                    ...$issues,
                    ...array_map(
                        fn (DriftEntry $entry): array => $this->proxyIssuePayload($entry, $route),
                        $this->proxyRouteProbe->diff($route, $snapshot),
                    ),
                ];
            }
        }

        if (in_array('firewall_rule', $selectedFamilies, true)) {
            foreach (FirewallRule::query()->with('node')->get() as $rule) {
                $snapshot = $this->firewallRuleProbe->introspect($rule);
                $issues = [
                    ...$issues,
                    ...array_map(
                        fn (DriftEntry $entry): array => $this->firewallIssuePayload($entry, $rule),
                        $this->firewallRuleProbe->diff($rule, $snapshot),
                    ),
                ];
            }
        }

        $actions = $this->actionsForUnsupportedMode($mode, $issues);

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
            'summary' => [
                'issues' => count($issues),
                'fixed' => 0,
                'adopted' => 0,
                'skipped' => count($actions),
                'conflicts' => 0,
                'failed' => 0,
            ],
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
     * @param  list<array<string, mixed>>  $issues
     * @return list<array<string, mixed>>
     */
    private function actionsForUnsupportedMode(string $mode, array $issues): array
    {
        if ($mode === 'verify') {
            return [];
        }

        return array_map(
            fn (array $issue): array => [
                'family' => $issue['family'] ?? null,
                'node' => $issue['node'] ?? null,
                'code' => $issue['key'] ?? null,
                'key' => $issue['key'] ?? null,
                'mode' => $mode,
                'status' => 'skipped',
                'summary' => "No {$mode} action is registered for ".(string) ($issue['key'] ?? 'this issue').'.',
                'detail' => [
                    'reason' => 'mode_not_supported',
                ],
            ],
            $issues,
        );
    }
}
