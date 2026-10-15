<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Operations\OperationCancelled;
use App\Domain\Operations\OperationRun;
use App\Infrastructure\Processes\ProcessCancelledException;
use Closure;

/**
 * Runs the steps of a deployment as the steps of its operation. A step that succeeded on an earlier attempt is
 * skipped, and its stored value comes back. After each step that ran, `$saved` keeps the deployment's events, so a
 * resumed attempt keeps them too. Once activation succeeded, the new release is live, so a cancel request no longer
 * stops the deployment: its PHP refresh, after-activation steps, and cleanup still run.
 */
final readonly class OperationDeploymentSteps implements DeploymentSteps
{
    /** @param Closure(): void $saved */
    public function __construct(
        private OperationRun $run,
        private Closure $saved,
    ) {}

    public function run(DeploymentStepName $step, DeploymentRequest $request, Closure $work): mixed
    {
        $cancellable = ! $this->run->succeeded(DeploymentProgressPhase::Activation->value);

        try {
            return $this->run->step($step->name(), function () use ($step, $request, $work): mixed {
                $request->emitPhase($step->phase, $step->stepName);

                try {
                    return $work();
                } finally {
                    ($this->saved)();
                }
            }, $cancellable);
        } catch (OperationCancelled) {
            // The deployment reports a cancel as `deployment.cancelled`, as it did when a stream closed.
            throw new ProcessCancelledException;
        }
    }
}
