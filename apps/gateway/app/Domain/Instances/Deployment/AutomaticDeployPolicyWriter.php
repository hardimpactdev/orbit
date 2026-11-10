<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Instances\InstanceState;
use App\Domain\Instances\ReadyContainerDefault;
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

    public const string ContainerDefaultRequired = 'instance.auto_deploy_container_default_required';

    public function assertSwamp(Instance $instance, bool $development = false): void
    {
        if ($this !== self::Swamp) {
            throw new ResourceOperationException('instance.auto_deploy_policy_owner', 'The Gateway PHP runner owns automatic deployment.', 409);
        }
        $refusal = $development ? $this->developmentEligibilityRefusal($instance) : $this->eligibilityRefusal($instance);
        if ($refusal !== null) {
            throw new ResourceOperationException($refusal, match ($refusal) {
                self::InactiveRole => 'Automatic deployment requires eligible active app-dev or app-prod placement.',
                self::ContainerDefaultRequired => 'Automatic development deployment requires an active container default with a seed snapshot. Checkout Instances are working trees and are never auto-deployed.',
                default => 'Automatic deployment is not enabled for this active Instance.',
            }, 409);
        }
    }

    /** The development policy must never inherit production's ungated eligibility. */
    public function developmentEligibilityRefusal(Instance $instance): ?string
    {
        if (! $instance->placedOnAppDev() || ! ReadyContainerDefault::query()->whereKey($instance->id)->exists()) {
            return self::ContainerDefaultRequired;
        }

        return $instance->node->status === LifecycleStatus::Active
            && $instance->node->roles->contains(static fn (NodeRole $role): bool => $role->role === RoleName::AppDev && $role->status === LifecycleStatus::Active)
                ? null : self::InactiveRole;
    }

    /** Shared by discovery, admission, and the worker. Ownership is checked separately for cutover probing. */
    public function eligibilityRefusal(Instance $instance): ?string
    {
        if ($instance->placedOnAppDev()) {
            return $this->developmentEligibilityRefusal($instance);
        }
        $instance->loadMissing('node.roles');
        if (! $instance->node->roles->contains(static fn (NodeRole $role): bool => $role->role === RoleName::AppProd && $role->status === LifecycleStatus::Active)) {
            return self::InactiveRole;
        }

        return $instance->placedOnAppProd() && $instance->auto_deploy && $instance->status === InstanceState::Active
            ? null : AutomaticDeployHistory::Paused;
    }
}
