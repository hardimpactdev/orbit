<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Validates safe family input and projects the bounded native outcome. */
final readonly class InstanceWebRootsProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct, private OperationExecutionContract $contract, private InstanceServedWebRootsInput $input, private AgentDelivery $agents) {}

    public function input(Operation $operation): array
    {
        $this->assertOperation($operation);
        $input = $this->input->forOperation($operation);

        return ['instance_id' => $operation->target_id, 'agent_sha256' => $input === null ? null : $this->agents->pin(Node::query()->findOrFail($operation->node_id)), 'agent_input' => $input];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $this->assertOperation($operation);
        $result = $this->contract->result($operation, ValidatedData::object($data['result'] ?? []));
        $status = $data['status'];
        $code = $data['error_code'] ?? null;
        if (($status === 'succeeded' && ($result !== ['instance_id' => $operation->target_id, 'confirmed' => true, 'no_op' => false]
                && $result !== ['instance_id' => $operation->target_id, 'confirmed' => true, 'no_op' => true]))
            || ($status === 'failed' && ($code === null || $result !== ['error_code' => $code]))
            || (in_array($status, ['running', 'cancelled'], true) && $result !== [])
            || ($status !== 'failed' && $code !== null)) {
            throw new ResourceOperationException('operation.result_invalid', 'The web-root result does not match the native outcome.', 422);
        }

        return $this->direct->project($operation, $data, $result, 'web_roots');
    }

    private function assertOperation(Operation $operation): void
    {
        if ($operation->kind !== 'instance.prepare_served_web_roots' || ! DirectOperation::owns($operation)
            || $operation->target_type !== 'instance' || $operation->node_id === null) {
            throw new ResourceOperationException('operation.execution_forbidden', 'A direct Instance web-root intent is required.', 422);
        }
    }
}
