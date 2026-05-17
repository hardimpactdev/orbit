<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles;

use App\Enums\Nodes\NodeRoleStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use InvalidArgumentException;
use Throwable;

class NodeRoleAssignmentService
{
    public function __construct(
        private readonly NodeRoleRegistry $registry = new NodeRoleRegistry,
        private readonly NodeRoleAssignments $assignments = new NodeRoleAssignments,
        private readonly NodeRoleBaselineConverger $converger = new NodeRoleBaselineConverger,
        private readonly NodeRoleDependencyInspector $dependencyInspector = new NodeRoleDependencyInspector,
    ) {}

    /**
     * @param  array<string, mixed>  $settings
     */
    public function add(Node $node, string $role, array $settings): NodeRoleAssignment
    {
        $definition = $this->registry->definition($role);

        if (! $definition->assignableByCommand) {
            throw new InvalidArgumentException("Role '{$role}' cannot be assigned through this service.");
        }

        $this->guardSupportedPlatform($node, $definition);

        $settingsData = $definition->settingsFromArray($settings)->toArray();
        $this->guardAgainstConflicts($node, $definition);

        $assignment = $node->roleAssignments()->create([
            'role' => $role,
            'status' => NodeRoleStatus::Pending->value,
            'settings' => $settingsData,
            'last_error' => null,
            'converged_at' => null,
        ]);

        return $this->converge($node, $assignment);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function update(Node $node, string $role, array $settings): NodeRoleAssignment
    {
        $definition = $this->registry->definition($role);
        $assignment = $this->assignments->find($node, $role);

        if (! $assignment instanceof NodeRoleAssignment) {
            throw new InvalidArgumentException("Role '{$role}' is not assigned to node '{$node->name}'.");
        }

        $this->guardSupportedPlatform($node, $definition);

        $assignment->forceFill([
            'settings' => $definition->settingsFromArray($settings)->toArray(),
            'status' => NodeRoleStatus::Pending->value,
            'last_error' => null,
            'converged_at' => null,
        ])->save();

        return $this->converge($node, $assignment->fresh() ?? $assignment);
    }

    public function remove(Node $node, string $role, bool $force = false, bool $purgeData = false): void
    {
        if ($purgeData && ! $force) {
            throw new InvalidArgumentException('The purgeData option requires force.');
        }

        if ($role === 'gateway') {
            throw new InvalidArgumentException("Role '{$role}' cannot be removed through this service.");
        }

        $assignment = $this->assignments->find($node, $role);

        if (! $assignment instanceof NodeRoleAssignment) {
            throw new InvalidArgumentException("Role '{$role}' is not assigned to node '{$node->name}'.");
        }

        $dependents = $this->dependencyInspector->dependentSummaries($node, $assignment);

        if ($dependents !== [] && ! $force) {
            throw new InvalidArgumentException("Role '{$role}' cannot be removed while dependents exist.");
        }

        $assignment->forceFill([
            'status' => NodeRoleStatus::Removing->value,
            'last_error' => null,
        ])->save();

        try {
            if ($dependents !== []) {
                $this->dependencyInspector->removeOrbitOwnedDependents($node, $assignment, $purgeData);
            }

            $this->converger->remove($node, $assignment, $purgeData);
            $assignment->delete();
        } catch (Throwable $throwable) {
            $assignment->forceFill([
                'status' => NodeRoleStatus::Error->value,
                'last_error' => $throwable->getMessage(),
            ])->save();

            return;
        }
    }

    private function converge(Node $node, NodeRoleAssignment $assignment): NodeRoleAssignment
    {
        try {
            $this->converger->converge($node, $assignment);

            $assignment->forceFill([
                'status' => NodeRoleStatus::Active->value,
                'converged_at' => now(),
                'last_error' => null,
            ])->save();
        } catch (Throwable $throwable) {
            $assignment->forceFill([
                'status' => NodeRoleStatus::Error->value,
                'last_error' => $throwable->getMessage(),
                'converged_at' => null,
            ])->save();
        }

        /** @var NodeRoleAssignment $freshAssignment */
        $freshAssignment = $assignment->fresh();

        return $freshAssignment;
    }

    private function guardSupportedPlatform(Node $node, NodeRoleDefinition $definition): void
    {
        if ($this->assignments->platformSupported($definition, $node->platform)) {
            return;
        }

        throw new InvalidArgumentException("Role '{$definition->name}' does not support platform '{$node->platform}'.");
    }

    private function guardAgainstConflicts(Node $node, NodeRoleDefinition $definition): void
    {
        $conflict = $this->assignments->conflicting($node, $definition)->first();

        if (! $conflict instanceof NodeRoleAssignment) {
            return;
        }

        throw new InvalidArgumentException("Role '{$definition->name}' conflicts with {$conflict->status} role '{$conflict->role}'.");
    }
}
