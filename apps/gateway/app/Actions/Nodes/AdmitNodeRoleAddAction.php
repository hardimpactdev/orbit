<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Analytics\AnalyticsRoleSettings;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Nodes\NativeNodeRoleConvergeInput;
use App\Domain\Nodes\NodeRoleParentInput;
use App\Domain\Nodes\NodeRoleIntent;
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\RoleRegistry;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

/** Records the real assignment and immutable parent intent before one native start. */
final readonly class AdmitNodeRoleAddAction
{
    public function __construct(private StartOperationAction $operations, private OperationStore $store,
        private OperationIdempotency $idempotency, private AssignRoleAction $assign, private RoleRegistry $roles,
        private NodeRoleParentInput $input) {}

    public function execute(Node $node, RoleName $role, ?AnalyticsRoleSettings $analytics = null, ?string $requestId = null, bool $duringProvisioning = false): Operation
    {
        if (($existing = $this->idempotency->replay('node.role_add')) !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($node, $role, $requestId, $duringProvisioning): Operation {
            $node = Node::query()->lockForUpdate()->findOrFail($node->id);
            if (! $this->roles->definition($role)->mutable) {
                throw new RoleAssignmentException("Role [{$role->value}] is protected from generic mutation.");
            }
            if ($duringProvisioning || ! app(ManagedNodeEligibility::class)->allows($node)
                || ! NativeNodeRoleConvergeInput::supportsContribution($node, $role)) {
                throw new ResourceOperationException('operation.kind_unavailable', 'This role requires its implemented native contribution on209.', 422);
            }
            $this->assign->preflight($node, $role);
            $existing = $node->roles()->where('role', $role)->first();
            if ($existing !== null && ! ($role === RoleName::Ingress && $existing->status === LifecycleStatus::Active)) {
                throw new RoleAssignmentException("Role [{$role->value}] is already assigned; explicit convergence is required.");
            }
            $operation = $this->operations->execute('node.role_add', 'node', $node->id, $node->id,
                ['node_id' => $node->id, 'role' => $role->value, 'converge' => false], 'operator', $requestId,
                lockKey: OperationStore::lockKey('node', $node->id));
            if (! $operation->wasRecentlyCreated) {
                return $operation;
            }
            if (! is_string($node->agent_secret_hash) || preg_match('/\A[a-f0-9]{64}\z/D', $node->agent_secret_hash) !== 1) {
                throw new ResourceOperationException('node.agent_rekey_required', 'The role needs the existing recorded Node agent identity.', 409);
            }
            $assignment = $this->assign->execute($node, $role);
            $created = $assignment->wasRecentlyCreated;
            if (! $created) {
                $assignment->claimConvergence();
            }
            $captured = $this->input->target($node, $assignment, $created);
            $copies = $this->input->executionCopies($operation, $node, $captured['snapshot']);
            $this->store->change($operation, ['input' => [...$operation->input,
                '_role' => $captured['snapshot'], 'native_role' => $captured['intent'],
                'intent' => ['target' => $captured['intent']], 'execution_secrets' => $copies,
                'participant_node_ids' => $captured['participant_node_ids']],
                'state' => [...$operation->state, 'role_intent' => NodeRoleIntent::capture($assignment, $created)]]);

            return $operation;
        });
    }
}
