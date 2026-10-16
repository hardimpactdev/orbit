<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Operations\OperationStepFailed;
use Closure;

/**
 * Runs every step of a deployment or a rollback directly, outside an operation: no step is skipped, and no cancel request
 * stops one. Tests use it to run a deployment without the Gateway queue; Orbit itself runs every deployment and rollback
 * through {@see OperationDeploymentSteps}.
 */
final readonly class InlineDeploymentSteps implements DeploymentSteps
{
    public function run(DeploymentStepName $step, DeploymentRequest $request, Closure $work): mixed
    {
        $request->emitPhase($step->phase, $step->stepName);

        try {
            return $work();
        } catch (OperationStepFailed) {
            return null;
        }
    }
}
