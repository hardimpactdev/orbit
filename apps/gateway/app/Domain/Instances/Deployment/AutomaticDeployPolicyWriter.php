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

    public function assertSwamp(Instance $instance): void
    {
        if ($this !== self::Swamp) {
            throw new ResourceOperationException('instance.auto_deploy_policy_owner', 'The Gateway PHP runner owns automatic deployment.', 409);
        }
        if ($instance->placedOnAppDev()) {
            throw new ResourceOperationException('instance.auto_deploy_policy_owner', 'The Gateway owns development automatic deployment.', 409);
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
            return $this->developmentEligibilityRefusal($instance);
        }
        $instance->loadMissing('node.roles');
        if (! $instance->node->roles->contains(static fn (NodeRole $role): bool => $role->role === RoleName::AppProd && $role->status === LifecycleStatus::Active)) {
            return self::InactiveRole;
        }

        return $instance->placedOnAppProd() && $instance->auto_deploy && $instance->status === InstanceState::Active
            ? null : AutomaticDeployHistory::Paused;
    }

    public const string ContainerDefaultRequired = 'instance.auto_deploy_container_default_required';

    public function assertDevelopmentConfigurable(Instance $instance): void
    {
        if (($refusal = $this->developmentEligibilityRefusal($instance, requireEnabled: false)) !== null) {
            throw new ResourceOperationException($refusal, 'Development automatic deployment requires a ready container default on an active app-dev Node.', 409);
        }
    }

    public function developmentEligibilityRefusal(Instance $instance, bool $requireEnabled = true): ?string
    {
        if (! $instance->placedOnAppDev() || ! ReadyContainerDefault::query()->whereKey($instance->id)->exists()) {
            return self::ContainerDefaultRequired;
        }
        if ($instance->node->status !== LifecycleStatus::Active
            || ! $instance->node->roles->contains(static fn (NodeRole $role): bool => $role->role === RoleName::AppDev && $role->status === LifecycleStatus::Active)) {
            return self::InactiveRole;
        }

        return ! $requireEnabled || $instance->auto_deploy ? null : AutomaticDeployHistory::Paused;
    }
}
