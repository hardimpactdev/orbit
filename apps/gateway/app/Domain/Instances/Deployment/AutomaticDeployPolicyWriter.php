<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\NodeRole;

/** Code-owned cutover boundary. Never bind Swamp while the PHP production policy loop is active. */
enum AutomaticDeployPolicyWriter: string
{
    case Gateway = 'gateway';
    case Swamp = 'swamp';

    public const string InactiveRole = 'instance.auto_deploy_role_inactive';

    public function assertSwamp(Instance $instance): void
    {
        if ($this !== self::Swamp) {
            throw new ResourceOperationException('instance.auto_deploy_policy_owner', 'The Gateway PHP runner owns production automatic deployment.', 409);
        }
        $refusal = $this->eligibilityRefusal($instance);
        if ($refusal !== null) {
            throw new ResourceOperationException($refusal, $refusal === self::InactiveRole
                ? 'Automatic deployment requires an active app-prod Node role.'
                : 'Automatic deployment is not enabled for this active production Instance.', 409);
        }
    }

    /** Shared by discovery, admission, and the worker. Ownership is checked separately for cutover probing. */
    public function eligibilityRefusal(Instance $instance): ?string
    {
        $instance->loadMissing('node.roles');
        if (! $instance->node->roles->contains(static fn (NodeRole $role): bool => $role->role === RoleName::AppProd && $role->status === LifecycleStatus::Active)) {
            return self::InactiveRole;
        }

        return $instance->placedOnAppProd() && $instance->auto_deploy && $instance->status === InstanceState::Active
            ? null : AutomaticDeployHistory::Paused;
    }
}
