<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Domain\Nodes\NodeRoleFollowUpReport;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\AwaitOperation;
use App\Domain\Operations\OperationStatus;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Relocation records the original assignment and Settings scopes; Swamp coordinates both Nodes. */
final readonly class RelocateNodeRoleAction
{
    public function __construct(private AdmitNodeRoleOperationAction $admission, private AwaitOperation $await, private NodeRoleFollowUpReport $followUps) {}

    public function start(Node $target, RoleName $role, bool $force = false, ?Node $from = null, ?string $requestId = null): Operation
    {
        return $this->admission->relocate($target, $role, $force, $from, $requestId);
    }

    public function execute(Node $target, RoleName $role, bool $force = false, ?Node $from = null): NodeRole
    {
        $operation = $this->await->execute($this->start($target, $role, $force, $from));
        if ($operation->status !== OperationStatus::Succeeded) {
            $step = $operation->result['failed_step'] ?? 'operation';
            throw new NodeRoleOperationException(is_string($step) ? $step : 'operation', 'node_role.relocate_failed',
                $operation->error_code ?? ($operation->status === OperationStatus::Cancelled ? 'operation.cancelled' : 'node_role.relocate_failed'),
                "Role relocation did not succeed; follow Operation [{$operation->id}].");
        }
        $followUp = $operation->result['follow_up'] ?? null;
        if (is_string($followUp)) {
            $this->followUps->record($followUp);
        }
        $intent = ValidatedData::object($operation->state['role_intent'] ?? []);

        return NodeRole::query()->findOrFail(ValidatedData::integer($intent['assignment_id']));
    }
}
