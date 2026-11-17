<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Data\Nodes\NodeCiData;
use App\Domain\Instances\DefaultInstanceName;
use App\Domain\Instances\InstanceHostIntent;
use App\Domain\Instances\InstancePlacement;
use App\Domain\Nodes\CiRunnerStateFiles;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\ProofWarmCopy;
use App\Support\ValidatedData;
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

    /**
     * The Node takes proof copies (docs/reference/node-provisioning.md#proof-places-on-a-ci-node): it offers proof places, it records the
     * hold script that this release installs, and it hosts nested fleets (InstancePlacement::hostsNestedFleets), so a copy's placement passes.
     * After a release that adds or changes the script, the Node takes none until a `ci-runner` add or converge of it passes. The converge
     * itself reads offersPlaces, not this, so it still prepares the Node.
     */
    public static function takesCopies(Node $node): bool
    {
        return $node->ci_hold_digest === self::holdDigest() && self::offersPlaces($node) && InstancePlacement::hostsNestedFleets($node);
    }

    /** The SHA-256 digest of the hold script that this release's `ci-runner` role installs. */
    public static function holdDigest(): string
    {
        return hash('sha256', CiRunnerStateFiles::holdScript());
    }

    /**
     * Records what a `ci-runner` converge prepared on a CI Node, at the end of each run that writes it: a `ci-runner` add or role converge,
     * and a `node:converge` with the role, which passes here when its roles phase passed. Two records follow this one rule
     * (docs/reference/node-provisioning.md#proof-places-on-a-ci-node): the digest of the hold script (`ci_hold_digest`), and whether the
     * intent carried the nested branch of the Instance container hosting (`ci_nested_hosting`). A passed add or role converge (`$sets`)
     * sets both from its intent. A `node:converge` keeps each only when it already matches its intent. Any other end clears both: the run
     * may have changed the Node.
     *
     * @param  array<string, mixed>  $runtime  the run's role runtime intent
     */
    public static function recordConverge(int $nodeId, array $runtime, bool $passed, bool $sets): void
    {
        $files = array_map(ValidatedData::object(...), is_array($runtime['files'] ?? null) ? $runtime['files'] : []);
        $script = array_find($files, static fn (array $file): bool => ($file['path'] ?? null) === CiRunnerStateFiles::Hold)['content'] ?? null;
        $digest = $passed && is_string($script) ? hash('sha256', $script) : null;
        $nested = $passed && is_array(ValidatedData::object($runtime['instances'] ?? [])['nested'] ?? null);
        if ($sets) {
            Node::query()->whereKey($nodeId)->update(['ci_hold_digest' => $digest, 'ci_nested_hosting' => $nested]);

            return;
        }
        Node::query()->whereKey($nodeId)->where(static fn (Builder $kept): Builder => $kept->whereNull('ci_hold_digest')->orWhere('ci_hold_digest', '!=', $digest ?? ''))
            ->update(['ci_hold_digest' => null]);
        if (! $nested) {
            Node::query()->whereKey($nodeId)->update(['ci_nested_hosting' => false]);
        }
    }

    /** The end of a `ci-runner` removal, passed or failed: its phase removes the hold script, and the Node is no CI Node any more. */
    public static function forgetConverge(int $nodeId): void
    {
        Node::query()->whereKey($nodeId)->update(['ci_hold_digest' => null, 'ci_nested_hosting' => false]);
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
     * on it, of any proof, and the warm copies. A task copy takes no place. None on a Node that takes no proof copies (takesCopies). The runner view shows this
     * count, and proof:run places one copy in each.
     */
    public static function freePlaces(Node $node, NodeCiData $ci, int $busyRunners, int $proofCopies): int
    {
        return self::takesCopies($node) ? max(0, $ci->places - self::runnerJobs($ci, $busyRunners) - $proofCopies) : 0;
    }

    /** The copies that the Node's available memory holds, each at one copy's peak (`proof.copy_memory`). */
    public static function memoryPlaces(int $availableBytes): int
    {
        return intdiv(max(0, $availableBytes), app(ProofSettings::class)->copyMemory());
    }

    /**
     * The live proof copies: each copy in the nested Incus project whose task group a proof run owns (ProofGroup) or the warm pool holds
     * (ProofWarmPool), also one that is failing or being destroyed. A warm copy holds its place while it waits.
     *
     * @return Builder<Instance>
     */
    public static function copies(): Builder
    {
        return Instance::query()->whereNotNull('task_workspace_owner_id')
            ->whereHas('runtime', static fn ($runtime) => $runtime->where('incus_project', InstancePlacement::NestedProject))
            ->where(static fn (Builder $owned): Builder => $owned
                ->whereIn('task_workspace_owner_id', Operation::query()->select('target_id')->where('kind', ProofGroup::OwnerKind)->where('target_type', 'task_group'))
                ->orWhereIn('task_workspace_owner_id', ProofWarmCopy::query()->select('task_id')));
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
