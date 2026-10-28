<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Instances\InstanceState;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;

/** Code-owned cutover boundary. Never bind Swamp while the PHP production policy loop is active. */
enum AutomaticDeployPolicyWriter: string
{
    case Gateway = 'gateway';
    case Swamp = 'swamp';

    public function assertSwamp(Instance $instance): void
    {
        if ($this !== self::Swamp) {
            throw new ResourceOperationException('instance.auto_deploy_policy_owner', 'The Gateway PHP runner owns production automatic deployment.', 409);
        }
        if (! $instance->placedOnAppProd() || ! $instance->auto_deploy || $instance->status !== InstanceState::Active) {
            throw new ResourceOperationException('deployment.paused', 'Automatic deployment is not enabled for this active production Instance.', 409);
        }
    }
}
