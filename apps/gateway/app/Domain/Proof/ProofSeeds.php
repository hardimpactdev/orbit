<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Instances\DefaultInstanceName;
use App\Domain\Instances\InstanceHostIntent;
use App\Domain\Instances\InstancePlacement;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationBudgetTable;
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
use Illuminate\Support\Facades\DB;
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

    /**
     * The bound of one `incus` push, the `timeout` of each push in the playbook `proof-seed-copy.yml`. Basis: a measured full copy of
     * the seed pair, both halves and about 4 GB, took about 4 minutes over the WireGuard path; the inner volume holds about 3 GB of data,
     * not its 30 GiB size. So 1,200 s per half gives a wide margin. A refresh sends only the new snapshot, which is small.
     */
    public const int PushSeconds = 1200;

    /**
     * The bound of one target's copy step, the `timeout` of "Copy the seed" in the workflow: its two pushes, plus 100 s for the
     * remote and snapshot reads, the renames and the SSH set-up. The steps run one target at a time (docs/cli/instance/seed-copy.mmd).
     */
    public const int CopySeconds = 2 * self::PushSeconds + 100;

    /**
     * The phases of a run that do not grow with its targets: the certificate read (120 s) and the check (120 s), each side by side,
     * the release of the base (300 s) and the report (60 s), plus 300 s for the input, the queue wait, the result and the cleanup.
     */
    public const int PhaseSeconds = 900;

    /** The holder of the seed on each CI Node, a stopped container, and its inner volume, both in the nested Incus project. */
    public const string Holder = 'proof-seed';

    public const string HolderVolume = self::Holder.'-inner';

    /** The one hold of the default's seed (docs/reference/development-containers.md#the-seed-hold): a seed copy's Operation lock. */
    public const string HoldKind = 'instance_seed';

    /**
     * The bound of a deployment's wait for the seed hold, the step "Hold the seed": one seed copy, which sends a refresh to each CI Node
     * one at a time, ends well within it; the deployment's run budget counts it.
     */
    public const int HoldWaitSeconds = 1800;

    /** A deployment of the default that holds the seed records it in its Operation state until it ends. */
    public const string HeldState = 'seed_held';

    /** The admission's refusal while a deployment of the default holds the seed; the deployment's end admits the copy again. */
    public const string Held = 'instance.seed_held';

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
     * Records which Node the seed listener of a CI Node trusts, at the end of each run that changes it: a `ci-runner` add or role converge,
     * and a `node:converge` with the role, which passes here when its roles phase passed. A passed run with a sender in its intent sets that
     * sender when the run opens the listener; a `node:converge` never opens it, so it keeps the record only when the record already names
     * that sender. A passed run without a sender removed the listener, and any other end may have changed the trust: both clear it
     * (docs/reference/node-provisioning.md#the-seed-listener). A seed copy targets only a Node whose record names the default's Node.
     *
     * @param  array<string, mixed>  $runtime  the run's role runtime intent
     */
    public static function recordListener(int $nodeId, array $runtime, bool $passed, bool $opens): void
    {
        $sender = $passed ? (ValidatedData::object($runtime['proof_seed'] ?? [])['sender'] ?? null) : null;
        $senderId = is_array($sender) ? ValidatedData::integer($sender['node_id']) : null;
        $node = Node::query()->whereKey($nodeId);
        if ($senderId !== null && ! $opens) {
            $node->where('seed_sender_id', '!=', $senderId)->update(['seed_sender_id' => null]);

            return;
        }
        $node->update(['seed_sender_id' => $senderId]);
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

        return $default?->status === InstanceState::Active && $this->seededDefault($default, $runtime) ? $runtime : null;
    }

    /**
     * Whether a seed copy sends this Instance's seed: the proof Project's default in the nested project. Its deployment holds the seed
     * before it retakes it (docs/reference/development-containers.md#the-seed-hold). Read from the loaded models, with no query.
     */
    public function sends(Instance $instance, ?InstanceRuntime $runtime): bool
    {
        return $instance->project_id === $this->settings->projectId() && $instance->name === DefaultInstanceName::Name
            && $runtime?->incus_project === InstancePlacement::NestedProject;
    }

    /** Whether the Instance is the proof Project's default in the nested project with a seed: read from the loaded models, with no query. */
    private function seededDefault(Instance $instance, ?InstanceRuntime $runtime): bool
    {
        return $instance->project_id === $this->settings->projectId() && $instance->name === DefaultInstanceName::Name
            && $runtime?->incus_project === InstancePlacement::NestedProject && $runtime->seed_commit !== null && $runtime->seed_taken_at !== null;
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

        return $source === null ? [] : $this->ready($source, holding: false);
    }

    /**
     * The seed that the default keeps as `base-<commit>` at its next retake, for the next seed copy to start from
     * (docs/reference/development-containers.md#a-retake-keeps-the-seed): the default's current seed while a Node that a copy would target
     * records it, so it lacks only the new one. Null for any other runtime, or when no such Node holds it; then the retake keeps no base.
     * Only one base: a Node with an older seed gets the whole pair. Every container intent asks, so the loaded Instance and runtime first
     * rule out any other container without a query.
     */
    public function base(Instance $instance, InstanceRuntime $runtime): ?string
    {
        if (! $this->seededDefault($instance, $runtime)) {
            return null;
        }
        $source = $this->source();

        return $source === null || $source->id !== $runtime->id || $this->ready($source, holding: true) === [] ? null : $source->seed_commit;
    }

    /**
     * Each Node that offers proof places, other than the default's Node, whose seed listener trusts the default's Node
     * ({@see self::recordListener()}), and whose recorded seed is the default's current seed (`$holding`) or not.
     *
     * @return list<Node>
     */
    private function ready(InstanceRuntime $source, bool $holding): array
    {
        $ids = NodeRole::query()->where('role', RoleName::CiRunner)->where('node_id', '!=', $source->node_id)->orderBy('node_id')->pluck('node_id')->unique();
        $seeds = ProofSeed::query()->whereIn('node_id', $ids)->get()->keyBy('node_id');
        $ready = Node::query()->whereIn('id', $ids)->where('seed_sender_id', $source->node_id)->orderBy('id')->get();

        return array_values($ready->filter(static function (Node $node) use ($seeds, $source, $holding): bool {
            $seed = $seeds->get($node->id);

            return ProofCopyHosts::offersPlaces($node) && ($seed instanceof ProofSeed && self::current($seed, $source)) === $holding;
        })->all());
    }

    /**
     * The run budget of a seed copy to this many targets, captured at admission: the fixed phases and one copy step per target, in
     * series (docs/reference/operations.md).
     */
    public static function budgetSeconds(int $targets): int
    {
        return self::PhaseSeconds + $targets * self::CopySeconds;
    }

    /**
     * The most targets that one run takes: its budget must fit in the Swamp serve run cap ({@see OperationBudgetTable::longestFixedSeconds()}),
     * which cuts any longer run. The Nodes that a run leaves out are untried at the seed, so its end admits them ({@see self::admitAfter()}).
     */
    public static function maxTargets(): int
    {
        return intdiv(OperationBudgetTable::longestFixedSeconds() - self::PhaseSeconds, self::CopySeconds);
    }

    /**
     * Admits one seed copy to the Nodes that are behind, at most {@see self::maxTargets()} of them. Best effort: nothing waits for it, so a refusal or an error is a log line, never
     * an exception. An open copy refuses by its lock (`operation.target_busy`); its end admits the next one ({@see self::admitAfter()}).
     * `$except` names Nodes that this admission leaves out. `$node` names the Node whose own trigger this is: when the limit cuts the
     * targets, it keeps that Node, because the end of the run admits only Nodes that no copy has tried at the seed.
     *
     * @param  list<int>  $except
     */
    public function admit(string $trigger, array $except = [], ?int $node = null): ?Operation
    {
        try {
            $source = $this->source();
            $behind = $this->targets();
            $candidates = array_filter($behind, static fn (Node $target): bool => ! in_array($target->id, $except, true));
            $named = array_filter($candidates, static fn (Node $target): bool => $target->id === $node);
            $targets = array_slice([...$named, ...array_diff_key($candidates, $named)], 0, self::maxTargets());
            usort($targets, static fn (Node $a, Node $b): int => $a->id <=> $b->id);
            if ($source === null || $targets === []) {
                return null;
            }
            // A copy to every Node behind the seed may release the default's base when all of them pass: no other Node needs it.
            $input = ['default_instance_id' => $source->instance_id, 'commit' => $source->seed_commit,
                'taken_at' => $source->seed_taken_at?->toIso8601String(),
                'targets' => array_map(static fn (Node $node): int => $node->id, $targets), 'release_base' => count($targets) === count($behind),
                'run_budget_seconds' => self::budgetSeconds(count($targets))];

            // The default's row orders this admission with a deployment's hold of the seed ({@see self::hold()}).
            return DB::transaction(static function () use ($source, $input, $trigger): Operation {
                Instance::query()->whereKey($source->instance_id)->lockForUpdate()->firstOrFail();
                if (self::heldByDeployment($source->instance_id)) {
                    throw new ResourceOperationException(self::Held, 'A deployment of the default holds its seed.', 409);
                }

                return app(StartOperationAction::class)->execute(self::Kind, 'instance', $source->instance_id, $source->node_id, $input,
                    $trigger, null, lockKey: self::holdKey($source->instance_id));
            });
        } catch (ResourceOperationException $refused) {
            Log::info('Proof seed copy not admitted.', ['trigger' => $trigger, 'code' => $refused->errorCode]);
        } catch (Throwable $failed) {
            report($failed);
        }

        return null;
    }

    /** The hold key of the default's seed, which an open seed copy holds as its Operation lock. */
    public static function holdKey(int $defaultId): string
    {
        return OperationStore::lockKey(self::HoldKind, $defaultId);
    }

    /**
     * A deployment of the default takes the seed before its first `seed` phase (docs/cli/instance/container/deploy-default.mmd): true
     * when it holds it now or held it already, false while a seed copy holds it. Once taken, the deployment holds the seed until it ends,
     * and no seed copy is admitted meanwhile; its end admits one again.
     */
    public function hold(Operation $deployment): bool
    {
        return DB::transaction(static function () use ($deployment): bool {
            Instance::query()->whereKey($deployment->target_id)->lockForUpdate()->firstOrFail();
            $locked = Operation::query()->lockForUpdate()->findOrFail($deployment->id);
            if (($locked->state[self::HeldState] ?? false) === true) {
                return true;
            }
            if (Operation::query()->where('lock_key', self::holdKey($locked->target_id))->exists()) {
                return false;
            }
            $locked->forceFill(['state' => [...($locked->state ?? []), self::HeldState => true]])->save();

            return true;
        });
    }

    /** Whether an open deployment of the default holds its seed. */
    private static function heldByDeployment(int $defaultId): bool
    {
        // An open deployment of a container holds the Instance lock; the terminal status frees it, and the seed with it.
        return Operation::query()->where('lock_key', OperationStore::lockKey('instance', $defaultId))->where('kind', 'instance.deploy')
            ->where('state->'.self::HeldState, true)->exists();
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
     * run was admitted for, whether the run may release the default's base when every target passes, and per target its Node, the remote of root on the default's Node and the listener it names.
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
            'release_base' => ($operation->input['release_base'] ?? false) === true,
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
