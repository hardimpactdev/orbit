<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Domain\Metrics\ExporterDegradationReason;
use App\Domain\Nodes\NodeRoleDependencySet;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Nodes\NodeRoleRemovalOutcome;
use App\Domain\Nodes\NodeSideResidue;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\AwaitOperation;
use App\Domain\Operations\OperationStatus;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Records removal intent; retained Gateway callers await proven Swamp teardown. */
final readonly class RemoveNodeRoleAction
{
    public function __construct(private AdmitNodeRoleOperationAction $admission, private AwaitOperation $await, private NodeSideResidue $residue) {}

    public function start(Node $node, RoleName $role, bool $force = false, bool $purgeData = false, bool $offline = false, bool $deleted = false, ?string $requestId = null): Operation|NodeRoleRemovalOutcome
    {
        return $this->admission->remove($node, $role, $force, $purgeData, $offline, $deleted, $requestId);
    }

    public function execute(Node $node, RoleName $role, bool $force = false, bool $purgeData = false, bool $offline = false, bool $deleted = false): NodeRoleRemovalOutcome
    {
        $operation = $this->start($node, $role, $force, $purgeData, $offline, $deleted);
        if ($operation instanceof NodeRoleRemovalOutcome) {
            return $operation;
        }
        $operation = $this->await->execute($operation);
        if ($operation->status !== OperationStatus::Succeeded) {
            $step = $operation->result['failed_step'] ?? 'operation';
            throw new NodeRoleOperationException(is_string($step) ? $step : 'operation', 'node_role.remove_failed',
                $operation->error_code ?? ($operation->status === OperationStatus::Cancelled ? 'operation.cancelled' : 'node_role.remove_failed'),
                "Role removal did not succeed; follow Operation [{$operation->id}].");
        }
        $dependencies = ValidatedData::object($operation->state['role_dependencies'] ?? []);
        $raw = $operation->state['role_degradation'] ?? null;
        $degradation = is_string($raw) ? ExporterDegradationReason::tryFrom($raw) : null;

        return new NodeRoleRemovalOutcome(new NodeRoleDependencySet(
            ValidatedData::integerList($dependencies['instance_ids'] ?? []), ValidatedData::integerList($dependencies['workspace_ids'] ?? []),
            ValidatedData::integerList($dependencies['process_ids'] ?? []), ValidatedData::stringList($dependencies['summaries'] ?? [])),
            $degradation, $degradation === null ? [] : $this->residue->describe([$role], nodeLeavesFleet: false));
    }
}
