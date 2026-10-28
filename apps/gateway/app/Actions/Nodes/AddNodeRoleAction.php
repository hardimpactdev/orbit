<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Domain\Analytics\AnalyticsRoleSettings;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\RoleRegistry;
use App\Domain\Operations\AwaitOperation;
use App\Domain\Operations\OperationStatus;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Retained Gateway callers admit a role Operation. Swamp owns every role effect. */
final readonly class AddNodeRoleAction
{
    public function __construct(private AdmitNodeRoleOperationAction $admission, private AwaitOperation $await,
        private AssignRoleAction $assign, private RoleRegistry $roles) {}

    public function start(Node $node, RoleName $role, bool $convergeExisting = false, ?AnalyticsRoleSettings $analytics = null, ?string $requestId = null): Operation
    {
        return $this->admission->add($node, $role, $convergeExisting, $analytics, $requestId);
    }

    /** @return array{assignment: NodeRole, created: bool, follow_up: ?string} */
    public function execute(Node $node, RoleName $role, bool $convergeExisting = false, ?AnalyticsRoleSettings $analytics = null): array
    {
        return $this->result($this->await->execute($this->start($node, $role, $convergeExisting, $analytics)));
    }

    public function executeDuringProvisioning(Node $node, RoleName $role): NodeRole
    {
        $operation = $this->admission->add($node, $role, true, duringProvisioning: true);

        return $this->result($this->await->execute($operation))['assignment'];
    }

    /** @param list<RoleName> $prospectiveRoles */
    public function preflightDuringProvisioning(Node $node, RoleName $role, array $prospectiveRoles): void
    {
        if (! $this->roles->definition($role)->assignableDuringProvisioning) {
            throw new RoleAssignmentException("Role [{$role->value}] cannot be assigned during provisioning.");
        }
        $this->assign->preflight($node, $role, $prospectiveRoles);
    }

    /** @return array{assignment: NodeRole, created: bool, follow_up: ?string} */
    private function result(Operation $operation): array
    {
        if ($operation->status !== OperationStatus::Succeeded) {
            $step = $operation->result['failed_step'] ?? 'operation';
            throw new NodeRoleOperationException(is_string($step) ? $step : 'operation', 'node_role.convergence_failed',
                $operation->error_code ?? ($operation->status === OperationStatus::Cancelled ? 'operation.cancelled' : 'node_role.convergence_failed'),
                "Role convergence did not succeed; follow Operation [{$operation->id}].");
        }
        $intent = ValidatedData::object($operation->state['role_intent'] ?? []);
        $created = $intent['created'] ?? null;
        if (! is_bool($created)) {
            throw new \LogicException('The role Operation has no retained creation intent.');
        }

        return ['assignment' => NodeRole::query()->findOrFail(ValidatedData::integer($intent['assignment_id'])),
            'created' => $created, 'follow_up' => is_string($operation->result['follow_up'] ?? null) ? $operation->result['follow_up'] : null];
    }
}
