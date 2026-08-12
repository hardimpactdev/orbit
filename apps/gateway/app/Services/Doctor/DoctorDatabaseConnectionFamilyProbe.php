<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DoctorIssue;
use App\Data\Doctor\DoctorTargetScope;
use App\Models\Node;
use App\Services\DatabaseConnections\DatabaseConnectionProbe;

final readonly class DoctorDatabaseConnectionFamilyProbe
{
    public function __construct(
        private DoctorFamilyProbeRunner $familyProbeRunner,
        private DatabaseConnectionProbe $databaseConnectionProbe,
    ) {}

    /**
     * @param  (callable(string, 'running'|'done', list<array<string, mixed>>, ?int, ?int): void)|null  $onFamilyProgress
     * @return list<DoctorIssue>
     */
    public function probe(
        Node $node,
        DoctorTargetScope $scope,
        ?string $key,
        ?callable $onFamilyProgress,
    ): array {
        return $this->familyProbeRunner->run(
            node: $node,
            family: 'database_connection',
            total: 1,
            key: $key,
            onFamilyProgress: $onFamilyProgress,
            probe: function (callable $addIssue, callable $advance) use ($node, $scope): void {
                foreach ($this->databaseConnectionProbe->probe($node, $scope) as $issue) {
                    $addIssue($issue);
                }

                $advance();
            },
        );
    }
}
