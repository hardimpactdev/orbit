<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Analytics\AnalyticsRoleSettings;
use App\Domain\Analytics\AnalyticsRoleSettingsRepository;
use App\Domain\Analytics\AnalyticsStorageProcessGuard;
use App\Domain\Metrics\ExporterDegradationReason;
use App\Domain\Metrics\MetricsFleetCapture;
use App\Domain\Nodes\NodeReachabilityProbe;
use App\Domain\Nodes\NodeRoleDependencyInspector;
use App\Domain\Nodes\NodeRoleDependencySet;
use App\Domain\Nodes\NodeRoleDirectInput;
use App\Domain\Nodes\NodeRoleIntent;
use App\Domain\Nodes\NodeRoleRemovalOutcome;
use App\Domain\Nodes\NodeRoleValidationException;
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\RoleRegistry;
use App\Domain\Operations\OperationChildIntent;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStore;
use App\Domain\Routes\RouteRemovalGuard;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Nodes\NodeWorkerRollout;
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
        private OperationKinds $kinds,
        private AssignRoleAction $assign,
        private RoleRegistry $roles,
        private AnalyticsRoleSettingsRepository $analytics,
        private AnalyticsStorageProcessGuard $analyticsStorage,
        private NodeRoleDependencyInspector $dependencies,
        private NodeReachabilityProbe $reachability,
        private RouteRemovalGuard $routes,
    ) {}

    public function add(Node $node, RoleName $role, bool $converge = false, ?AnalyticsRoleSettings $analytics = null, ?string $requestId = null, bool $duringProvisioning = false, bool $native = false): Operation
    {
        if (($existing = $this->idempotency->replay('node.role_add')) !== null) {
            return $existing;
        }
        if (! is_array($this->kinds->definition('node.role_add'))) {
            throw new ResourceOperationException('operation.kind_unavailable', 'Node role admission is waiting for its executor contract.', 503);
        }

        return DB::transaction(function () use ($node, $role, $converge, $analytics, $requestId, $duringProvisioning, $native): Operation {
            $node = Node::query()->lockForUpdate()->findOrFail($node->id);
            if (! in_array($node->status, $duringProvisioning ? [LifecycleStatus::Provisioning, LifecycleStatus::Active] : [LifecycleStatus::Active], true)) {
                throw new RoleAssignmentException('Roles can be changed only on an active node.');
            }
            if ($node->platform !== 'linux') {
                throw new ResourceOperationException('node.platform_unsupported', "Node platform [{$node->platform}] does not support roles.", 422);
            }
            if ($duringProvisioning ? ! $this->roles->definition($role)->assignableDuringProvisioning : ! $this->roles->definition($role)->mutable) {
                throw new RoleAssignmentException("Role [{$role->value}] is protected from generic mutation.");
            }
            $firstRole = ! $duringProvisioning && ! $node->roles()->where('status', LifecycleStatus::Active)->exists();
            $metricsBefore = null;
            if ($native) {
                if ($duringProvisioning || $this->idempotency->parent !== null || $firstRole
                    || ! in_array($role, [RoleName::Database, RoleName::CiRunner], true)) {
                    throw new ResourceOperationException('operation.kind_unavailable', 'This role transition needs its owning same-root publication or enrollment composition.', 422);
                }
                if (! app(NodeWorkerRollout::class)->allows($node)) {
                    throw new ResourceOperationException('swamp.node_worker_not_ready', 'The Node is outside the native worker rollout.', 422);
                }
                if (! is_string($node->agent_secret_hash) || preg_match('/\A[a-f0-9]{64}\z/D', $node->agent_secret_hash) !== 1) {
                    throw new ResourceOperationException('node.agent_rekey_required', 'The standalone role needs the existing recorded Node agent identity.', 409);
                }
                if (Node::query()->where('status', LifecycleStatus::Active)->whereHas('roles',
                    static fn ($query) => $query->where('role', RoleName::Metrics)->where('status', LifecycleStatus::Active))->exists()) {
                    $metricsBefore = app(MetricsFleetCapture::class)->capture();
                }
            }
            if ($role === RoleName::Analytics) {
                $analytics ??= $this->analytics->find($node);
                if ($analytics === null) {
                    throw new ResourceOperationException('analytics.settings_missing', 'The analytics role requires a PostgreSQL Process and a ClickHouse Process.', 422);
                }
                $this->analyticsStorage->assert($analytics->postgresProcessId, $analytics->clickhouseProcessId);
            }

            // Take the durable target hold before changing an assignment or its Settings.
            $operation = $this->operations->execute('node.role_add', 'node', $node->id, $node->id,
                ['role' => $role->value, 'converge' => $converge, ...($native ? ['node_id' => $node->id, 'native_role' => true] : [])],
                'operator', $requestId, expectedWriter: $native ? 'direct' : null);
            if (! $operation->wasRecentlyCreated) {
                return $operation;
            }
            $assignment = $this->assign->execute($node, $role);
            $created = $assignment->wasRecentlyCreated;
            $noOp = ! $created && ! $converge && $role === RoleName::Ingress && $assignment->status === LifecycleStatus::Active;
            if (! $created && ! $noOp) {
                if (! $converge) {
                    throw new RoleAssignmentException("Role [{$role->value}] is already assigned; explicit convergence is required.");
                }
                if (! $assignment->canClaimConvergence()) {
                    throw new RoleAssignmentException("Role [{$role->value}] cannot converge from status [{$assignment->status->value}].");
                }
                $assignment->claimConvergence();
            }
            if ($analytics !== null) {
                $this->analytics->store($node, $analytics);
            }
            $nativeInput = [];
            if ($native) {
                $snapshot = app(NodeRoleDirectInput::class)->capture($node->refresh(), $assignment, $created, $metricsBefore);
                $impact = ValidatedData::object($snapshot['metrics_impact'] ?? []);
                $footprint = ValidatedData::object($snapshot['footprint']);
                if (($impact['required'] ?? false) === true || $footprint['refusal'] !== null) {
                    throw new ResourceOperationException('operation.kind_unavailable', 'The applicable role contributions need their owning native composition.', 422);
                }
                $nativeInput = ['input' => [...$operation->input, '_role' => $snapshot,
                    'secret_hash' => ['$secret' => 'node.agent_secret_hash', 'id' => $node->id]]];
            }
            $this->store->change($operation, [...$nativeInput, 'state' => [...($operation->state ?? []),
                'role_intent' => NodeRoleIntent::capture($assignment, $created),
                'role_first_managed' => $firstRole, 'role_noop' => $noOp]]);

            return $operation;
        });
    }

    public function remove(Node $node, RoleName $role, bool $force = false, bool $purgeData = false, bool $offline = false, bool $deleted = false, ?string $requestId = null): Operation|NodeRoleRemovalOutcome
    {
        if (($existing = $this->idempotency->replay('node.role_remove')) !== null) {
            return $existing;
        }
        if (! is_array($this->kinds->definition('node.role_remove'))) {
            throw new ResourceOperationException('operation.kind_unavailable', 'Node role removal is waiting for its executor contract.', 503);
        }
        $this->guardMutable($node, $role);
        $this->routes->assertRoleRemovable($node, $role);
        $assignment = $node->roles()->where('role', $role)->first();
        if ($assignment === null && $role !== RoleName::Ingress) {
            $node->roles()->where('role', $role)->firstOrFail();
        }
        if ($role === RoleName::AppDev && $node->instances()->exists()) {
            throw new NodeRoleValidationException("Role [{$role->value}] cannot be removed while node [{$node->name}] owns Instances.", [
                'reason' => 'instances_attached', 'role' => $role->value,
            ]);
        }
        $preview = $this->dependencies->inspect($node, $role);
        if (! $force) {
            throw new NodeRoleValidationException('Use --force to remove this node role.', [
                'field' => 'force', 'reason' => 'destructive_consent_required', 'role' => $role->value, 'dependents' => $preview->summaries,
            ]);
        }
        if ($assignment === null && $role === RoleName::Ingress) {
            return new NodeRoleRemovalOutcome(new NodeRoleDependencySet([], [], [], []));
        }
        $degradation = match (true) {
            $offline && $deleted => ExporterDegradationReason::Unreachable,
            $offline => $this->reachability->degradation($node),
            default => null,
        };

        return DB::transaction(function () use ($node, $role, $purgeData, $offline, $deleted, $degradation, $requestId): Operation {
            $node = Node::query()->lockForUpdate()->findOrFail($node->id);
            $this->guardMutable($node, $role);
            $this->routes->assertRoleRemovable($node, $role);
            $assignment = $node->roles()->where('role', $role)->lockForUpdate()->firstOrFail();
            if ($role === RoleName::AppDev && $node->instances()->exists()) {
                throw new NodeRoleValidationException("Role [{$role->value}] cannot be removed while node [{$node->name}] owns Instances.", [
                    'reason' => 'instances_attached', 'role' => $role->value,
                ]);
            }
            if ($assignment->status !== LifecycleStatus::Active && ! $assignment->isStaleClaim()
                && ! ($assignment->status === LifecycleStatus::Failed && is_string($assignment->failed_step)
                    && (str_starts_with($assignment->failed_step, 'converge:') || str_starts_with($assignment->failed_step, 'remove:')))) {
                throw new NodeRoleValidationException("Role [{$role->value}] cannot be removed from status [{$assignment->status->value}].");
            }
            $dependencies = $this->dependencies->inspect($node, $role);
            $operation = $this->operations->execute('node.role_remove', 'node', $node->id, $node->id,
                ['role' => $role->value, 'purge_data' => $purgeData, 'offline' => $offline, 'deleted' => $deleted], 'operator', $requestId);
            if (! $operation->wasRecentlyCreated) {
                return $operation;
            }
            $intent = NodeRoleIntent::capture($assignment, false);
            $assignment->claimRemoval();
            $this->store->change($operation, ['state' => [...($operation->state ?? []), 'role_intent' => $intent,
                'role_offline' => $degradation !== null, 'role_degradation' => $degradation?->value,
                'role_dependencies' => ['instance_ids' => $dependencies->instanceIds, 'workspace_ids' => $dependencies->workspaceIds,
                    'process_ids' => $dependencies->processIds, 'summaries' => $dependencies->summaries]]]);

            return $operation;
        });
    }

    public function relocate(Node $target, RoleName $role, bool $force = false, ?Node $from = null, ?string $requestId = null): Operation
    {
        if (($existing = $this->idempotency->replay('node.role_relocate')) !== null) {
            return $existing;
        }
        if (! is_array($this->kinds->definition('node.role_relocate'))) {
            throw new ResourceOperationException('operation.kind_unavailable', 'Node role relocation is waiting for its executor contract.', 503);
        }
        if (! $this->roles->definition($role)->relocatable) {
            throw new NodeRoleValidationException("Role [{$role->value}] cannot be relocated.", ['field' => 'role', 'role' => $role->value]);
        }
        if (! $force) {
            throw new NodeRoleValidationException('Use --force to relocate this node role.', [
                'field' => 'force', 'reason' => 'destructive_consent_required', 'role' => $role->value, 'dependents' => [],
            ]);
        }
        $this->guardMutable($target, $role);
        if ($from !== null) {
            $this->guardMutable($from, $role);
            if ($from->is($target)) {
                throw new NodeRoleValidationException("Role [{$role->value}] cannot relocate from node [{$from->name}] onto itself.", ['field' => 'from', 'role' => $role->value]);
            }
        }

        return DB::transaction(function () use ($target, $from, $role, $requestId): Operation {
            $assignment = NodeRole::query()->where('role', $role)->lockForUpdate()->first();
            if ($assignment === null) {
                throw new NodeRoleValidationException("Role [{$role->value}] is not assigned.", ['field' => 'role', 'role' => $role->value]);
            }
            if ($assignment->status !== LifecycleStatus::Active) {
                throw new NodeRoleValidationException("Role [{$role->value}] cannot relocate from status [{$assignment->status->value}].", ['field' => 'role', 'role' => $role->value]);
            }
            $reconcile = $assignment->node_id === $target->id;
            if ($reconcile && $from === null) {
                throw new NodeRoleValidationException("Role [{$role->value}] is already assigned to node [{$target->name}].", ['field' => 'role', 'role' => $role->value]);
            }
            $source = $from ?? $assignment->node;
            if (! $reconcile && $source->id !== $assignment->node_id) {
                throw new NodeRoleValidationException("Role [{$role->value}] is assigned to node [{$assignment->node->name}], not [{$source->name}].", ['field' => 'from', 'role' => $role->value]);
            }
            $this->guardMutable($source, $role);
            $this->assign->preflightTransfer($target, $role);
            // Keep serving on the source. A source child takes its hold before any transfer or runtime effect.
            $original = clone $assignment;
            $original->node_id = $source->id;
            $operation = $this->operations->execute('node.role_relocate', 'node', $target->id, $target->id,
                ['role' => $role->value, 'source_node_id' => $source->id, 'target_node_id' => $target->id], 'operator', $requestId);
            if (! $operation->wasRecentlyCreated) {
                return $operation;
            }
            $this->store->change($operation, ['state' => [...($operation->state ?? []),
                'role_intent' => NodeRoleIntent::capture($original, false, $target), 'role_reconcile' => $reconcile]]);

            return $operation;
        });
    }

    /** @param array{key:string,kind:string,node_id:int|null,input:array<string,mixed>} $intent */
    public function relocationChild(Operation $parent, array $intent): Operation
    {
        $sourceStage = $intent['kind'] === 'node.role_relocate_source';
        $retained = ValidatedData::object($parent->state['role_intent'] ?? null);
        $expectedParent = $sourceStage ? 'node.role_relocate' : 'node.role_relocate_source';
        $nodeId = $retained[$sourceStage ? 'source_node_id' : 'target_node_id'] ?? null;
        if ($parent->kind !== $expectedParent || $this->idempotency->parent?->id !== $parent->id
            || $this->idempotency->childKey !== $intent['key'] || $intent['node_id'] !== $nodeId
            || ! OperationChildIntent::same($intent['input'], ['assignment_id' => $retained['assignment_id'] ?? null])) {
            throw new ResourceOperationException('operation.child_forbidden', 'The role transfer stage differs from its retained Node intent.', 422);
        }
        $role = RoleName::from(ValidatedData::string($retained['role'] ?? null));
        if (! $this->roles->definition($role)->relocatable) {
            throw new ResourceOperationException('operation.child_forbidden', 'This role cannot be relocated.', 422);
        }
        $node = Node::query()->findOrFail($nodeId);
        $this->guardMutable($node, $role);

        return DB::transaction(function () use ($parent, $intent, $retained, $sourceStage, $node): Operation {
            $operation = $this->operations->execute($intent['kind'], 'node', $node->id, $node->id, $intent['input'], 'node-role', $parent->request_id);
            if ($operation->wasRecentlyCreated) {
                $this->store->change($operation, ['state' => [...($operation->state ?? []), 'role_intent' => $retained,
                    'role_relocation_source' => $sourceStage, 'role_relocation_target' => ! $sourceStage,
                    'role_owned_by_parent' => true, 'role_first_managed' => false, 'role_noop' => false]]);
            }

            return $operation;
        });
    }

    /** @param array{key:string,kind:string,node_id:int|null,input:array<string,mixed>} $intent */
    public function routerChild(Operation $parent, array $intent): Operation
    {
        if (($existing = $this->idempotency->replay($intent['kind'])) !== null) {
            return $existing;
        }
        if ($this->idempotency->parent?->id !== $parent->id || $parent->target_type !== 'cluster_router'
            || ! in_array($parent->kind, ['cluster.router_set', 'cluster.router_unset'], true)
            || ! in_array($intent['kind'], ['node.role_converge', 'node.role_remove'], true)
            || $this->idempotency->childKey !== $intent['key']) {
            throw new ResourceOperationException('operation.child_forbidden', 'Router role work requires its owning Cluster parent.', 422);
        }
        $plan = ValidatedData::object($parent->state['route_plan'] ?? []);
        $remove = $intent['kind'] === 'node.role_remove';
        if ($remove) {
            if (! OperationChildIntent::same($intent['input'], ['role' => 'router', 'purge_data' => false, 'offline' => false])) {
                throw new ResourceOperationException('operation.child_forbidden', 'The Cluster can remove only its online Router effects.', 422);
            }
            $roleIds = ValidatedData::integerList($plan['old_role_ids'] ?? []);
            $assignment = NodeRole::query()->whereIn('id', $roleIds)->where('node_id', $intent['node_id'])->where('role', RoleName::Router)->sole();
        } else {
            $id = ValidatedData::integer($intent['input']['assignment_id'] ?? null);
            $assignment = NodeRole::query()->findOrFail($id);
            if ($id !== ($parent->input['candidate_role_id'] ?? null) || $id !== ($plan['candidate_role_id'] ?? null)
                || ! in_array($id, ValidatedData::integerList($plan['assignment_ids'] ?? []), true)) {
                throw new ResourceOperationException('operation.child_forbidden', 'The Router candidate is not in its Cluster plan.', 422);
            }
        }
        if ($assignment->role !== RoleName::Router || $assignment->node_id !== $intent['node_id']
            || $assignment->cluster_id !== ($parent->input['cluster_id'] ?? null)
            || ! in_array($assignment->node_id, ValidatedData::integerList($plan['node_ids'] ?? []), true)
            || $assignment->status !== ($remove ? LifecycleStatus::Removing : LifecycleStatus::Provisioning)) {
            throw new ResourceOperationException('operation.child_forbidden', 'The Router assignment differs from its owning Cluster intent.', 422);
        }

        return DB::transaction(function () use ($intent, $assignment): Operation {
            $operation = $this->operations->execute($intent['kind'], 'node', $assignment->node_id, $assignment->node_id, $intent['input'], 'cluster', null);
            if ($operation->wasRecentlyCreated) {
                $this->store->change($operation, ['state' => [...($operation->state ?? []), 'role_intent' => NodeRoleIntent::capture($assignment, false),
                    'role_owned_by_parent' => true, 'role_offline' => false, 'role_first_managed' => false, 'role_noop' => false]]);
            }

            return $operation;
        });
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
