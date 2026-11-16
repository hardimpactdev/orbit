<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Data\Nodes\NodeCiData;
use App\Domain\Instances\DefaultInstanceName;
use App\Domain\Instances\InstanceHostIntent;
use App\Domain\Instances\InstancePlacement;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Illuminate\Database\Eloquent\Builder;

/**
 * The rules of a Node with the `ci-runner` role for proof copies (docs/reference/node-provisioning.md#proof-copies-on-a-ci-node,
 * ADR 0260 build steps 3 to 5): which Node hosts them, and how many of its CI places are free. For the first two, a `ci-runner` role
 * that is being added or converged counts, so its own converge prepares the Node; one that is being removed does not.
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

    /** The Node's CI places: its `ci` setting (docs/reference/node-settings.md#ci-places, ADR 0260), 0 while the Node has none. */
    public static function places(Node $node): int
    {
        return app(NodeSettingsNormalizer::class)->fromStored($node->settings)?->ci()->places ?? 0;
    }

    /**
     * The runner jobs that take a place on the Node: its busy runners, or none on a proof-only Node, whose places serve proof copies only.
     */
    public static function runnerJobs(?NodeCiData $ci, int $busyRunners): int
    {
        return $ci?->proof_only === true ? 0 : $busyRunners;
    }

    /**
     * The Node's free CI places (docs/cli/proof/run.mdx#the-run): its `ci.places` minus its busy runners minus the proof copies already
     * on it, of any proof. A task copy takes no place. The runner view shows this count, and proof:run places one copy in each.
     */
    public static function freePlaces(NodeCiData $ci, int $busyRunners, int $proofCopies): int
    {
        return max(0, $ci->places - self::runnerJobs($ci, $busyRunners) - $proofCopies);
    }

    /** The copies that the Node's available memory holds, each at one copy's peak (`proof.copy_memory`). */
    public static function memoryPlaces(int $availableBytes): int
    {
        return intdiv(max(0, $availableBytes), app(ProofSettings::class)->copyMemory());
    }

    /**
     * The live proof copies: each copy in the nested Incus project whose task group a proof run owns (ProofGroup), also one that is
     * failing or being destroyed.
     *
     * @return Builder<Instance>
     */
    public static function copies(): Builder
    {
        return Instance::query()->whereNotNull('task_workspace_owner_id')
            ->whereHas('runtime', static fn ($runtime) => $runtime->where('incus_project', InstancePlacement::NestedProject))
            ->whereIn('task_workspace_owner_id', Operation::query()->select('target_id')->where('kind', ProofGroup::OwnerKind)->where('target_type', 'task_group'));
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
