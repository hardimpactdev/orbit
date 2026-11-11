<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;

/** The container identity of the saved removal; no execution. */
final readonly class ContainerInstanceDestroy
{
    public function assertAvailable(Instance $instance, bool $force): void
    {
        $runtime = $instance->runtime()->first();
        if ($runtime === null) {
            return;
        }
        if ($instance->name === 'default') {
            throw new ResourceOperationException('operation.kind_unavailable', 'Container default destruction is not available yet.', 503);
        }
        if (! $force) {
            throw new ResourceOperationException('instance.container_destroy_force_required', 'Non-default container removal currently requires --force. Guarded removal follows separately.', 409);
        }
    }

    /** @return array<string, mixed>|null */
    public function capture(Instance $instance): ?array
    {
        $runtime = $instance->runtime()->first();
        if ($runtime === null) {
            return null;
        }

        return ['instance_id' => $instance->id, 'project' => $runtime->incus_project, 'name' => $runtime->container_name,
            'acl' => $runtime->container_name, 'pool' => InstanceHostIntent::Pool,
            'mirror' => InstanceContainer::mirror($instance), 'firewall_prefix' => InstanceContainer::firewallPrefix($instance)];
    }
}
