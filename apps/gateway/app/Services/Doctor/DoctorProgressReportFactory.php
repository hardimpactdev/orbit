<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DoctorTargetScope;
use App\Models\Node;

final readonly class DoctorProgressReportFactory
{
    public function __construct(
        private DoctorReportSections $reportSections,
    ) {}

    /**
     * @param  list<string>  $families
     * @return array<string, string>
     */
    public function familyStatuses(array $families, string $status = 'queued'): array
    {
        $statuses = [];

        foreach ($families as $family) {
            $statuses[$family] = $status;
        }

        return $statuses;
    }

    /**
     * @param  list<string>  $families
     * @param  list<array<string, mixed>>  $issues
     * @param  list<array<string, mixed>>  $actions
     * @param  array<string, string>  $familyStatuses
     * @param  array<string, array{completed: int, total: int}>  $familyCheckCounts
     * @return array<string, mixed>
     */
    public function report(
        Node $target,
        string $mode,
        array $families,
        ?string $key,
        array $issues,
        array $actions,
        array $familyStatuses,
        string $state = 'running',
        array $familyCheckCounts = [],
        ?string $app = null,
        ?string $workspace = null,
        ?string $instance = null,
    ): array {
        return [
            'healthy' => false,
            'mode' => $mode,
            'scope' => $this->reportSections->nodeScope(
                families: $families,
                node: $target,
                key: $key,
                scope: new DoctorTargetScope(
                    app: $app,
                    workspace: $workspace,
                    instance: $instance,
                ),
            ),
            'summary' => $this->reportSections->progressSummary($mode, $issues, $actions),
            'issues' => $issues,
            'actions' => $actions,
            'progress' => [
                'state' => $state,
                'families' => array_map(
                    static function (string $family) use ($familyStatuses, $familyCheckCounts): array {
                        $entry = [
                            'family' => $family,
                            'status' => $familyStatuses[$family] ?? 'queued',
                        ];
                        $counts = $familyCheckCounts[$family] ?? null;

                        if ($counts !== null && $counts['total'] > 0) {
                            $entry['completed'] = $counts['completed'];
                            $entry['total'] = $counts['total'];
                        }

                        return $entry;
                    },
                    $families,
                ),
            ],
        ];
    }
}
