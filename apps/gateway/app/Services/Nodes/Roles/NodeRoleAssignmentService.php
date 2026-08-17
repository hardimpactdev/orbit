<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles;

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Process;
use App\Services\Analytics\AnalyticsDatabaseResolver;
use App\Services\Analytics\AnalyticsRouteRegistrar;
use App\Services\Dns\DnsmasqReconciler;
use App\Services\S3\S3RouteRegistrar;
use App\Services\WebSockets\WebSocketValkeyResolver;
use Illuminate\Database\QueryException;
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
        private readonly NodeRoleActivator $roleActivator,
        private readonly RoleSelfGrantMaterializer $roleSelfGrantMaterializer,
        private readonly WebSocketValkeyResolver $webSocketValkeyResolver,
        private readonly AnalyticsDatabaseResolver $analyticsDatabaseResolver,
        private readonly AnalyticsRouteRegistrar $analyticsRouteRegistrar,
        private readonly S3RouteRegistrar $s3RouteRegistrar,
        private readonly DnsmasqReconciler $dnsmasqReconciler,
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

        $this->assertFleetRoleAvailable($role, $node);
        $this->guardSupportedPlatform($node, $definition);
        $this->guardAgainstConflicts($node, $definition);
        $this->roleActivator->ensureCanActivate($node, $role);

        $settingsData = $definition->settingsFromArray($settings)->toArray();
        $this->guardWebSocketValkeyNode($role, $settingsData);
        $this->guardAnalyticsDatabaseNodes($role, $settingsData);
        $this->guardAppProductionIngressNode($node, $role, $settingsData);

        try {
            $assignment = $node->roleAssignments()->create([
                'role' => $role,
                'status' => NodeRoleStatus::Pending->value,
                'settings' => $settingsData,
                'last_error' => null,
                'converged_at' => null,
            ]);
        } catch (QueryException $exception) {
            $this->assertFleetRoleAvailable($role, $node);

            throw $exception;
        }

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
        $this->roleActivator->ensureCanActivate($node, $role);

        $settingsData = $definition->settingsFromArray($settings)->toArray();
        $this->guardWebSocketValkeyNode($role, $settingsData);
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
        $this->roleActivator->ensureCanActivate($node, $role);
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

        $dependentPolicy = $force ? 'remove' : 'block';
        $removal = $this->markAssignmentRemoving($node, $assignment, $dependentPolicy, $role);
        $removingAssignment = $removal['assignment'];

        try {
            $this->converger->remove($node, $removingAssignment, $purgeData);

            if ($role === NodeRoleName::Analytics->value) {
                $this->analyticsRouteRegistrar->removeServiceRoute();
            }

            if ($role === NodeRoleName::S3->value && $this->hasActiveRouter()) {
                $this->s3RouteRegistrar->syncServiceRouteAfterBackendChange();
            }

            $this->completeRemoval(
                $node,
                $assignment,
                $dependentPolicy,
                $role,
                $removal['ingress_route_ids'],
            );

            if ($this->roleChangesWildcardDns($role)) {
                $this->dnsmasqReconciler->reconcileNodeRecords();
            }
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

    /**
     * @return array{assignment: NodeRoleAssignment, ingress_route_ids: list<int>}
     */
    private function markAssignmentRemoving(
        Node $node,
        NodeRoleAssignment $assignment,
        string $dependentPolicy,
        string $role,
    ): array {
        /** @var array{assignment: NodeRoleAssignment, ingress_route_ids: list<int>} $removal */
        $removal = DB::transaction(function () use ($node, $assignment, $dependentPolicy, $role): array {
            /** @var NodeRoleAssignment $transactionAssignment */
            $transactionAssignment = NodeRoleAssignment::query()
                ->lockForUpdate()
                ->findOrFail($assignment->id);

            $transactionDependents = $this->dependencyInspector->dependentSummaries($node, $transactionAssignment);

            if ($transactionDependents !== [] && $dependentPolicy === 'block') {
                throw new InvalidArgumentException("Role '{$role}' cannot be removed while dependents exist.");
            }

            $ingressRouteIds =
                $dependentPolicy === 'remove' && $role === NodeRoleName::Ingress->value
                    ? $this->dependencyInspector->ingressDependentRouteIds($node)
                    : [];

            $transactionAssignment->forceFill([
                'status' => NodeRoleStatus::Removing->value,
                'last_error' => null,
            ])->save();

            return [
                'assignment' => $transactionAssignment,
                'ingress_route_ids' => $ingressRouteIds,
            ];
        });

        return $removal;
    }

    /**
     * @param  list<int>  $ingressRouteIds
     */
    private function completeRemoval(
        Node $node,
        NodeRoleAssignment $assignment,
        string $dependentPolicy,
        string $role,
        array $ingressRouteIds,
    ): void {
        DB::transaction(function () use ($node, $assignment, $dependentPolicy, $role, $ingressRouteIds): void {
            /** @var NodeRoleAssignment $transactionAssignment */
            $transactionAssignment = NodeRoleAssignment::query()
                ->lockForUpdate()
                ->findOrFail($assignment->id);
            $transactionDependents = $this->dependencyInspector->dependentSummaries($node, $transactionAssignment);

            if ($transactionDependents !== [] && $dependentPolicy === 'block') {
                throw new InvalidArgumentException("Role '{$role}' cannot be removed while dependents exist.");
            }

            if (
                $dependentPolicy === 'remove'
                && ($transactionDependents !== []
                || $ingressRouteIds !== [])
            ) {
                $this->dependencyInspector->removeOrbitOwnedDependents(
                    $node,
                    $transactionAssignment,
                    $ingressRouteIds,
                );
            }

            $transactionAssignment->delete();

            $this->roleSelfGrantMaterializer->reconcileOnRoleRemoved($node, NodeRoleName::from($role));
        });
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

            if ($assignment->role === NodeRoleName::Analytics->value) {
                $this->analyticsRouteRegistrar->convergeServiceRoute($node);
            }

            $assignment = $this->roleActivator->activate($node, $assignment);

            if ($this->roleChangesWildcardDns($assignment->role)) {
                $this->dnsmasqReconciler->reconcileNodeRecords();
            }

            if (
                $assignment->role === NodeRoleName::S3->value
                && $node->isActive()
                && $this->hasActiveRouter()
            ) {
                $this->s3RouteRegistrar->syncServiceRoute();
            }
        } catch (Throwable $throwable) {
            $assignment->forceFill([
                'status' => NodeRoleStatus::Error->value,
                'last_error' => $throwable->getMessage(),
                'converged_at' => null,
            ])->save();
        }

        /** @var NodeRoleAssignment */
        return $assignment->fresh();
    }

    private function hasActiveRouter(): bool
    {
        return $this->assignments->activeRouterNodeQuery()->exists();
    }

    private function roleChangesWildcardDns(string $role): bool
    {
        return in_array($role, [NodeRoleName::AppDevelopment->value, NodeRoleName::Agent->value], true);
    }

    public function assertFleetRoleAvailable(string $role, ?Node $exceptNode = null): void
    {
        if ($role !== NodeRoleName::Analytics->value) {
            return;
        }

        $query = NodeRoleAssignment::query()
            ->where('role', NodeRoleName::Analytics->value);

        if ($exceptNode instanceof Node) {
            $query->where('node_id', '!=', $exceptNode->id);
        }

        $existing = $query->orderBy('id')->first();

        if (! $existing instanceof NodeRoleAssignment) {
            return;
        }

        $existingNode = Node::query()->find($existing->node_id);
        $existingNodeName = $existingNode instanceof Node ? $existingNode->name : (string) $existing->node_id;

        throw new InvalidArgumentException(
            "Role 'analytics' is already assigned to node '{$existingNodeName}'.",
        );
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
    private function guardWebSocketValkeyNode(string $role, array $settings): void
    {
        if ($role !== NodeRoleName::WebSocket->value) {
            return;
        }

        $valkeyNodeId = $settings['valkey_node_id'] ?? null;

        if (is_int($valkeyNodeId) && $this->webSocketValkeyResolver->usableValkeyNode($valkeyNodeId) instanceof Node) {
            return;
        }

        throw new InvalidArgumentException(
            'The websocket role requires valkey_node_id to reference an active database node with a Valkey process.',
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
        $postgresProcessId = $settings['postgres_process_id'] ?? null;
        $clickhouseNodeId = $settings['clickhouse_node_id'] ?? null;

        $postgresNode = is_int($postgresNodeId)
            ? $this->analyticsDatabaseResolver->usablePostgresNode($postgresNodeId)
            : null;
        $postgresProcess = $postgresNode instanceof Node && is_int($postgresProcessId)
            ? Process::query()
                ->whereKey($postgresProcessId)
                ->where('owner_type', $postgresNode->getMorphClass())
                ->where('owner_id', $postgresNode->getKey())
                ->where('runtime_config->service', 'postgres')
                ->first()
            : null;

        if (
            $postgresProcess instanceof Process
            && ! $this->analyticsDatabaseResolver->isPlausiblePostgresProcess($postgresProcess)
        ) {
            throw new InvalidArgumentException('The analytics role requires PostgreSQL 16 for Plausible.');
        }

        if (
            is_int($postgresNodeId)
            && is_int($clickhouseNodeId)
            && $postgresProcess instanceof Process
            && $this->analyticsDatabaseResolver->usableClickHouseNode($clickhouseNodeId) instanceof Node
        ) {
            return;
        }

        throw new InvalidArgumentException(
            'The analytics role requires postgres_node_id, postgres_process_id, and clickhouse_node_id to reference active database services.',
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
