<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Projects\DevelopmentDeployStep;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Instances\RemoteDevelopmentDeployment;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Instance;
use Illuminate\Container\Attributes\Bind;

/** Deploys a development default in its own checkout. */
#[Bind(RemoteDevelopmentDeployment::class)]
interface DevelopmentDeployment
{
    /**
     * Fetches the Project's default branch. Its target is the branch's newest commit, or `$commit` when the fetched
     * branch contains it.
     *
     * @throws ResourceOperationException `deployment.commit_not_on_branch` when the branch does not contain `$commit`
     */
    public function target(Instance $instance, ?string $commit = null): DevelopmentTarget;

    public function checkout(Instance $instance, string $commit, DeploymentRequest $request): void;

    public function executeStep(Instance $instance, DevelopmentDeployStep $step, DeploymentRequest $request): CommandResult;
}
