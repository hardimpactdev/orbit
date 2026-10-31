<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Operations\DirectOperationProjection;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Rollback shares bounded history projection; the retained-release decision belongs to Swamp. */
final readonly class InstanceRollbackProjection implements DirectOperationProjection
{
    public function __construct(private InstanceDeployProjection $deployment) {}

    public function input(Operation $operation): array
    {
        return [...$this->deployment->input($operation), 'release' => ValidatedData::string($operation->input['release'])];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return $this->deployment->apply($operation, $data);
    }
}
