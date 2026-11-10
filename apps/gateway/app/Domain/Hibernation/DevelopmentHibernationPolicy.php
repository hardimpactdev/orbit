<?php

declare(strict_types=1);

namespace App\Domain\Hibernation;

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;

final readonly class DevelopmentHibernationPolicy
{
    public function appliesToInstance(Instance $instance): bool
    {
        $instance->loadMissing('node.roles');

        // A container Instance sleeps as a whole container, which a later part of the runtime builds; its units are not the Node's.
        return $this->hasActiveAppDevRole($instance->node) && ! $instance->runtime()->exists();
    }

    public function appliesToProcess(Process $process): bool
    {
        if (! Instance::isMorphType($process->owner_type)) {
            return false;
        }

        $owner = $process->owner;

        return $owner instanceof Instance && $this->appliesToInstance($owner);
    }

    public function usesOnDemandHostStart(Instance $instance): bool
    {
        return $this->appliesToInstance($instance);
    }

    public function hasActiveAppDevRole(Node $node): bool
    {
        return $node->roles()
            ->where('role', RoleName::AppDev)
            ->where('status', LifecycleStatus::Active)
            ->exists();
    }
}
