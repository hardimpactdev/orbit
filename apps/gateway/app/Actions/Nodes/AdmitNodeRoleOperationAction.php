<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Analytics\AnalyticsRoleSettings;
use App\Domain\Analytics\AnalyticsRoleSettingsRepository;
use App\Domain\Analytics\AnalyticsStorageProcessGuard;
use App\Domain\Metrics\ExporterDegradationReason;
use App\Domain\Nodes\NodeReachabilityProbe;
use App\Domain\Nodes\NodeRoleDependencyInspector;
use App\Domain\Nodes\NodeRoleDependencySet;
use App\Domain\Nodes\NodeRoleIntent;
use App\Domain\Nodes\NodeRoleRemovalOutcome;
use App\Domain\Nodes\NodeRoleValidationException;
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\RoleRegistry;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStore;
use App\Domain\Routes\RouteRemovalGuard;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
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

    public function add(Node $node, RoleName $role, bool $converge = false, ?AnalyticsRoleSettings $analytics = null, ?string $requestId = null): Operation
    {
        if (($existing = $this->idempotency->replay('node.role_add')) !== null) {
            return $existing;
        }
        if (! is_array($this->kinds->definition('node.role_add'))) {
            throw new ResourceOperationException('operation.kind_unavailable', 'Node role admission is waiting for its executor contract.', 503);
        }

        return DB::transaction(function () use ($node, $role, $converge, $analytics, $requestId): Operation {
            $node = Node::query()->lockForUpdate()->findOrFail($node->id);
            if ($node->status !== LifecycleStatus::Active) {
                throw new RoleAssignmentException('Roles can be changed only on an active node.');
            }
            if ($node->platform !== 'linux') {
                throw new ResourceOperationException('node.platform_unsupported', "Node platform [{$node->platform}] does not support roles.", 422);
            }
            if (! $this->roles->definition($role)->mutable) {
                throw new RoleAssignmentException("Role [{$role->value}] is protected from generic mutation.");
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
                ['role' => $role->value, 'converge' => $converge], 'operator', $requestId);
            if (! $operation->wasRecentlyCreated) {
                return $operation;
            }
            $firstRole = ! $node->roles()->where('status', LifecycleStatus::Active)->exists();
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
            $this->store->change($operation, ['state' => [...($operation->state ?? []),
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
