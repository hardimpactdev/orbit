<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Models\Node;

final readonly class DoctorFleetProgressReporter
{
    public function __construct(
        private DoctorFleetNodeProjection $fleetNodeProjection,
        private DoctorFleetProgressReportFactory $progressReportFactory,
    ) {}

    /** @param array<string, mixed> $report */
    public function complete(Node $node, int $nodeIndex, FleetProbeRunState $state, array $report): void
    {
        $state->issuesByIndex[$nodeIndex] = $this->fleetNodeProjection->nodeIssues($report);
        $state->issues = $this->fleetNodeProjection->orderedIssues(
            $state->scope->targets,
            $state->issuesByIndex,
        );
        $state->nodesByIndex[$nodeIndex] = $this->fleetNodeProjection->nodeSummary($node, $report);
        $state->nodes = $this->fleetNodeProjection->orderedNodeSummaries(
            $state->scope->targets,
            $state->nodesByIndex,
        );
        $state->nodeProgressStatuses[$nodeIndex]['status'] = 'done';

        if ($state->scope->onNodeProgress !== null) {
            $donePhase = 'done';
            ($state->scope->onNodeProgress)(
                $node,
                $donePhase,
                $this->progressReportFactory->make($state),
            );
        }
    }

    public function familyProgressReporter(
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
            if ($phase === 'running' && $completed !== null && $total !== null && $total > 0) {
                $nodeCompleted = ($doneFamilies * $total) + $completed;
                $nodeTotal = $totalFamilies * $total;

                if ($nodeCompleted < $nodeTotal) {
                    $this->emit($node, $nodeIndex, $state, $nodeCompleted, $nodeTotal);
                }

                return;
            }

            if ($phase === 'running' && $totalFamilies > 0 && $doneFamilies < $totalFamilies) {
                $this->emit($node, $nodeIndex, $state, $doneFamilies, $totalFamilies);
            }

            if ($phase === 'done') {
                $doneFamilies++;
            }
        };
    }

    private function emit(
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

        if ($state->scope->onNodeProgress !== null) {
            $runningPhase = 'running';
            ($state->scope->onNodeProgress)($node, $runningPhase, $this->progressReportFactory->make($state));
        }
    }
}
