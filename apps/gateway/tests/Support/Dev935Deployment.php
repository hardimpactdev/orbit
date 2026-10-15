<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\Deployment\DeploymentEvent;
use App\Domain\Instances\Deployment\DeploymentOutputStream;
use App\Domain\Instances\Deployment\DeploymentRequest;
use App\Domain\Instances\Deployment\DevelopmentDeployment;
use App\Domain\Instances\Deployment\DevelopmentTarget;
use App\Domain\Projects\DevelopmentDeployStep;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Instance;
use Throwable;

/** A development default's remote checkout that records each call and fails where a test asks. */
final class Dev935Deployment implements DevelopmentDeployment
{
    /** @var list<string> */
    public array $trace = [];

    public string $targetCommit;

    public ?string $pushDuringCheckout = null;

    public ?string $failedStep = null;

    public bool $noisyFailure = false;

    public ?Throwable $checkoutFailure = null;

    public function __construct()
    {
        $this->targetCommit = str_repeat('b', 40);
    }

    /** A commit the fetched branch does not contain. */
    public ?string $notOnBranch = null;

    public function target(Instance $instance, ?string $commit = null): DevelopmentTarget
    {
        $this->trace[] = 'target:'.$instance->project->default_branch.($commit === null ? '' : '@'.$commit);
        if ($commit !== null && $commit === $this->notOnBranch) {
            throw new ResourceOperationException('deployment.commit_not_on_branch', 'Injected refusal.', 409);
        }

        return new DevelopmentTarget($commit ?? $this->targetCommit);
    }

    public function checkout(Instance $instance, string $commit, DeploymentRequest $request): void
    {
        $this->trace[] = 'checkout:'.$commit;
        if ($this->checkoutFailure !== null) {
            throw $this->checkoutFailure;
        }
        if ($this->pushDuringCheckout !== null) {
            $this->targetCommit = $this->pushDuringCheckout;
            $this->pushDuringCheckout = null;
        }
    }

    public function executeStep(Instance $instance, DevelopmentDeployStep $step, DeploymentRequest $request): CommandResult
    {
        $this->trace[] = 'step:'.$step->name;
        if ($step->name === $this->failedStep) {
            if ($this->noisyFailure) {
                for ($i = 0; $i < 12; $i++) {
                    $request->emit(new DeploymentEvent($step->name, DeploymentOutputStream::Stderr, str_repeat('x', 16_384)));
                }
            }
            throw new RuntimeConvergenceException('deployment-step-'.$step->name, 'deployment.step_failed', 'Failed step.', result: new CommandResult(42, '', 'failure', 1, false));
        }

        return new CommandResult(0, '', '', 1, false);
    }
}
