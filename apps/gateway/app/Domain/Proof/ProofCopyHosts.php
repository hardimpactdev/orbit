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
 * The two rules of a Node with the `ci-runner` role for proof copies (docs/reference/node-provisioning.md#proof-copies-on-a-ci-node,
 * ADR 0260 build steps 3 and 4). For both, a `ci-runner` role that is being added or converged counts, so its own converge prepares
 * the Node; one that is being removed does not.
 */
final readonly class ProofCopyHosts
{
    /**
     * A CI-only Node: `ci-runner`, at least one CI place and no `app-dev`. Its `ci-runner` converge runs the Instance container branch
     * (InstanceHostIntent::role), and it takes no Instance but a proof copy (InstancePlacement::assert).
     */
    public static function ciOnly(Node $node): bool
    {
        return self::offersPlaces($node)
            && ! NodeRole::query()->where('node_id', $node->id)->where('role', RoleName::AppDev)->exists();
    }

    /**
     * The Node offers proof places: `ci-runner` and at least one CI place, with or without `app-dev`. A Node with both, such as the
     * proof default's own Node, hosts containers through `app-dev`. Every such Node but the default's keeps a seed of the default.
     */
    public static function offersPlaces(Node $node): bool
    {
        return self::places($node) > 0 && NodeRole::query()->where('node_id', $node->id)->where('role', RoleName::CiRunner)
            ->where('status', '!=', LifecycleStatus::Removing)->exists();
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
