<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Projects\DevelopmentDeployStep;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Instance;

/** Deploys a development default in its own checkout. */
interface DevelopmentDeployment
{
    /** Fetches the Project's default branch. */
    public function target(Instance $instance): DevelopmentTarget;

    public function checkout(Instance $instance, string $commit, DeploymentRequest $request): void;

    public function executeStep(Instance $instance, DevelopmentDeployStep $step, DeploymentRequest $request): CommandResult;
}
