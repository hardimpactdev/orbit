<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Domain\Instances\DefaultInstanceName;
use App\Domain\Instances\InstancePlacement;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\ProofSeed;

/**
 * The seed of the proof default on each Node that offers proof places (docs/reference/development-containers.md#the-seed-on-each-ci-node,
 * ADR 0260 build step 4). The default's own Node holds the seed itself; every other such Node keeps a copy, which one
 * `instance.seed_copy` run per seed sends to each Node that is behind. A copy on a Node starts from that Node's seed.
 */
final readonly class ProofSeeds
{
    public function __construct(private ProofSettings $settings) {}

    /** The proof default's runtime when the default is active and has a seed, else null. */
    public function source(): ?InstanceRuntime
    {
        $default = Instance::query()->where('project_id', $this->settings->projectId())->where('name', DefaultInstanceName::Name)->first();
        $runtime = $default?->runtime;

        return $default?->status === InstanceState::Active && $runtime?->seed_commit !== null && $runtime->seed_taken_at !== null
            && $runtime->incus_project === InstancePlacement::NestedProject ? $runtime : null;
    }

    /**
     * The Node's seed of the proof default, when it is the default's current seed: the one a proof copy on that Node starts from. Null on
     * an old seed or none: then the Node offers no proof places, so one proof never mixes the fleets of two seeds. The default's own
     * Node holds no copy; a copy there clones the default.
     */
    public function holder(Node $node): ?ProofSeed
    {
        $source = $this->source();
        $seed = ProofSeed::query()->where('node_id', $node->id)->first();

        return $source !== null && $seed !== null && self::current($seed, $source) ? $seed : null;
    }

    /**
     * The Nodes whose seed a copy must bring to the current seed: each Node that offers proof places, other than the default's Node,
     * whose recorded seed is not the default's current seed.
     *
     * @return list<Node>
     */
    public function targets(): array
    {
        $source = $this->source();
        if ($source === null) {
            return [];
        }
        $ids = NodeRole::query()->where('role', RoleName::CiRunner)->where('node_id', '!=', $source->node_id)->orderBy('node_id')->pluck('node_id')->unique();
        $seeds = ProofSeed::query()->whereIn('node_id', $ids)->get()->keyBy('node_id');

        return array_values(Node::query()->whereIn('id', $ids)->orderBy('id')->get()->filter(static function (Node $node) use ($seeds, $source): bool {
            $seed = $seeds->get($node->id);

            return ProofCopyHosts::offersPlaces($node) && ! ($seed instanceof ProofSeed && self::current($seed, $source));
        })->all());
    }

    private static function current(ProofSeed $seed, InstanceRuntime $source): bool
    {
        return $seed->commit === $source->seed_commit && $source->seed_taken_at !== null && $seed->taken_at->equalTo($source->seed_taken_at);
    }
}
