<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Operations\OperationStepFailed;
use Closure;

/**
 * Runs the named steps of one deployment or rollback as the steps of its operation ({@see OperationDeploymentSteps}).
 * A step that already succeeded on an earlier attempt returns the value it stored instead of running again.
 */
interface DeploymentSteps
{
    /**
     * Runs one step after it reports its phase. The work returns a JSON value that later steps need, or null. A
     * best-effort step that failed throws {@see OperationStepFailed}; the deployment then goes
     * on, and `run()` returns null.
     *
     * @param  Closure(): mixed  $work
     */
    public function run(DeploymentStepName $step, DeploymentRequest $request, Closure $work): mixed;
}
