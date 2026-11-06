<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Domain\Instances\Deployment\DeploymentReleaseState;
use App\Domain\Instances\Deployment\RecordedReleases;
use App\Domain\Instances\InstanceState;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;

/** Lists the releases the Instance's deployment history retains, without contacting its Node. */
final readonly class ListInstanceReleasesAction
{
    public function execute(Instance $instance): DeploymentReleaseState
    {
        $instance->refresh();

        // A development default deploys in its checkout and has no releases.
        if ($instance->placedOnAppDev() && $instance->name === 'default') {
            return new DeploymentReleaseState([], null);
        }

        if (
            ! $instance->placedOnAppProd()
            || $instance->status !== InstanceState::Active
            || $instance->provisioning_step !== 'active'
            || ! $instance->usesProductionReleaseLayout()
            || ! is_string($instance->production_user)
            || ! is_string($instance->production_home)
        ) {
            throw new ResourceOperationException('deployment.identity_invalid', 'The production deployment identity is invalid.', 409);
        }

        return RecordedReleases::state($instance);
    }
}
