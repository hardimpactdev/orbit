<?php

declare(strict_types=1);

namespace App\Services\Doctor;

final class FleetProbeRunState
{
    /** @var array<int, array<string, mixed>> */
    public array $nodesByIndex = [];

    /** @var array<int, list<array<string, mixed>>> */
    public array $issuesByIndex = [];

    /**
     * @param  list<array{node: string, status: string, completed?: int, total?: int}>  $nodeProgressStatuses
     * @param  list<array<string, mixed>>  $issues
     * @param  list<array<string, mixed>>  $nodes
     */
    public function __construct(
        public readonly FleetProbeScope $scope,
        public array $nodeProgressStatuses,
        public array $issues,
        public array $nodes,
    ) {}
}
