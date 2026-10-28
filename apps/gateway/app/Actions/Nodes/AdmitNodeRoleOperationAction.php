<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Analytics\AnalyticsRoleSettings;
use App\Domain\Analytics\AnalyticsRoleSettingsRepository;
use App\Domain\Analytics\AnalyticsStorageProcessGuard;
use App\Domain\Nodes\NodeRoleIntent;
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\RoleRegistry;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
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
}
