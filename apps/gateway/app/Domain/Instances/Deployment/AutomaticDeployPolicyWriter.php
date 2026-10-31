<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\NodeRole;

/** Code-owned cutover boundary. Never bind Swamp while the PHP policy loop is active. */
enum AutomaticDeployPolicyWriter: string
{
    case Gateway = 'gateway';
    case Swamp = 'swamp';

    public const string InactiveRole = 'instance.auto_deploy_role_inactive';

    public function assertSwamp(Instance $instance): void
    {
        if ($this !== self::Swamp) {
            throw new ResourceOperationException('instance.auto_deploy_policy_owner', 'The Gateway PHP runner owns automatic deployment.', 409);
        }
        $refusal = $this->eligibilityRefusal($instance);
        if ($refusal !== null) {
            throw new ResourceOperationException($refusal, $refusal === self::InactiveRole
                ? 'Automatic deployment requires eligible active app-dev or app-prod placement.'
                : 'Automatic deployment is not enabled for this active Instance.', 409);
        }
    }

    /** Shared by discovery, admission, and the worker. Ownership is checked separately for cutover probing. */
    public function eligibilityRefusal(Instance $instance): ?string
    {
        if ($instance->placedOnAppDev()) {
            return $instance->name === 'default' && $instance->status === InstanceState::Active
                && $instance->source_layout === InstanceSourceLayout::Checkout->value
                && $instance->node->roles->contains(static fn (NodeRole $role): bool => $role->role === RoleName::AppDev && $role->status === LifecycleStatus::Active)
                    ? null : self::InactiveRole;
        }
        if (AutomaticDeployState::of($instance)->smokeHeld()) {
            return 'instance.smoke_held';
        }
        $instance->loadMissing('node.roles');
        if (! $instance->node->roles->contains(static fn (NodeRole $role): bool => $role->role === RoleName::AppProd && $role->status === LifecycleStatus::Active)) {
            return self::InactiveRole;
        }

        return $instance->placedOnAppProd() && $instance->auto_deploy && $instance->status === InstanceState::Active
            ? null : AutomaticDeployHistory::Paused;
    }
}
