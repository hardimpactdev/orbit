<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Operations\OperationStepFailed;
use Closure;

/** Runs every step of a deployment at once, as a request or the scheduled development deploy does. */
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
