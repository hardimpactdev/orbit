<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Instances\DefaultInstanceName;
use App\Domain\Instances\InstanceHostIntent;
use App\Domain\Instances\InstancePlacement;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\ProofSeed;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The seed of the proof default on each Node that offers proof places (docs/reference/development-containers.md#the-seed-on-each-ci-node,
 * ADR 0260 build step 4). The default's own Node holds the seed itself; every other such Node keeps a copy, which one
 * `instance.seed_copy` run per seed sends to each Node that is behind. A copy on a Node starts from that Node's seed.
 */
final readonly class ProofSeeds
{
    public const string Kind = 'instance.seed_copy';

    /** The holder of the seed on each CI Node, a stopped container, and its inner volume, both in the nested Incus project. */
    public const string Holder = 'proof-seed';

    public const string HolderVolume = self::Holder.'-inner';

    /** The client remote of root on the default's Node that names a CI Node's listener. */
    public const string RemotePrefix = 'proof-node-';

    public function __construct(private ProofSettings $settings) {}

    /** The Incus listener port of a CI Node, on its WireGuard address only (docs/reference/node-provisioning.md#the-seed-listener). */
    public const int ListenerPort = 8444;

    /** The name of the one restricted trust entry on a CI Node: root's Incus client certificate of the default's Node. */
    public const string TrustName = 'orbit-proof-seed';

    /** The Node of the proof default's nested container, which sends the seed to every CI Node; null while there is none. */
    public function defaultNode(): ?Node
    {
        $default = Instance::query()->where('project_id', $this->settings->projectId())->where('name', DefaultInstanceName::Name)->first();
        $runtime = $default?->runtime;

        return $runtime === null || $runtime->incus_project !== InstancePlacement::NestedProject ? null : Node::query()->find($runtime->node_id);
    }

    /**
     * The Node that sends the seed to this Node: the default's Node, when this Node offers proof places and is not that Node. Null
     * otherwise; then the Node has no seed listener, trust or firewall rule (docs/reference/node-provisioning.md#the-seed-listener).
     */
    public function sender(Node $node): ?Node
    {
        $default = $this->defaultNode();

        return $default === null || $default->id === $node->id || ! ProofCopyHosts::offersPlaces($node) ? null : $default;
    }

    /**
     * Records which Node the seed listener of a CI Node trusts, at the end of its `ci-runner` add or converge: the sender in a passed run's
     * intent; none for a passed run without a sender, which removed the listener, and for any other end, which may have changed the trust
     * (docs/reference/node-provisioning.md#the-seed-listener). A seed copy targets only a Node whose record names the default's Node.
     *
     * @param  array<string, mixed>  $runtime  the run's role runtime intent
     */
    public static function recordListener(int $nodeId, array $runtime, bool $passed): void
    {
        $sender = $passed ? (ValidatedData::object($runtime['proof_seed'] ?? [])['sender'] ?? null) : null;
        Node::query()->whereKey($nodeId)->update(['seed_sender_id' => is_array($sender) ? ValidatedData::integer($sender['node_id']) : null]);
    }

    /** The end of a `ci-runner` removal, passed or failed: its phase stops the listener, so the Node trusts no sender. */
    public static function forgetListener(int $nodeId): void
    {
        Node::query()->whereKey($nodeId)->update(['seed_sender_id' => null]);
    }

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
     * whose recorded seed is not the default's current seed and whose seed listener trusts the default's Node ({@see self::recordListener()}).
     * A Node whose listener is not ready is no target, so no copy fails on it or marks it tried; its next passed converge makes it one.
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
        $ready = Node::query()->whereIn('id', $ids)->where('seed_sender_id', $source->node_id)->orderBy('id')->get();

        return array_values($ready->filter(static function (Node $node) use ($seeds, $source): bool {
            $seed = $seeds->get($node->id);

            return ProofCopyHosts::offersPlaces($node) && ! ($seed instanceof ProofSeed && self::current($seed, $source));
        })->all());
    }

    /**
     * Admits one seed copy to the Nodes that are behind. Best effort: nothing waits for it, so a refusal or an error is a log line, never
     * an exception. An open copy refuses by its lock (`operation.target_busy`); its end admits the next one ({@see self::admitAfter()}).
     * `$except` names Nodes that this admission leaves out.
     *
     * @param  list<int>  $except
     */
    public function admit(string $trigger, array $except = []): ?Operation
    {
        try {
            $source = $this->source();
            $targets = array_values(array_filter($this->targets(), static fn (Node $node): bool => ! in_array($node->id, $except, true)));
            if ($source === null || $targets === []) {
                return null;
            }
            $input = ['default_instance_id' => $source->instance_id, 'commit' => $source->seed_commit,
                'taken_at' => $source->seed_taken_at?->toIso8601String(),
                'targets' => array_map(static fn (Node $node): int => $node->id, $targets)];

            return app(StartOperationAction::class)->execute(self::Kind, 'instance', $source->instance_id, $source->node_id, $input,
                $trigger, null, lockKey: OperationStore::lockKey('instance_seed_copy', $source->instance_id));
        } catch (ResourceOperationException $refused) {
            Log::info('Proof seed copy not admitted.', ['trigger' => $trigger, 'code' => $refused->errorCode]);
        } catch (Throwable $failed) {
            report($failed);
        }

        return null;
    }

    /**
     * The admission at the end of a seed copy (docs/cli/instance/seed-copy.mmd): for every Node behind the default's seed when the seed
     * moved during the copy, else only for Nodes that no seed copy has tried at that seed, such as one whose own trigger the copy's lock
     * refused. A Node tried at the same seed waits for the next seed or its own ci-runner converge, so lasting failures do not repeat,
     * also not two failing Nodes for each other. The tried Nodes are the targets of the recorded copies of that seed.
     */
    public function admitAfter(Operation $ended): ?Operation
    {
        $source = $this->source();
        $moved = $source === null || $source->seed_commit !== $ended->input['commit']
            || $source->seed_taken_at?->toIso8601String() !== $ended->input['taken_at'];
        if ($moved) {
            return $this->admit('seed_copy');
        }
        $tried = Operation::query()->where('kind', self::Kind)->where('target_type', 'instance')->where('target_id', $source->instance_id)
            ->where('input->commit', $source->seed_commit)->where('input->taken_at', $source->seed_taken_at?->toIso8601String())->get(['input'])
            ->flatMap(static fn (Operation $copy): array => ValidatedData::integerList($copy->input['targets']))->unique()->all();

        return $this->admit('seed_copy', array_values($tried));
    }

    /**
     * The workflow intent (docs/cli/instance/seed-copy.mmd): the default's Node, container and inner volume, the seed snapshot that the
     * run was admitted for, and per target its Node, the remote of root on the default's Node and the listener it names.
     *
     * @return array<string, mixed>
     */
    public function intent(Operation $operation): array
    {
        $runtime = InstanceRuntime::query()->where('instance_id', $operation->target_id)->firstOrFail();
        $ssh = app(SwampSshExecutionInput::class);
        $ids = ValidatedData::integerList($operation->input['targets']);

        return [
            'project' => InstancePlacement::NestedProject,
            'pool' => InstanceHostIntent::Pool,
            'snapshot' => 'seed-'.ValidatedData::string($operation->input['commit']),
            'holder' => self::Holder,
            'holder_volume' => self::HolderVolume,
            // The holder never starts: its NIC gets no static address and only the common ACL, which every Node with the branch has.
            'holder_nic_acl' => InstanceHostIntent::NicAcl,
            'source' => ['node_id' => $runtime->node_id, 'ssh_execution' => $ssh->forNode(Node::query()->findOrFail($runtime->node_id)),
                'container' => $runtime->container_name, 'inner_volume' => $runtime->inner_volume],
            'targets' => Node::query()->whereIn('id', $ids)->orderBy('id')->get()->map(static fn (Node $node): array => [
                'node_id' => $node->id, 'ssh_execution' => $ssh->forNode($node), 'remote' => self::RemotePrefix.$node->id,
                'listen' => ValidatedData::string($node->wireguard_ip).':'.self::ListenerPort])->all(),
        ];
    }

    private static function current(ProofSeed $seed, InstanceRuntime $source): bool
    {
        return $seed->commit === $source->seed_commit && $source->seed_taken_at !== null && $seed->taken_at->equalTo($source->seed_taken_at);
    }
}
