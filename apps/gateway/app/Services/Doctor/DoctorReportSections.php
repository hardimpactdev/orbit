<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DoctorIssue;
use App\Data\Doctor\DoctorTargetScope;
use App\Enums\DoctorIssueDisposition;
use App\Models\Node;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Support\Collection;

final readonly class DoctorReportSections
{
    public function __construct(
        private NodeRoleAssignments $nodeRoleAssignments,
        private DoctorNodeFamilyResolver $nodeFamilies,
    ) {}

    /**
     * @return list<string>
     */
    public function roles(Node $node): array
    {
        $roles = $this->nodeRoleAssignments->activeRoleNames($node);

        return $roles === [] ? ['operator'] : $roles;
    }

    /**
     * @param  list<string>  $families
     * @return array{families: list<string>, node: string, role: string, roles: list<string>, self: false, app: string|null, instance: string|null, workspace: string|null, key: string|null}
     */
    public function nodeScope(
        array $families,
        Node $node,
        ?string $key,
        DoctorTargetScope $scope,
    ): array {
        return [
            'families' => $families,
            'node' => $node->name,
            'role' => $node->displayRole(),
            'roles' => $this->roles($node),
            'self' => false,
            'app' => $scope->app,
            'instance' => $scope->instance,
            'workspace' => $scope->workspace,
            'key' => $key,
        ];
    }

    /**
     * @param  Collection<int, Node>  $targets
     * @param  list<string>  $families
     * @return array{families: list<string>, node: null, role: 'fleet', self: false, app: null, workspace: null, key: string|null, targets: list<string>}
     */
    public function fleetScope(Collection $targets, array $families, ?string $key): array
    {
        return [
            'families' => $this->nodeFamilies->fleetFamilies($targets, $families),
            'node' => null,
            'role' => 'fleet',
            'self' => false,
            'app' => null,
            'workspace' => null,
            'key' => $key,
            'targets' => $targets
                ->map(static fn (Node $node): string => $node->name)
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  list<mixed>  $issues
     * @param  list<array<string, mixed>>  $actions
     * @return array{issues: int, fixed: int, adopted: int, skipped: int, conflicts: int, failed: int, planned: int}
     */
    public function summary(array $issues, array $actions): array
    {
        return $this->buildSummary($issues, $actions, null);
    }

    /**
     * Progress actions can omit their mode while the operation is still running.
     *
     * @param  list<mixed>  $issues
     * @param  list<array<string, mixed>>  $actions
     * @return array{issues: int, fixed: int, adopted: int, skipped: int, conflicts: int, failed: int, planned: int}
     */
    public function progressSummary(string $mode, array $issues, array $actions): array
    {
        return $this->buildSummary($issues, $actions, $mode);
    }

    /**
     * @param  list<DoctorIssue>  $issues
     * @return array<string, int>
     */
    public function dispositions(array $issues): array
    {
        $counts = [
            DoctorIssueDisposition::GenuineDrift->value => 0,
            DoctorIssueDisposition::BlockedInspection->value => 0,
            DoctorIssueDisposition::InvalidIntent->value => 0,
            DoctorIssueDisposition::RuntimeIncident->value => 0,
        ];

        foreach ($issues as $issue) {
            $disposition = $issue->disposition->value;

            if (array_key_exists($disposition, $counts)) {
                $counts[$disposition]++;
            }
        }

        return $counts;
    }

    /**
     * @param  list<DoctorIssue>  $issues
     * @return list<array<string, mixed>>
     */
    public function serializeIssues(array $issues): array
    {
        return array_map(
            static fn (DoctorIssue $issue): array => $issue->toArray(),
            $issues,
        );
    }

    /**
     * @param  list<mixed>  $issues
     * @param  list<array<string, mixed>>  $actions
     * @return array{issues: int, fixed: int, adopted: int, skipped: int, conflicts: int, failed: int, planned: int}
     */
    private function buildSummary(array $issues, array $actions, ?string $defaultMode): array
    {
        return [
            'issues' => count($issues),
            'fixed' => count(array_filter(
                $actions,
                static fn (array $action): bool => in_array(
                    needle: $action['mode'] ?? $defaultMode,
                    haystack: ['fix', 'restore'],
                    strict: true,
                )
                && ($action['status'] ?? null) === 'completed',
            )),
            'adopted' => count(array_filter(
                $actions,
                static fn (array $action): bool => ($action['mode'] ?? $defaultMode) === 'adopt'
                && in_array(
                    needle: $action['status'] ?? null,
                    haystack: ['completed', 'created', 'updated'],
                    strict: true,
                ),
            )),
            'skipped' => count(array_filter(
                $actions,
                static fn (array $action): bool => ($action['status'] ?? null) === 'skipped',
            )),
            'conflicts' => count(array_filter(
                $actions,
                static fn (array $action): bool => ($action['status'] ?? null) === 'conflict',
            )),
            'failed' => count(array_filter(
                $actions,
                static fn (array $action): bool => ($action['status'] ?? null) === 'failed',
            )),
            'planned' => count(array_filter(
                $actions,
                static fn (array $action): bool => ($action['status'] ?? null) === 'planned',
            )),
        ];
    }
}
