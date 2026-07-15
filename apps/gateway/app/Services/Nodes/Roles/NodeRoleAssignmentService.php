<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles;

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Analytics\AnalyticsDatabaseResolver;
use App\Services\WebSockets\WebSocketRedisResolver;
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
        private readonly RoleSelfGrantMaterializer $roleSelfGrantMaterializer,
        private readonly WebSocketRedisResolver $webSocketRedisResolver,
        private readonly AnalyticsDatabaseResolver $analyticsDatabaseResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $settings
     */
    public function add(Node $node, string $role, array $settings): NodeRoleAssignment
    {
        $this->guardNotGatewayCoupledInfrastructureRole($role);

        $definition = $this->registry->definition($role);

        if (! $definition->assignableByRoleCommand) {
            throw new InvalidArgumentException("Role '{$role}' cannot be assigned through this service.");
        }

        return $this->persistAndConverge($node, $role, $settings);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function addDuringCreation(Node $node, string $role, array $settings): NodeRoleAssignment
    {
        $this->guardNotGatewayCoupledInfrastructureRole($role);

        $definition = $this->registry->definition($role);

        if (! $definition->assignableByNodeNew) {
            throw new InvalidArgumentException("Role '{$role}' cannot be assigned during node creation.");
        }

        return $this->persistAndConverge($node, $role, $settings);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function persistAndConverge(Node $node, string $role, array $settings): NodeRoleAssignment
    {
        $definition = $this->registry->definition($role);

        if ($this->assignments->find($node, $role) instanceof NodeRoleAssignment) {
            throw new InvalidArgumentException("Role '{$role}' is already assigned to node '{$node->name}'.");
        }

        $this->guardSupportedPlatform($node, $definition);
        $this->guardAgainstConflicts($node, $definition);

        $settingsData = $definition->settingsFromArray($settings)->toArray();
        $this->guardWebSocketRedisNode($role, $settingsData);
        $this->guardAnalyticsDatabaseNodes($role, $settingsData);
        $this->guardAppProductionIngressNode($node, $role, $settingsData);

        $assignment = $node->roleAssignments()->create([
            'role' => $role,
            'status' => NodeRoleStatus::Pending->value,
            'settings' => $settingsData,
            'last_error' => null,
            'converged_at' => null,
        ]);

        return $this->clearManagedOptInAfterActiveRole($node, $this->converge($node, $assignment));
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function update(Node $node, string $role, array $settings): NodeRoleAssignment
    {
        $this->guardNotGatewayCoupledInfrastructureRole($role);

        $definition = $this->registry->definition($role);

        if (! $definition->assignableByRoleCommand) {
            throw new InvalidArgumentException("Role '{$role}' cannot be updated through this service.");
        }

        $assignment = $this->assignments->find($node, $role);

        if (! $assignment instanceof NodeRoleAssignment) {
            throw new InvalidArgumentException("Role '{$role}' is not assigned to node '{$node->name}'.");
        }

        $this->guardSupportedPlatform($node, $definition);
        $this->guardAgainstConflicts($node, $definition);

        $settingsData = $definition->settingsFromArray($settings)->toArray();
        $this->guardWebSocketRedisNode($role, $settingsData);
        $this->guardAnalyticsDatabaseNodes($role, $settingsData);
        $this->guardAppProductionIngressNode($node, $role, $settingsData);

        $assignment->forceFill([
            'settings' => $settingsData,
            'status' => NodeRoleStatus::Pending->value,
            'last_error' => null,
            'converged_at' => null,
        ])->save();

        return $this->converge($node, $assignment->fresh() ?? $assignment);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function retryDuringCreation(Node $node, string $role, array $settings): NodeRoleAssignment
    {
        $definition = $this->registry->definition($role);

        if (! $definition->assignableByNodeNew) {
            throw new InvalidArgumentException("Role '{$role}' cannot be assigned during node creation.");
        }

        $assignment = $this->assignments->find($node, $role);

        if (! $assignment instanceof NodeRoleAssignment) {
            throw new InvalidArgumentException("Role '{$role}' is not assigned to node '{$node->name}'.");
        }

        $this->guardSupportedPlatform($node, $definition);
        $settingsData = $definition->settingsFromArray($settings)->toArray();

        $assignment->forceFill([
            'settings' => $settingsData,
            'status' => NodeRoleStatus::Pending->value,
            'last_error' => null,
            'converged_at' => null,
        ])->save();

        return $this->clearManagedOptInAfterActiveRole(
            $node,
            $this->converge($node, $assignment->fresh() ?? $assignment),
        );
    }

    public function remove(Node $node, string $role, bool $force = false, bool $purgeData = false): void
    {
        $this->guardNotGatewayCoupledInfrastructureRole($role);

        $definition = $this->registry->definition($role);

        if ($purgeData && ! $force) {
            throw new InvalidArgumentException('The purgeData option requires force.');
        }

        if (! $definition->assignableByRoleCommand) {
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

                $this->roleSelfGrantMaterializer->reconcileOnRoleRemoved($node, NodeRoleName::from($role));
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

    private function guardNotGatewayCoupledInfrastructureRole(string $role): void
    {
        if (! in_array(
            $role,
            [NodeRoleName::Gateway->value, NodeRoleName::Vpn->value, NodeRoleName::Router->value],
            true,
        )) {
            return;
        }

        throw new InvalidArgumentException("Role '{$role}' is gateway-coupled and cannot be assigned independently.");
    }

    private function clearManagedOptInAfterActiveRole(Node $node, NodeRoleAssignment $assignment): NodeRoleAssignment
    {
        if ($assignment->status === NodeRoleStatus::Active && $node->managed) {
            $node->forceFill(['managed' => false])->save();
        }

        return $assignment;
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

            $this->roleSelfGrantMaterializer->materializeOnRoleApplied($node, NodeRoleName::from($assignment->role));
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

        throw new InvalidArgumentException(
            "Role '{$definition->name}' conflicts with {$conflict->status->value} role '{$conflict->role}'.",
        );
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function guardWebSocketRedisNode(string $role, array $settings): void
    {
        if ($role !== NodeRoleName::WebSocket->value) {
            return;
        }

        $redisNodeId = $settings['redis_node_id'] ?? null;

        if (is_int($redisNodeId) && $this->webSocketRedisResolver->usableRedisNode($redisNodeId) instanceof Node) {
            return;
        }

        throw new InvalidArgumentException(
            'The websocket role requires redis_node_id to reference an active database node with a Redis process.',
        );
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function guardAnalyticsDatabaseNodes(string $role, array $settings): void
    {
        if ($role !== NodeRoleName::Analytics->value) {
            return;
        }

        $postgresNodeId = $settings['postgres_node_id'] ?? null;
        $clickhouseNodeId = $settings['clickhouse_node_id'] ?? null;

        if (
            is_int($postgresNodeId)
            && is_int($clickhouseNodeId)
            && $this->analyticsDatabaseResolver->usablePostgresNode($postgresNodeId) instanceof Node
            && $this->analyticsDatabaseResolver->usableClickHouseNode($clickhouseNodeId) instanceof Node
        ) {
            return;
        }

        throw new InvalidArgumentException(
            'The analytics role requires postgres_node_id and clickhouse_node_id to reference active database nodes with PostgreSQL and ClickHouse processes.',
        );
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function guardAppProductionIngressNode(Node $node, string $role, array $settings): void
    {
        if ($role !== NodeRoleName::AppProduction->value) {
            return;
        }

        $ingressNodeId = $settings['ingress_node_id'] ?? null;

        if (! is_int($ingressNodeId) || $ingressNodeId <= 0) {
            throw new InvalidArgumentException('The app-prod role requires an active ingress node.');
        }

        if ($ingressNodeId === $node->id && $this->nodeHasActiveIngressAssignment($node)) {
            return;
        }

        $ingressNode = Node::query()->find($ingressNodeId);

        if (! $ingressNode instanceof Node || ! $this->nodeCanServeIngress($ingressNode)) {
            throw new InvalidArgumentException('The app-prod role requires an active ingress node.');
        }
    }

    private function nodeCanServeIngress(Node $node): bool
    {
        if (! $node->isActive()) {
            return false;
        }

        return $this->nodeHasActiveIngressAssignment($node);
    }

    private function nodeHasActiveIngressAssignment(Node $node): bool
    {
        return $node
            ->roleAssignments()
            ->where('role', NodeRoleName::Ingress->value)
            ->where('status', NodeRoleStatus::Active->value)
            ->exists();
    }
}
