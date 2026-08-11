<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Data\Doctor\DriftEntry;
use App\Data\Nodes\NodeConvergenceResult;
use App\Enums\DriftKind;
use App\Enums\Nodes\NodeConvergenceContext;
use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Models\Node;
use App\Models\NodeTool;
use App\Services\Doctor\DoctorIssueFactory;
use App\Services\Tools\ToolsFixer;
use App\Services\Tools\ToolsProbe;
use Throwable;

final readonly class NodeConverger
{
    public function __construct(
        private NodesProbe $nodesProbe,
        private ToolsProbe $toolsProbe,
        private ToolsFixer $toolsFixer,
        private DoctorIssueFactory $doctorIssueFactory,
    ) {}

    /**
     * @param  list<string>  $families
     */
    public function converge(
        Node $node,
        NodeConvergenceContext $context,
        array $families = ['tool'],
    ): NodeConvergenceResult {
        $families = $this->normalizeFamilies($families);
        $issues = [];

        if (in_array('node', $families, true)) {
            $issues = [
                ...$issues,
                ...$this->nodeIssuesForNode($node, $context),
            ];
        }

        return $this->applyResolvedIssues($node, $context, $families, $issues, probeSelectedFamilies: true);
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     */
    public function applyIssues(Node $node, NodeConvergenceContext $context, array $issues): NodeConvergenceResult
    {
        $issues = array_values(array_filter($issues, is_array(...)));

        return $this->applyResolvedIssues(
            node: $node,
            context: $context,
            families: $this->familiesFromIssues($issues),
            issues: $issues,
        );
    }

    /**
     * @param  list<string>  $families
     * @param  list<array<string, mixed>>  $issues
     */
    private function applyResolvedIssues(
        Node $node,
        NodeConvergenceContext $context,
        array $families,
        array $issues,
        bool $probeSelectedFamilies = false,
    ): NodeConvergenceResult {
        $actions = [];
        $remainingIssues = [];
        $nodeIssues = $this->mergeIssuePayloads($this->filterNodeIssues($issues), []);
        $toolIssues = $this->filterToolIssues($issues);

        if ($nodeIssues !== []) {
            $actions = [
                ...$actions,
                ...$this->applyNodeIssues($node, $context, $nodeIssues),
            ];

            $remainingIssues = [
                ...$remainingIssues,
                ...$this->nodeIssuesForNode(
                    node: $node,
                    context: $context,
                    onlyKeys: $this->keysFromIssues($nodeIssues),
                ),
            ];
        }

        if ($probeSelectedFamilies && in_array('tool', $families, true)) {
            $toolIssues = $this->mergeIssuePayloads(
                $toolIssues,
                $this->toolIssuesForNode(
                    node: $node,
                    context: $context,
                    onlyTools: $this->setupToolScope($node, $context),
                ),
            );
        }

        if ($toolIssues !== []) {
            $actions = [
                ...$actions,
                ...$this->applyToolIssues($node, $context, $toolIssues),
            ];

            $remainingIssues = [
                ...$remainingIssues,
                ...$this->toolIssuesForNode(
                    node: $node,
                    context: $context,
                    onlyTools: $this->toolNamesFromIssues($toolIssues),
                    onlyKeys: $this->keysFromIssues($toolIssues),
                ),
            ];
        }

        $actions = $this->markResolvedActionsThatStillHaveDriftAsFailed($actions, $remainingIssues);

        return new NodeConvergenceResult(
            context: $context,
            families: $families,
            issues: $issues,
            actions: $actions,
            remainingIssues: $remainingIssues,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @return list<array<string, mixed>>
     */
    private function applyNodeIssues(Node $node, NodeConvergenceContext $context, array $issues): array
    {
        $actions = [];

        foreach ($issues as $issue) {
            $entry = $this->driftEntryFromIssue($issue);
            $code = is_string($issue['code'] ?? null) ? $issue['code'] : $entry->key;

            try {
                $this->nodesProbe->reconcile($node, $entry);
            } catch (Throwable $e) {
                $actions[] = [
                    'family' => 'node',
                    'node' => $node->name,
                    'code' => $code,
                    'key' => $entry->key,
                    'mode' => $context->value,
                    'status' => 'failed',
                    'summary' => "Failed to fix {$code}.",
                    'details' => [
                        'error' => $e->getMessage(),
                    ],
                ];

                continue;
            }

            $actions[] = [
                'family' => 'node',
                'node' => $node->name,
                'code' => $code,
                'key' => $entry->key,
                'mode' => $context->value,
                'status' => 'completed',
                'summary' => is_string($issue['summary'] ?? null) ? $issue['summary'] : "Fixed {$code}.",
                'details' => $entry->detail,
            ];
        }

        return $actions;
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @return list<array<string, mixed>>
     */
    private function applyToolIssues(Node $node, NodeConvergenceContext $context, array $issues): array
    {
        $actions = [];

        foreach ($this->sortToolIssues($issues) as $issue) {
            $toolName = $this->toolNameFromIssue($issue);

            if ($toolName === null) {
                continue;
            }

            $tool = NodeTool::query()
                ->where('node_id', $node->id)
                ->where('name', $toolName)
                ->first();

            if (! $tool instanceof NodeTool) {
                continue;
            }

            $entry = $this->driftEntryFromIssue($issue);

            try {
                $action = $this->toolsFixer->fix($tool, $entry);
            } catch (Throwable $e) {
                $action = [
                    'family' => 'tool',
                    'node' => $node->name,
                    'code' => $entry->key,
                    'key' => $entry->key,
                    'mode' => $context->value,
                    'status' => 'failed',
                    'summary' => "Failed to fix {$entry->key}.",
                    'details' => [
                        'tool' => $tool->name,
                        'error' => $e->getMessage(),
                    ],
                ];
            }

            if ($action !== null) {
                $actions[] = $this->normalizeActionMode($action, $context);
            }
        }

        return $actions;
    }

    /**
     * @param  list<string>|null  $onlyKeys
     * @return list<array<string, mixed>>
     */
    private function nodeIssuesForNode(
        Node $node,
        NodeConvergenceContext $context,
        ?array $onlyKeys = null,
    ): array {
        $issues = [];

        foreach ($this->nodesProbe->roleBaselineDrift($node) as $entry) {
            if ($entry->key !== 'node.role_baseline_mismatch') {
                continue;
            }

            if ($onlyKeys !== null && ! in_array($entry->key, $onlyKeys, true)) {
                continue;
            }

            $issues[] = $this->nodeIssuePayload($entry, $node);
        }

        return $issues;
    }

    /**
     * @param  list<string>|null  $onlyTools
     * @param  list<string>|null  $onlyKeys
     * @return list<array<string, mixed>>
     */
    private function toolIssuesForNode(
        Node $node,
        NodeConvergenceContext $context,
        ?array $onlyTools = null,
        ?array $onlyKeys = null,
    ): array {
        $issues = [];

        $query = NodeTool::query()
            ->with('node')
            ->where('node_id', $node->id)
            ->orderBy('name');

        if ($onlyTools !== null) {
            $query->whereIn('name', $onlyTools);
        }

        $tools = $query->get();
        $snapshots = $this->toolsProbe->introspectMany($tools->all());

        foreach ($tools as $tool) {
            $snapshot = $snapshots[$tool->name] ?? $this->toolsProbe->introspect($tool);

            foreach ($this->toolsProbe->diff(
                $tool,
                $snapshot,
                allowProvisioning: $context->allowsProvisioningNode(),
            ) as $entry) {
                if ($onlyKeys !== null && ! in_array($entry->key, $onlyKeys, true)) {
                    continue;
                }

                $issues[] = $this->toolIssuePayload($entry, $tool);
            }
        }

        return $this->sortToolIssues($issues);
    }

    /**
     * @return array<string, mixed>
     */
    private function nodeIssuePayload(DriftEntry $entry, Node $node): array
    {
        return $this->doctorIssueFactory->fromDriftEntry($entry, $node->name)->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    private function toolIssuePayload(DriftEntry $entry, NodeTool $tool): array
    {
        $tool->loadMissing('node');

        return $this->doctorIssueFactory
            ->fromDriftEntry(
                $entry,
                $tool->node?->name,
                detail: [
                    ...($entry->detail ?? []),
                    'tool' => $tool->name,
                ],
            )
            ->toArray();
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    private function driftEntryFromIssue(array $issue): DriftEntry
    {
        $kind = is_string($issue['kind'] ?? null) ? DriftKind::tryFrom($issue['kind']) : null;

        return new DriftEntry(
            family: is_string($issue['family'] ?? null) ? $issue['family'] : 'tool',
            key: is_string($issue['key'] ?? null) ? $issue['key'] : 'unknown',
            kind: $kind ?? DriftKind::Unknown,
            summary: is_string($issue['summary'] ?? null) ? $issue['summary'] : '',
            detail: is_array($issue['detail'] ?? null) ? $issue['detail'] : [],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @return list<array<string, mixed>>
     */
    private function filterNodeIssues(array $issues): array
    {
        return array_values(array_filter(
            $issues,
            fn (array $issue): bool => (
                ($issue['family'] ?? null) === 'node'
                && ($issue['key'] ?? null) === 'node.role_baseline_mismatch'
            ),
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @return list<array<string, mixed>>
     */
    private function filterToolIssues(array $issues): array
    {
        return array_values(array_filter(
            $issues,
            fn (array $issue): bool => ($issue['family'] ?? null) === 'tool',
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @return list<string>
     */
    private function toolNamesFromIssues(array $issues): array
    {
        return array_values(array_unique(array_filter(array_map(
            $this->toolNameFromIssue(...),
            $issues,
        ))));
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    private function toolNameFromIssue(array $issue): ?string
    {
        $detail = is_array($issue['detail'] ?? null)
            ? $issue['detail']
            : (is_array($issue['details'] ?? null) ? $issue['details'] : []);

        return is_string($detail['tool'] ?? null) ? $detail['tool'] : null;
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @return list<string>
     */
    private function keysFromIssues(array $issues): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (array $issue): ?string => is_string($issue['key'] ?? null) ? $issue['key'] : null,
            $issues,
        ))));
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @return list<string>
     */
    private function familiesFromIssues(array $issues): array
    {
        $families = array_values(array_unique(array_filter(array_map(
            fn (array $issue): ?string => is_string($issue['family'] ?? null) ? $issue['family'] : null,
            $issues,
        ))));

        return $families === [] ? ['tool'] : $families;
    }

    /**
     * @param  list<array<string, mixed>>  $left
     * @param  list<array<string, mixed>>  $right
     * @return list<array<string, mixed>>
     */
    private function mergeIssuePayloads(array $left, array $right): array
    {
        $merged = [];
        $seen = [];

        foreach ([...$left, ...$right] as $issue) {
            $id = $this->issueResolutionId($issue);

            if ($id !== null && in_array($id, $seen, true)) {
                continue;
            }

            if ($id !== null) {
                $seen[] = $id;
            }

            $merged[] = $issue;
        }

        return $merged;
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @return list<array<string, mixed>>
     */
    private function sortToolIssues(array $issues): array
    {
        usort($issues, fn (array $a, array $b): int => $this->toolIssuePriority($a) <=> $this->toolIssuePriority($b));

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    private function toolIssuePriority(array $issue): int
    {
        return match ($this->toolNameFromIssue($issue)) {
            'docker' => 5,
            'php-cli' => 10,
            'composer' => 20,
            'git' => 30,
            'gh' => 40,
            'laravel-installer' => 50,
            'caddy' => 60,
            default => 100,
        };
    }

    /**
     * @return list<string>|null
     */
    private function setupToolScope(Node $node, NodeConvergenceContext $context): ?array
    {
        if ($context !== NodeConvergenceContext::Setup) {
            return null;
        }

        $hasDevelopmentRole = $node
            ->roleAssignments()
            ->where('role', NodeRoleName::AppDevelopment->value)
            ->whereIn('status', [
                NodeRoleStatus::Pending->value,
                NodeRoleStatus::Active->value,
            ])
            ->exists();

        if (! $hasDevelopmentRole) {
            return null;
        }

        return ['caddy', 'docker', 'php-cli', 'composer', 'git', 'gh', 'laravel-installer'];
    }

    /**
     * @param  list<string>  $families
     * @return list<string>
     */
    private function normalizeFamilies(array $families): array
    {
        $families = array_values(array_unique(array_filter($families, is_string(...))));

        return $families === [] ? ['tool'] : $families;
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    private function normalizeActionMode(array $action, NodeConvergenceContext $context): array
    {
        $action['mode'] = $context->value;

        return $action;
    }

    /**
     * @param  list<array<string, mixed>>  $actions
     * @param  list<array<string, mixed>>  $remainingIssues
     * @return list<array<string, mixed>>
     */
    private function markResolvedActionsThatStillHaveDriftAsFailed(array $actions, array $remainingIssues): array
    {
        $remainingIds = array_values(array_filter(array_map(
            $this->issueResolutionId(...),
            $remainingIssues,
        )));

        if ($remainingIds === []) {
            return $actions;
        }

        return array_map(function (array $action) use ($remainingIds): array {
            if (($action['status'] ?? null) !== 'completed') {
                return $action;
            }

            if (! in_array($this->issueResolutionId($action), $remainingIds, true)) {
                return $action;
            }

            $action['status'] = 'failed';
            $action['summary'] = "Failed to clear {$action['key']}.";
            $details = is_array($action['details'] ?? null) ? $action['details'] : [];
            $action['details'] = [
                ...$details,
                'error' => 'Drift remained after repair.',
            ];

            return $action;
        }, $actions);
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

        if ($family === 'tool' && ($tool = $this->toolNameFromIssue($item)) !== null) {
            return "{$family}:{$key}:{$code}:{$tool}";
        }

        if (
            $family === 'node'
            && $key === 'node.role_baseline_mismatch'
            && ($role = $this->roleNameFromIssue($item)) !== null
        ) {
            return "{$family}:{$key}:{$code}:{$role}";
        }

        return "{$family}:{$key}:{$code}";
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function roleNameFromIssue(array $item): ?string
    {
        if (is_array($item['detail'] ?? null)) {
            return is_string($item['detail']['role'] ?? null) ? $item['detail']['role'] : null;
        }

        if (is_array($item['details'] ?? null)) {
            return is_string($item['details']['role'] ?? null) ? $item['details']['role'] : null;
        }

        return null;
    }
}
