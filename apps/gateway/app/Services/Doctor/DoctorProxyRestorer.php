<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DoctorIssue;
use App\Data\Doctor\DriftEntry;
use App\Enums\DriftKind;
use App\Models\Node;

final readonly class DoctorProxyRestorer
{
    public function __construct(
        private DoctorManagedProxyRestorer $managedProxyRestorer,
        private DoctorProxyRouteRestorer $proxyRouteRestorer,
        private DoctorProxyRouteInventory $proxyRouteInventory,
    ) {}

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>|null
     */
    public function apply(
        Node $fallbackNode,
        string $mode,
        string $key,
        array $detail,
        DoctorIssue $issue,
    ): ?array {
        $node = $this->nodeFromIssue($issue) ?? $fallbackNode;

        if ($this->managedProxyRestorer->supportsBeforeExtra($key)) {
            return $this->managedProxyRestorer->apply(
                $node,
                $mode,
                $key,
                $this->driftEntryFromIssue($issue),
            );
        }

        if ($issue->kind === DriftKind::Extra) {
            return $this->proxyRouteRestorer->applyExtra($node, $mode, $key, $issue);
        }

        if ($this->managedProxyRestorer->supportsAfterExtra($key)) {
            return $this->managedProxyRestorer->apply($node, $mode, $key, $this->driftEntryFromIssue($issue));
        }

        return $this->proxyRouteRestorer->applyStored($mode, $detail, $this->driftEntryFromIssue($issue));
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    public function issueTargetsWorkspace(array $detail): bool
    {
        return $this->proxyRouteInventory->issueTargetsWorkspace($detail);
    }

    /**
     * Keep non-proxy positions stable while ordering proxy repairs by their
     * runtime dependencies.
     *
     * @param  list<DoctorIssue>  $issues
     * @return list<DoctorIssue>
     */
    public function orderForRecovery(array $issues): array
    {
        /** @var array<int, list<DoctorIssue>> $proxyIssuesByPriority */
        $proxyIssuesByPriority = [[], [], []];

        foreach ($issues as $issue) {
            if ($issue->family !== 'proxy') {
                continue;
            }

            $proxyIssuesByPriority[$this->managedProxyRestorer->recoveryPriority($issue->key)][] = $issue;
        }

        $orderedProxyIssues = array_merge(...$proxyIssuesByPriority);
        $orderedIssues = [];
        $proxyIssueIndex = 0;

        foreach ($issues as $issue) {
            $orderedIssues[] = $issue->family === 'proxy'
                ? $orderedProxyIssues[$proxyIssueIndex++]
                : $issue;
        }

        return $orderedIssues;
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

    private function nodeFromIssue(DoctorIssue $issue): ?Node
    {
        $nodeName = $issue->node;

        if ($nodeName === null) {
            return null;
        }

        $node = Node::query()->where('name', $nodeName)->first();

        return $node instanceof Node ? $node : null;
    }
}
