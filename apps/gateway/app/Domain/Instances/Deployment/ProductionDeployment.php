<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Models\Instance;

/** Read-only release queries. Swamp owns preparation, activation and cleanup. */
interface ProductionDeployment
{
    public function selected(Instance $instance): ?DeploymentRelease;

    public function retained(Instance $instance, string $name): DeploymentRelease;

    public function releases(Instance $instance): DeploymentReleaseState;
}
