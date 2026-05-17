<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles;

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class NodeRoleAssignmentService
{
    public function __construct(
        private readonly NodeRoleRegistry $registry,
        private readonly NodeRoleAssignments $assignments,
        private readonly NodeRoleBaselineConverger $converger,
        private readonly NodeRoleDependencyInspector $dependencyInspector,
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

        if ($this->assignments->find($node, $role) instanceof NodeRoleAssignment) {
            throw new InvalidArgumentException("Role '{$role}' is already assigned to node '{$node->name}'.");
        }

        $this->guardSupportedPlatform($node, $definition);
        $this->guardAgainstConflicts($node, $definition);

        $settingsData = $definition->settingsFromArray($settings)->toArray();

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

        if (! $definition->assignableByCommand) {
            throw new InvalidArgumentException("Role '{$role}' cannot be updated through this service.");
        }

        $assignment = $this->assignments->find($node, $role);

        if (! $assignment instanceof NodeRoleAssignment) {
            throw new InvalidArgumentException("Role '{$role}' is not assigned to node '{$node->name}'.");
        }

        $this->guardSupportedPlatform($node, $definition);
        $this->guardAgainstConflicts($node, $definition);

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
        $definition = $this->registry->definition($role);

        if ($purgeData && ! $force) {
            throw new InvalidArgumentException('The purgeData option requires force.');
        }

        if (! $definition->assignableByCommand) {
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

        try {
            DB::transaction(function () use ($node, $assignment, $force, $purgeData, $role): void {
                $transactionAssignment = NodeRoleAssignment::query()
                    ->lockForUpdate()
                    ->findOrFail($assignment->id);

                $transactionDependents = $this->dependencyInspector->dependentSummaries($node, $transactionAssignment);

                if ($transactionDependents !== [] && ! $force) {
                    throw new InvalidArgumentException("Role '{$role}' cannot be removed while dependents exist.");
                }

                $transactionAssignment->forceFill([
                    'status' => NodeRoleStatus::Removing->value,
                    'last_error' => null,
                ])->save();

                if ($force && $transactionDependents !== []) {
                    $this->dependencyInspector->removeOrbitOwnedDependents($node, $transactionAssignment);
                }

                $this->converger->remove($node, $transactionAssignment, $purgeData);

                $transactionAssignment->delete();

                $this->syncLegacyNodeFields($node);
            });
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            NodeRoleAssignment::query()
                ->whereKey($assignment->id)
                ->update([
                    'status' => NodeRoleStatus::Error->value,
                    'last_error' => $throwable->getMessage(),
                ]);

            throw $throwable;
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

            $this->syncLegacyNodeFields($node);
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

    private function syncLegacyNodeFields(Node $node): void
    {
        $activeAssignments = $node->roleAssignments()
            ->where('status', NodeRoleStatus::Active->value)
            ->orderBy('role')
            ->get();

        $role = 'control';
        $environment = null;
        $tld = null;

        $gateway = $activeAssignments->firstWhere('role', NodeRoleName::Gateway->value);
        $appDevelopment = $activeAssignments->firstWhere('role', NodeRoleName::AppDevelopment->value);
        $appProduction = $activeAssignments->firstWhere('role', NodeRoleName::AppProduction->value);
        $database = $activeAssignments->firstWhere('role', NodeRoleName::Database->value);

        if ($gateway instanceof NodeRoleAssignment) {
            $role = NodeRoleName::Gateway->value;
        } elseif ($appDevelopment instanceof NodeRoleAssignment) {
            $role = 'app';
            $environment = 'development';

            $developmentTld = $appDevelopment->settings['tld'] ?? null;
            $tld = is_string($developmentTld) ? $developmentTld : null;
        } elseif ($appProduction instanceof NodeRoleAssignment) {
            $role = 'app';
            $environment = 'production';
        } elseif ($database instanceof NodeRoleAssignment) {
            $role = NodeRoleName::Database->value;
        }

        $node->forceFill([
            'role' => $role,
            'environment' => $environment,
            'tld' => $tld,
        ])->save();

        $node->unsetRelation('roleAssignments');
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
