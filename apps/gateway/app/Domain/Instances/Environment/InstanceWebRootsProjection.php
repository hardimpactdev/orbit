<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Validates safe family input and projects the bounded native outcome. */
final readonly class InstanceWebRootsProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct, private OperationExecutionContract $contract, private DirectInstanceWebRoots $roots) {}

    public function input(Operation $operation): array
    {
        $this->roots->assertOperation($operation);

        return ['instance_id' => $operation->target_id];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $this->roots->assertOperation($operation);
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
}
