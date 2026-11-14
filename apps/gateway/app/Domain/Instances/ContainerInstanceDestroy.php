<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\InstanceMigration\InstanceMigration;
use App\Domain\Instances\Power\InstancePower;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceRuntime;

/** The container identity of the saved removal; no execution. */
final readonly class ContainerInstanceDestroy
{
    public function assertAvailable(Instance $instance, bool $force): void
    {
        InstanceMigration::assertSettled($instance);
        $runtime = $instance->runtime()->first();
        if ($runtime === null) {
            return;
        }
        if ($instance->name === DefaultInstanceName::Name) {
            $this->assertRemovableDefault($instance);
        }
        if (! $force) {
            throw new ResourceOperationException('instance.container_destroy_force_required', 'Non-default container removal currently requires --force. Guarded removal follows separately.', 409);
        }
    }

    /**
     * A container default goes only when both hold (docs/reference/development-containers.md#destroy): its Project is on the operator
     * list, and no Instance copies it, whose seed snapshot it holds. The list alone names a trial default; the first nested-fleet
     * default's Project is never listed.
     */
    private function assertRemovableDefault(Instance $instance): void
    {
        $listed = config('instances.default_destroy_projects');
        if (! is_array($listed) || ! array_is_list($listed) || ! array_all($listed, static fn (mixed $id): bool => is_int($id) && $id > 0)) {
            throw new ResourceOperationException('instance.default_destroy_projects_invalid', 'ORBIT_INSTANCES_DEFAULT_DESTROY_PROJECTS must be a JSON list of positive integer Project IDs.', 500);
        }
        if (! in_array($instance->project_id, $listed, true)) {
            throw new ResourceOperationException('operation.kind_unavailable', 'Container default destruction is not available for this default.', 503);
        }
        if (InstanceRuntime::query()->where('clone_source_instance_id', $instance->id)->exists()) {
            throw new ResourceOperationException('instance.default_has_copies', 'Remove the Instances that copy this default first.', 409);
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
            'mirror' => InstanceContainer::mirror($instance), 'firewall_prefix' => InstanceContainer::firewallPrefix($instance),
            // A sleeping Instance leaves its marker behind on the Node; no later Instance or domain may find it.
            'asleep_marker' => InstancePower::markerPath($instance),
            // The removal deletes an inner volume with its snapshots, after the container.
            ...($runtime->inner_volume === null ? [] : ['inner_volume' => $runtime->inner_volume])];
    }
}
