<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Models\Node;
use App\Services\Nodes\Roles\NodeRoleAssignments;

final class DoctorProgressReportFactory
{
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
        ?string $appInstance = null,
    ): array {
        return [
            'healthy' => false,
            'mode' => $mode,
            'scope' => [
                'families' => $families,
                'node' => $target->name,
                'role' => $target->displayRole(),
                'roles' => $this->nodeRoles($target),
                'self' => false,
                'app' => $app,
                'app_instance' => $appInstance,
                'workspace' => $workspace,
                'key' => $key,
            ],
            'summary' => $this->summary($mode, $issues, $actions),
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

    /**
     * @param  list<array<string, mixed>>  $issues
     * @param  list<array<string, mixed>>  $actions
     * @return array{issues: int, fixed: int, adopted: int, skipped: int, conflicts: int, failed: int, planned: int}
     */
    private function summary(string $mode, array $issues, array $actions): array
    {
        $fixed = count(array_filter(
            $actions,
            fn (array $action): bool => in_array($action['mode'] ?? $mode, ['fix', 'restore'], true)
            && ($action['status'] ?? null) === 'completed',
        ));
        $adopted = count(array_filter(
            $actions,
            fn (array $action): bool => ($action['mode'] ?? $mode) === 'adopt'
            && in_array($action['status'] ?? null, ['completed', 'created', 'updated'], true),
        ));

        return [
            'issues' => count($issues),
            'fixed' => $fixed,
            'adopted' => $adopted,
            'skipped' => count(array_filter(
                $actions,
                fn (array $action): bool => ($action['status'] ?? null) === 'skipped',
            )),
            'conflicts' => count(array_filter(
                $actions,
                fn (array $action): bool => ($action['status'] ?? null) === 'conflict',
            )),
            'failed' => count(array_filter(
                $actions,
                fn (array $action): bool => ($action['status'] ?? null) === 'failed',
            )),
            'planned' => count(array_filter(
                $actions,
                fn (array $action): bool => ($action['status'] ?? null) === 'planned',
            )),
        ];
    }

    /**
     * @return list<string>
     */
    private function nodeRoles(Node $target): array
    {
        $roles = app(NodeRoleAssignments::class)->activeRoleNames($target);

        return $roles === [] ? ['operator'] : $roles;
    }
}
