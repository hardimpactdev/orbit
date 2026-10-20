<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Instances\RemoteProductionDeployment;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Instance;
use Illuminate\Container\Attributes\Bind;

#[Bind(RemoteProductionDeployment::class)]
interface ProductionDeployment
{
    /**
     * Clones a new release at the branch's newest commit, or at `$commit` when one is given. A given commit must be
     * the branch's newest commit or one of its ancestors.
     *
     * @throws ResourceOperationException `deployment.commit_not_on_branch` when the branch does not contain `$commit`
     */
    public function prepare(Instance $instance, string $branch, ?string $commit = null): DeploymentRelease;

    public function executeStep(
        Instance $instance,
        DeploymentRelease $release,
        DeploymentStep $step,
        DeploymentRequest $request,
    ): CommandResult;

    public function activate(Instance $instance, DeploymentRelease $release): DeploymentRelease;

    public function selected(Instance $instance): ?DeploymentRelease;

    public function retained(Instance $instance, string $name): DeploymentRelease;

    public function releases(Instance $instance): DeploymentReleaseState;

    /**
     * Removes the releases beyond DeploymentRelease::RETAINED_PER_HOME. It keeps the selected release, the previous
     * selection, and then the newest others, and also every release a running process of the Instance still uses.
     * `in_use` names the releases kept only because a running process uses them.
     *
     * @return array{removed: list<string>, in_use: list<string>}
     */
    public function prune(Instance $instance, DeploymentRelease $selected, ?DeploymentRelease $previous): array;
}
