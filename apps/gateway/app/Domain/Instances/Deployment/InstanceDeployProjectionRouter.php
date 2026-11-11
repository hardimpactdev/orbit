<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationStartRefusal;
use App\Domain\Operations\DirectOperationTimeout;
use App\Domain\Operations\DirectOperationWorkflowInput;
use App\Domain\Operations\NativeOperationProjection;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Select once at the boundary: development policy facts never enter the production projector. */
final readonly class InstanceDeployProjectionRouter implements DirectOperationProjection, DirectOperationStartRefusal, DirectOperationTimeout, DirectOperationWorkflowInput, NativeOperationProjection
{
    public function native(Operation $operation): bool
    {
        return $this->variant($operation)->native($operation);
    }

    public function workflowInput(Operation $operation): array
    {
        return $this->variant($operation)->workflowInput($operation);
    }

    public function input(Operation $operation): array
    {
        return $this->variant($operation)->input($operation);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return $this->variant($operation)->apply($operation, $data);
    }

    public function refused(Operation $operation): void
    {
        $this->variant($operation)->refused($operation);
    }

    public function timedOut(Operation $operation): void
    {
        $this->variant($operation)->timedOut($operation);
    }

    private function variant(Operation $operation): InstanceDeployProjection|DevelopmentInstanceDeployProjection
    {
        $variant = ValidatedData::string(ValidatedData::object($operation->input['deployment'])['variant']);

        return app(InstanceDeploymentIntent::development($variant) ? DevelopmentInstanceDeployProjection::class : InstanceDeployProjection::class);
    }
}
