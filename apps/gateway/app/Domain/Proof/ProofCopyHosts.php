<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Domain\Instances\DefaultInstanceName;
use App\Domain\Instances\InstanceHostIntent;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\NodeRole;

/**
 * The one rule of a CI Node that hosts proof copies (docs/reference/node-provisioning.md#proof-copies-on-a-ci-node, ADR 0260 build
 * step 3): the Node has the `ci-runner` role, at least one CI place and no `app-dev` role. A `ci-runner` role that is being added or
 * converged counts, so its own converge prepares the Node; one that is being removed does not. Such a Node runs the Instance
 * container branch through its `ci-runner` converge (InstanceHostIntent::role) and takes no Instance but a proof copy.
 */
final readonly class ProofCopyHosts
{
    public static function includes(Node $node): bool
    {
        if (self::places($node) < 1) {
            return false;
        }
        $roles = NodeRole::query()->where('node_id', $node->id)->get(['role', 'status']);

        return $roles->contains(static fn (NodeRole $role): bool => $role->role === RoleName::CiRunner && $role->status !== LifecycleStatus::Removing)
            && ! $roles->contains(static fn (NodeRole $role): bool => $role->role === RoleName::AppDev);
    }

    /** The Node's CI places: its `ci.places` setting (docs/reference/node-settings.md, ADR 0260), 0 while the Node has none. */
    public static function places(Node $node): int
    {
        $ci = $node->settings['ci'] ?? null;
        $places = is_array($ci) ? ($ci['places'] ?? 0) : 0;

        return is_int($places) ? $places : 0;
    }

    /**
     * The ID map size of every proof copy and seed holder on such a Node: the one the proof Project's default records, or a new nested
     * default's size while that Project has none (docs/reference/node-settings.md#a-ci-node).
     */
    public static function idMapSize(): int
    {
        $default = Instance::query()->where('project_id', app(ProofSettings::class)->projectId())->where('name', DefaultInstanceName::Name)->value('id');
        $size = $default === null ? null : InstanceRuntime::query()->where('instance_id', $default)->value('idmap_size');

        return is_int($size) ? $size : InstanceHostIntent::TaskPlaceIds;
    }
}
