<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Instances\StartDevelopmentInstanceCreateAction;
use App\Actions\Projects\CaptureProjectSourceFactsAction;
use App\Data\Instances\CreateInstanceData;
use App\Domain\Instances\DefaultInstanceName;
use App\Domain\Instances\InstanceAgentInstructions;
use App\Domain\Instances\InstanceHostIntent;
use App\Domain\Instances\InstancePlacement;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\IsolatedIdRanges;
use App\Domain\Instances\Power\InstanceSleep;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Proof\ProofSeeds;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Instances\ContainerWorkspaceRead;
use App\Infrastructure\Instances\NodeIdMapRead;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The start of a task whose Project's tasks run as copies (docs/cli/task/start.mmd): one clone of the Project's default Instance,
 * named and branched `task-<id>`, that the group owns. The clone is an `instance.create` as every clone is; the group, its binding and
 * the create's Operation commit together, and Swamp starts the run only after that commit.
 */
final readonly class TaskStart
{
    public function __construct(private OperationIdempotency $idempotency, private ContainerWorkspaceRead $read, private NodeIdMapRead $ids) {}

    /** A retry with the request's Idempotency-Key replays the same create: the key moves to the create's own kind. */
    public function start(Task $group, string $caller, ?string $request): Operation
    {
        $key = $this->idempotency->key;

        return $key === null ? $this->admit($group, $caller, $request)
            : $this->idempotency->under('instance.create', $key, (string) $this->idempotency->fingerprint, fn (): Operation => $this->admit($group, $caller, $request));
    }

    private function admit(Task $group, string $caller, ?string $request): Operation
    {
        if (($replay = $this->idempotency->replay('instance.create')) !== null) {
            return $replay;
        }
        $read = $this->read($group->project()->firstOrFail());

        return DB::transaction(fn (): Operation => $this->admitRead($group, $caller, $request, $read));
    }

    /**
     * The reads a copy start needs before its transaction: the source facts, the default's container, the ID map of each Node a copy
     * goes to and the Project's agent instructions. Each can reach the forge, the Node or the document store, so a caller that starts
     * copies in its own transaction, such as a proof, calls this first. A task copy goes to the default's Node; a proof names the Nodes
     * of its CI places.
     *
     * @param  list<int>  $nodeIds
     * @return array{asleep: bool, hosts: array<int, array{ranges: IsolatedIdRanges, containers: list<string>}|null>, agent_files: array<string, string>}
     */
    public function read(Project $project, array $nodeIds = []): array
    {
        // The create captures missing source facts itself; that read can reach the forge, so it runs here, before the transaction.
        if ($project->source_facts_captured_at === null && $project->source_access !== ProjectSourceAccess::GhCli) {
            app(CaptureProjectSourceFactsAction::class)->execute($project);
        }

        // A copy is always a clone of the default: its agent instructions are read here, before the transaction
        // (docs/reference/development-containers.md#agent-instructions).
        return ['asleep' => $this->assertDefaultRuns($project), 'hosts' => $this->readIds($project, $nodeIds),
            'agent_files' => app(InstanceAgentInstructions::class)->read($project)];
    }

    /**
     * Admits one copy for the group from the reads of {@see read()}. Call it in a transaction; a second copy in the same transaction
     * counts the first as live and as an open create on the Node. A task copy goes to the default's Node, within `orbit.tasks.copies_max`.
     * A proof copy goes to the Node of its free CI place (`$place`, ProofCopies), which counted it already: it reads no copy cap.
     *
     * @param  array{asleep: bool, hosts: array<int, array{ranges: IsolatedIdRanges, containers: list<string>}|null>, agent_files: array<string, string>}  $read
     */
    public function admitRead(Task $group, string $caller, ?string $request, array $read, ?Node $place = null): Operation
    {
        $asleep = $read['asleep'];
        $group = Task::topLevel()->with('project')->lockForUpdate()->findOrFail($group->id);
        app(TaskProvisionIntent::class)->assertProvisionable($group);
        $project = $group->project;
        $default = Instance::query()->where('project_id', $project->id)->where('name', DefaultInstanceName::Name)->lockForUpdate()->first();
        $runtime = $default?->runtime;
        // A copy starts from a default with a seed that runs or sleeps: the clone copies the seed snapshot and never wakes it. A
        // default stopped by hand or unfinished fails here, before any record.
        if ($default === null || $runtime === null || $default->status !== InstanceState::Active || $runtime->seed_commit === null
            || ($runtime->power_state !== InstanceRuntimePowerState::Running && ! ($asleep && InstanceSleep::asleep($default, $runtime) !== null))) {
            throw new ResourceOperationException('tasks.copy_source_unavailable',
                "Project [{$project->slug}] has no running or sleeping default Instance with a seed snapshot to copy.", 409);
        }
        $name = TaskWorkspaceName::for($group);
        if (Instance::query()->where('project_id', $project->id)->where('name', $name)->exists()) {
            throw new ResourceOperationException('tasks.copy_name_taken', "Instance [{$name}] already exists in Project [{$project->slug}].", 409);
        }
        // The cap guards what a nested-fleet copy costs the Node (memory and its isolated ID range); a copy of another Project is a
        // plain clone and counts as every clone does.
        $limit = Config::integer('orbit.tasks.copies_max');
        $live = self::live($default->node_id);
        if ($place === null && $runtime->incus_project === InstancePlacement::NestedProject && $live >= $limit) {
            throw new ResourceOperationException('tasks.copy_capacity_full',
                "The Node holds {$live} task copies; the Gateway allows {$limit} at once.", 409, details: ['live' => $live, 'limit' => $limit]);
        }
        // The copy starts from the seed of the Node it is placed on.
        $node = $place ?? $default->node()->firstOrFail();
        self::seed($runtime, $node);
        $this->assertIdRanges($node, $runtime, $read['hosts'][$node->id] ?? null);
        $operation = app(StartDevelopmentInstanceCreateAction::class)->execute(
            new CreateInstanceData($project->id, $node->id, $name, null, null, $name), $caller, $request, $group, $read['agent_files'], $place !== null);
        $group->taskable()->associate(Instance::query()->findOrFail($operation->target_id));
        $group->fill(['status' => TaskGroupStatus::Reserved, 'reserved_at' => now(), 'capacity_wait_reason' => null])->save();

        return $operation;
    }

    /**
     * The seed a copy on the Node starts from (docs/reference/development-containers.md#the-seed-on-each-ci-node): on the default's own
     * Node, the default's seed pair; on another Node, that Node's seed holder of the proof default, which must be the default's current
     * seed. No copy fetches a seed over the network. A Node without a current seed refuses.
     *
     * @return array{source: string, snapshot: string, seed_commit: string, inner_volume: string|null}
     */
    public static function seed(InstanceRuntime $default, Node $node): array
    {
        $commit = $default->seed_commit ?? throw new LogicException('A copied default records its seed commit.');
        if ($node->id === $default->node_id) {
            return ['source' => $default->container_name, 'snapshot' => 'seed-'.$commit, 'seed_commit' => $commit, 'inner_volume' => $default->inner_volume];
        }
        $proof = app(ProofSeeds::class);
        $held = $proof->source()?->id === $default->id ? $proof->holder($node) : null;
        if ($held === null) {
            throw new ResourceOperationException('tasks.copy_source_unavailable',
                "Node [{$node->name}] holds no current seed of the default Instance to copy.", 409, details: ['node_id' => $node->id]);
        }

        return ['source' => $held->holder, 'snapshot' => 'seed-'.$held->commit, 'seed_commit' => $held->commit, 'inner_volume' => $held->volume()];
    }

    /**
     * The default's container must run or sleep now. Its power record can be stale, for example after a stop by hand, so one bounded
     * read of the container (ContainerWorkspaceRead, at most about 6 s) decides before the transaction. A container that does not run
     * and has no asleep marker (`stopped`), or that cannot be read, refuses. A timeout does not: the reader first sees the container
     * run, then may run out of time on its Git work. Returns whether the read found it asleep, which the transaction checks against
     * the default's last sleep. A missing default or runtime is refused inside the transaction.
     */
    private function assertDefaultRuns(Project $project): bool
    {
        $default = Instance::query()->with('node')->where('project_id', $project->id)->where('name', DefaultInstanceName::Name)->first();
        if ($default === null || ! $default->runtime()->exists()) {
            return false;
        }
        $read = $this->read->read($default);
        if ($read['state'] === 'unknown' && in_array($read['reason'], ['stopped', 'unreachable'], true)) {
            throw new ResourceOperationException('tasks.copy_source_unavailable',
                "Project [{$project->slug}] has no running or sleeping default Instance with a seed snapshot to copy.", 409, details: ['default' => $read['reason']]);
        }

        return $read['state'] === 'unknown' && $read['reason'] === 'asleep';
    }

    /**
     * The ID map facts of each host, by Node id, read before the transaction: one bounded SSH call each (NodeIdMapRead, at most about
     * 4 s). Without `$nodeIds`, the default's host. Null for a host that cannot be read, which assertIdRanges refuses; none when there is
     * no default, which the transaction refuses.
     *
     * @param  list<int>  $nodeIds
     * @return array<int, array{ranges: IsolatedIdRanges, containers: list<string>}|null>
     */
    private function readIds(Project $project, array $nodeIds): array
    {
        $default = Instance::query()->where('project_id', $project->id)->where('name', DefaultInstanceName::Name)->first();
        if ($default === null) {
            return [];
        }
        $hosts = [];
        foreach (Node::query()->whereKey($nodeIds === [] ? [$default->node_id] : $nodeIds)->get() as $node) {
            $hosts[$node->id] = $this->ids->read($node);
        }

        return $hosts;
    }

    /**
     * A copy takes one isolated ID range of its default's size, and a default's next seed needs one more for a moment: Incus maps a
     * new snapshot like a new container (docs/reference/development-containers.md#isolated-id-ranges). The ranges are the host's as
     * read, plus one for each container that an open create on this Node has not made yet. With the copy placed, a range must stay
     * free for the seed of this default and of every other default on the Node: a small range in a nested-sized gap would leave a
     * nested default without one. A copy that would leave none refuses here, not in its create. An unreadable host refuses: no free
     * range can be shown, and the hand-set cap knows nothing of the ranges.
     *
     * @param  array{ranges: IsolatedIdRanges, containers: list<string>}|null  $host
     */
    private function assertIdRanges(Node $node, InstanceRuntime $runtime, ?array $host): void
    {
        if ($host === null) {
            throw new ResourceOperationException('tasks.copy_id_ranges_unreadable',
                "The ID ranges of the copy's Node cannot be read, so the Gateway cannot show a free range for the copy and its next seed.", 409);
        }
        $runtimes = InstanceRuntime::query()->where('node_id', $node->id)->whereIn('incus_project', InstancePlacement::projects())
            ->get(['instance_id', 'incus_project', 'container_name', 'idmap_size']);
        // A runtime row whose container is not on the host holds a range only while its create is open; a failed one never will.
        $open = Operation::query()->where('kind', 'instance.create')->where('target_type', 'instance')
            ->whereIn('target_id', $runtimes->pluck('instance_id'))->whereNotIn('status', OperationStatus::terminalValues())->pluck('target_id')->all();
        $pending = $runtimes->filter(static fn (InstanceRuntime $other): bool => in_array($other->instance_id, $open, true)
            && ! in_array($other->incus_project.'/'.$other->container_name, $host['containers'], true));
        $ranges = $host['ranges'];
        foreach ($pending as $other) {
            $ranges = $ranges?->with(self::rangeSize($other));
        }
        $size = self::rangeSize($runtime);
        $placed = $ranges?->with($size);
        $defaults = Instance::query()->where('node_id', $node->id)->where('name', DefaultInstanceName::Name)->pluck('id')->all();
        $seeds = $runtimes->toBase()->filter(static fn (InstanceRuntime $other): bool => in_array($other->instance_id, $defaults, true))
            ->map(static fn (InstanceRuntime $other): int => self::rangeSize($other))->push($size)->unique()->values();
        $short = $placed === null ? $seeds : $seeds->filter(static fn (int $seed): bool => $placed->place($seed) === null)->values();
        if ($short->isNotEmpty()) {
            $free = $ranges?->free($size, 2) ?? 0;
            throw new ResourceOperationException('tasks.copy_id_range_full',
                "The Node has {$free} free isolated ID range(s) of {$size} IDs: with the copy placed, no range of ".$short->implode(', ')
                .' IDs stays free for a default\'s next seed.',
                409, details: ['free' => $free, 'size' => $size, 'seed_sizes_short' => $short->all(), 'pending' => $pending->count()]);
        }
    }

    /**
     * The isolated range of a container: a nested-fleet container's recorded size, which a copy shares with its default, or the Incus
     * default (docs/reference/development-containers.md#the-id-map-size).
     */
    private static function rangeSize(InstanceRuntime $runtime): int
    {
        return $runtime->incus_project === InstancePlacement::NestedProject
            ? ($runtime->idmap_size ?? throw new LogicException('A nested-fleet runtime records its ID map size.')) : InstanceHostIntent::IdMapSize;
    }

    /**
     * The nested-fleet task copies on the Node now: every Instance with a container in the nested Incus project that a task group owns,
     * also one that is failing or being destroyed.
     */
    public static function live(int $nodeId): int
    {
        return Instance::query()->where('node_id', $nodeId)->whereNotNull('task_workspace_owner_id')
            ->whereHas('runtime', static fn ($runtime) => $runtime->where('incus_project', InstancePlacement::NestedProject))->count();
    }
}
