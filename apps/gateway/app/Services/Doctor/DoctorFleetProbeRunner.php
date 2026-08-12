<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use Closure;
use Illuminate\Support\Collection;

final readonly class DoctorFleetProbeRunner
{
    public const int BATCH_SIZE = DoctorFleetProbeExecutor::BATCH_SIZE;

    public function __construct(
        private DoctorNodeFamilyResolver $nodeFamilies,
        private DoctorFleetProbeExecutor $fleetProbeExecutor,
        private DoctorReportSections $reportSections,
    ) {}

    /**
     * @param  list<string>  $families
     * @return Collection<int, Node>
     */
    public function targetsForFamilies(array $families = []): Collection
    {
        /** @var Collection<int, Node> $nodes */
        $nodes = Node::query()
            ->where('status', NodeStatus::Active->value)
            ->whereHas('roleAssignments', static fn ($query) => $query->where('status', NodeRoleStatus::Active->value))
            ->with('roleAssignments')
            ->orderBy('name')
            ->get();

        /**
         * @var Collection<int, Node> $targets
         * @mago-expect lint:inline-variable-return
         */
        $targets = $nodes
            ->filter(fn (Node $node): bool => $this->nodeFamilies->nodeSupportsFamilies($node, $families))
            ->values();

        return $targets;
    }

    /**
     * @param  list<string>  $families
     * @param  (callable(Node, 'running'|'done', ?array<string, mixed>=): void)|null  $onNodeProgress
     * @return array<string, mixed>
     */
    public function probe(array $families = [], ?string $key = null, ?callable $onNodeProgress = null): array
    {
        $targets = $this->targetsForFamilies($families);
        $state = $this->newRunState($targets, $families, $key, $onNodeProgress);

        $this->fleetProbeExecutor->execute($state);

        return [
            'healthy' => $state->issues === [],
            'mode' => 'verify',
            'scope' => $this->reportSections->fleetScope($targets, $families, $key),
            'summary' => $this->reportSections->summary($state->issues, []),
            'issues' => $state->issues,
            'actions' => [],
            'nodes' => $state->nodes,
        ];
    }

    /**
     * @param  Collection<int, Node>  $targets
     * @param  list<string>  $families
     * @param  (callable(Node, 'running'|'done', ?array<string, mixed>=): void)|null  $onNodeProgress
     */
    private function newRunState(
        Collection $targets,
        array $families,
        ?string $key,
        ?callable $onNodeProgress,
    ): FleetProbeRunState {
        $nodeProgressStatuses = $targets
            ->map(static fn (Node $node): array => ['node' => $node->name, 'status' => 'queued'])
            ->values()
            ->all();

        /** @var list<array{node: string, status: string, completed?: int, total?: int}> $nodeProgressStatuses */

        return new FleetProbeRunState(
            scope: new FleetProbeScope(
                targets: $targets,
                families: $families,
                key: $key,
                onNodeProgress: $onNodeProgress === null ? null : Closure::fromCallable($onNodeProgress),
            ),
            nodeProgressStatuses: $nodeProgressStatuses,
            issues: [],
            nodes: [],
        );
    }
}
