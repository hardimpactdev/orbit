<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Data\Nodes\NodeData;
use App\Domain\Analytics\AnalyticsRoleSettings;
use App\Domain\Analytics\AnalyticsRoleSettingsRepository;
use App\Domain\Analytics\AnalyticsStorageProcessGuard;
use App\Domain\Analytics\PlausibleProcess;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Nodes\NativeNodeRoleAddInput;
use App\Domain\Nodes\NativeNodeRoleRelocateInput;
use App\Domain\Nodes\NativeNodeRoleRemovalInput;
use App\Domain\Nodes\NodeRoleCertificateInput;
use App\Domain\Nodes\NodeRoleDirectInput;
use App\Domain\Nodes\NodeRoleMetricsInput;
use App\Domain\Nodes\NodeRolePublicationInput;
use App\Domain\Nodes\NodeRoleRelocateInput;
use App\Domain\Nodes\NodeRoleRemovalProjection;
use App\Domain\Nodes\NodeRoleRemoveInput;
use App\Domain\Nodes\NodeRoleRuntimeInput;
use App\Domain\Nodes\NodeRoleSettings;
use App\Domain\Nodes\NodeRoleValidationException;
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\RoleRegistry;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStore;
use App\Domain\Processes\ProcessOwnershipClaim;
use App\Domain\Routes\RouteRemovalGuard;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Claims and records role intent atomically; it never installs or publishes a runtime. */
final readonly class AdmitNodeRoleOperationAction
{
    public function __construct(
        private StartOperationAction $operations,
        private OperationStore $store,
        private OperationIdempotency $idempotency,
        private AssignRoleAction $assign,
        private RoleRegistry $roles,
        private AnalyticsRoleSettingsRepository $analytics,
        private AnalyticsStorageProcessGuard $analyticsStorage,
        private RouteRemovalGuard $routes,
    ) {}

    public function add(Node $node, RoleName $role, bool $converge = false, ?AnalyticsRoleSettings $analytics = null, ?string $requestId = null, bool $duringProvisioning = false): Operation
    {
        $kind = $converge ? 'node.role_converge' : 'node.role_add';
        if (($existing = $this->idempotency->replay($kind)) !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($node, $role, $converge, $analytics, $requestId, $duringProvisioning, $kind): Operation {
            $node = Node::query()->lockForUpdate()->findOrFail($node->id);
            if ($role === RoleName::Database && (! is_string($node->wireguard_ip) || $node->wireguard_ip === '')) {
                throw new ResourceOperationException('database.wireguard_ip_missing', 'The database role requires its recorded WireGuard address.', 422);
            }
            if ($duringProvisioning || ! app(ManagedNodeEligibility::class)->allows($node)) {
                throw new ResourceOperationException('operation.kind_unavailable', 'Role work requires its exact managed Node worker.', 422);
            }
            if (! $this->roles->definition($role)->mutable) {
                throw new RoleAssignmentException("Role [{$role->value}] is protected from generic mutation.");
            }
            $this->assign->preflight($node, $role);
            $existing = $node->roles()->where('role', $role)->first();
            if ($converge && $existing === null) {
                throw new RoleAssignmentException("Role [{$role->value}] is not assigned; add it before convergence.");
            }
            if ($converge && ! $existing->canClaimConvergence()) {
                throw new RoleAssignmentException("Role [{$role->value}] cannot converge from status [{$existing->status->value}].");
            }
            if ($existing !== null && ! $converge && ! ($role === RoleName::Ingress && $existing->status === LifecycleStatus::Active)) {
                throw new RoleAssignmentException("Role [{$role->value}] is already assigned; explicit convergence is required.");
            }
            if ($role === RoleName::Analytics) {
                app(ProcessOwnershipClaim::class)->forNode($node->id, PlausibleProcess::NAME);
                $analytics ??= $this->analytics->find($node);
                if ($analytics === null) {
                    throw new ResourceOperationException('analytics.settings_missing', 'The analytics role requires its storage Processes.', 422);
                }
                $this->analyticsStorage->assert($analytics->postgresProcessId, $analytics->clickhouseProcessId);
                $this->analytics->store($node, $analytics);
            }
            $beforePublications = app(NativeNodeRoleRemovalInput::class)->publications($node, $role);
            $operation = $this->operations->execute($kind, 'node', $node->id, $node->id,
                ['node_id' => $node->id, 'role' => $role->value, 'converge' => $converge], 'operator', $requestId,
                lockKey: OperationStore::lockKey('node', $node->id));
            if (! $operation->wasRecentlyCreated) {
                return $operation;
            }
            $assignment = $this->assign->execute($node, $role);
            $created = $assignment->wasRecentlyCreated;
            $created ? $assignment->startFirstAdd() : $assignment->claimConvergence();
            if ($role === RoleName::Gateway) {
                app(GrantGatewayRoleAccessAction::class)->execute($node);
            }
            if (! $converge || $role !== RoleName::Analytics) {
                app(NodeRoleRuntimeInput::class)->prepare($node, $assignment);
            }
            $snapshot = app(NodeRoleDirectInput::class)->capture($node->refresh(), $assignment, $created);
            $metrics = $snapshot['metrics_fleet'] === null ? null : ValidatedData::object($snapshot['metrics_fleet']);
            $metricsOwner = $metrics === null ? $node->id : ValidatedData::integer($metrics['metrics_node_id']);
            $runtime = ValidatedData::object($snapshot['runtime'] ?? []);
            $clickhouse = ValidatedData::object($runtime['clickhouse'] ?? []);
            $nativeInput = app(NativeNodeRoleAddInput::class)->capture($node, $assignment, $snapshot, $beforePublications, $operation);
            $this->store->change($operation, ['input' => [...$operation->input, '_role' => $snapshot,
                'native_role' => $nativeInput['intent'], 'execution_secrets' => $nativeInput['execution_secrets'],
                'participant_node_ids' => array_values(array_unique([$node->id,
                    ...$nativeInput['participant_node_ids'],
                    ...NodeRolePublicationInput::participants(ValidatedData::object($snapshot['publication'])),
                    ...NodeRoleCertificateInput::participants(ValidatedData::object($snapshot['certificates'])),
                    ...NodeRoleMetricsInput::participants($metrics),
                    ...(isset($clickhouse['node_id']) ? [ValidatedData::integer($clickhouse['node_id'])] : [])])),
                'metrics_runtime' => $snapshot['metrics_fleet'] === null ? null : ['$secret' => 'metrics.fleet_runtime', 'id' => $metricsOwner],
                'private_bindings' => ['metrics_runtime' => [
                    'owner_node_id' => $metricsOwner, 'read_node_ids' => $snapshot['metrics_fleet'] === null ? [] : [$metricsOwner], 'write_node_ids' => []]],
                'secret_hash' => ['$secret' => 'node.agent_secret_hash', 'id' => $node->id],
                'runtime_material' => ['$secret' => 'node.role_runtime', 'id' => $node->id]]]);

            return $operation;
        });
    }

    public function remove(Node $node, RoleName $role, bool $force = false, bool $purgeData = false, bool $offline = false, bool $deleted = false, ?string $requestId = null): Operation
    {
        if (($existing = $this->idempotency->replay('node.role_remove')) !== null) {
            return $existing;
        }
        if (! $force) {
            throw new NodeRoleValidationException('Use --force to remove this node role.', [
                'field' => 'force', 'reason' => 'destructive_consent_required', 'role' => $role->value,
            ]);
        }

        return DB::transaction(function () use ($node, $role, $purgeData, $offline, $deleted, $requestId): Operation {
            $node = Node::query()->lockForUpdate()->findOrFail($node->id);
            $this->guardMutable($node, $role);
            if ($role === RoleName::Swamp) {
                throw new NodeRoleValidationException("Role [{$role->value}] runs this removal's Swamp run, so it can't remove itself.", [
                    'reason' => 'swamp_self_removal', 'role' => $role->value,
                ]);
            }
            if (! app(ManagedNodeEligibility::class)->allows($node)) {
                throw new ResourceOperationException('operation.kind_unavailable', 'Role removal requires recorded SSH management.', 422);
            }
            $this->routes->assertRoleRemovable($node, $role);
            $assignment = $node->roles()->where('role', $role)->lockForUpdate()->firstOrFail();
            if ($role === RoleName::AppDev && $node->instances()->exists()) {
                throw new NodeRoleValidationException('The app-dev role cannot be removed while its Node owns Instances.', [
                    'reason' => 'instances_attached', 'role' => $role->value,
                ]);
            }
            NodeRoleRemoveInput::refuseOffline($offline, $deleted);
            $input = ['node_id' => $node->id, 'role' => $role->value, 'purge_data' => $purgeData, 'offline' => $offline];
            if ($assignment->neverTouchedNode()) {
                return $this->recordRemoval($node, $assignment, $input, $requestId);
            }
            $operation = $this->operations->execute('node.role_remove', 'node', $node->id, $node->id, $input, 'operator', $requestId);
            if (! $operation->wasRecentlyCreated) {
                return $operation;
            }
            $native = app(NativeNodeRoleRemovalInput::class);
            $before = $native->publications($node, $role);
            $assignment->claimRemoval();
            $node->unsetRelation('roles');
            $snapshot = app(NodeRoleRemoveInput::class)->capture($node, $assignment, $purgeData, $offline, $deleted);
            $intent = $native->capture($node, $assignment, $before, purgeData: $purgeData);
            $this->bindRemoval($operation, $snapshot, $intent);

            return $operation;
        });
    }

    /**
     * R1: a role that never touched the Node has nothing to withdraw, so the Gateway deletes the
     * assignment and its settings and records a succeeded Operation. The caller holds the Node and assignment locks.
     *
     * @param  array<string,mixed>  $input
     */
    private function recordRemoval(Node $node, NodeRole $assignment, array $input, ?string $requestId): Operation
    {
        $snapshot = app(NodeRoleRuntimeInput::class)->removal($node, $assignment->role);
        $result = ['node_id' => $node->id, 'assignment_id' => $assignment->id, 'role' => $assignment->role->value,
            'role_removed' => true, 'offline' => false];
        $operation = $this->operations->record('node.role_remove', 'node', $node->id, $node->id, $input, $result, 'operator', $requestId);
        if ($operation->wasRecentlyCreated) {
            NodeRoleRemovalProjection::recordCleanup(['node_id' => $node->id, 'role' => $assignment->role->value, 'runtime' => $snapshot, 'purge_data' => false]);
            $assignment->delete();
            app(RecordEventBroadcaster::class)->broadcast(RecordEventType::NodeUpdated, $node->id, NodeData::fromModel($node->refresh())->toArray());
        }

        return $operation;
    }

    public function relocate(Node $target, RoleName $role, bool $force = false, ?Node $from = null, ?string $requestId = null): Operation
    {
        if (($existing = $this->idempotency->replay('node.role_relocate')) !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($target, $role, $force, $from, $requestId): Operation {
            $target = Node::query()->lockForUpdate()->findOrFail($target->id);
            $prepared = app(NodeRoleRelocateInput::class)->prepare($target, $role, $force, $from);
            $this->guardMutable($target, $role);
            $source = Node::query()->lockForUpdate()->findOrFail($prepared['source']->id);
            $assignment = NodeRole::query()->lockForUpdate()->findOrFail($prepared['assignment']->id);
            $operation = $this->operations->execute('node.role_relocate', 'node', $target->id, $target->id,
                ['node_id' => $target->id, 'role' => $role->value, 'source_node_id' => $source->id], 'operator', $requestId);
            if (! $operation->wasRecentlyCreated) {
                return $operation;
            }
            $native = app(NativeNodeRoleRelocateInput::class);
            $before = $native->before($target, $source, $role);
            $assignment->update(['node_id' => $target->id]);
            $assignment->claimConvergence();
            app(NodeRoleSettings::class)->copyForTransfer($source->id, $target->id, $role);
            if ($role === RoleName::Gateway) {
                app(GrantGatewayRoleAccessAction::class)->execute($target);
            }
            app(NodeRoleRuntimeInput::class)->prepare($target, $assignment);
            $intent = $native->capture($target->refresh(), $source, $assignment, $before);
            $this->store->change($operation, ['input' => [...$operation->input, 'intent' => $intent,
                '_relocate' => ['assignment_id' => $assignment->id, 'role' => $role->value,
                    'source_node_id' => $source->id, 'target_node_id' => $target->id]]]);

            return $operation;
        });
    }

    /** @param array<string,mixed> $snapshot
     * @param  array<string,mixed>  $intent
     */
    private function bindRemoval(Operation $operation, array $snapshot, array $intent): void
    {
        $metrics = $snapshot['metrics_fleet'] === null ? null : ValidatedData::object($snapshot['metrics_fleet']);
        $owner = $metrics === null ? $operation->node_id : ValidatedData::integer($metrics['metrics_node_id']);
        $this->store->change($operation, ['input' => [...$operation->input, '_role_remove' => $snapshot, 'intent' => $intent,
            'participant_node_ids' => array_values(array_unique([$operation->node_id,
                ...NodeRolePublicationInput::participants(ValidatedData::object($snapshot['publication'])),
                ...NodeRoleCertificateInput::participants(ValidatedData::object($snapshot['certificates'])),
                ...NodeRoleMetricsInput::participants($metrics)])),
            'metrics_runtime' => $metrics === null ? null : ['$secret' => 'metrics.fleet_runtime', 'id' => $owner],
            'private_bindings' => ['metrics_runtime' => ['owner_node_id' => $owner, 'read_node_ids' => $metrics === null ? [] : [$owner], 'write_node_ids' => []]]]]);
    }

    private function guardMutable(Node $node, RoleName $role): void
    {
        if (! $node->exists || $node->status !== LifecycleStatus::Active) {
            throw new NodeRoleValidationException('Roles can be changed only on an active node.');
        }
        if (! $this->roles->definition($role)->mutable) {
            throw new NodeRoleValidationException("Role [{$role->value}] is protected from generic mutation.");
        }
    }
}
