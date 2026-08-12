<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Models\Node;
use Illuminate\Support\Collection;

final readonly class DoctorFleetProgressReportFactory
{
    public function __construct(
        private DoctorNodeFamilyResolver $nodeFamilies,
        private DoctorReportSections $reportSections,
    ) {}

    /** @return array<string, mixed> */
    public function make(FleetProbeRunState $state): array
    {
        return [
            'healthy' => $state->issues === [],
            'mode' => 'verify',
            'scope' => $this->reportSections->fleetScope(
                $state->scope->targets,
                $state->scope->families,
                $state->scope->key,
            ),
            'summary' => $this->reportSections->summary($state->issues, []),
            'issues' => $state->issues,
            'actions' => [],
            'nodes' => $this->nodes($state->scope->targets, $state->scope->families, $state->nodes),
            'progress' => [
                'state' => 'running',
                'nodes' => $state->nodeProgressStatuses,
            ],
        ];
    }

    /**
     * @param  Collection<int, Node>  $targets
     * @param  list<string>  $families
     * @param  list<array<string, mixed>>  $completedNodes
     * @return list<array<string, mixed>>
     */
    private function nodes(Collection $targets, array $families, array $completedNodes): array
    {
        /** @var array<string, array<string, mixed>> $completedByName */
        $completedByName = [];

        foreach ($completedNodes as $node) {
            $name = is_string($node['node'] ?? null) ? trim($node['node']) : '';

            if ($name !== '') {
                $completedByName[$name] = $node;
            }
        }

        $fleetFamilies = $this->nodeFamilies->fleetFamilies($targets, $families);
        $nodes = [];

        foreach ($targets as $target) {
            $nodes[] = $completedByName[$target->name] ?? [
                'node' => $target->name,
                'role' => $target->displayRole(),
                'roles' => $this->reportSections->roles($target),
                'healthy' => true,
                'families' => $fleetFamilies,
                'summary' => ['issues' => 0],
            ];
        }

        return $nodes;
    }
}
