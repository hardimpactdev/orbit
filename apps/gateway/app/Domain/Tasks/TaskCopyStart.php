<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Instances\StartDevelopmentInstanceCreateAction;
use App\Actions\Projects\CaptureProjectSourceFactsAction;
use App\Data\Instances\CreateInstanceData;
use App\Domain\Instances\DefaultInstanceName;
use App\Domain\Instances\InstanceHostIntent;
use App\Domain\Instances\InstancePlacement;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\IsolatedIdRanges;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Instances\ContainerWorkspaceRead;
use App\Infrastructure\Instances\NodeIdMapRead;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * The start of a task whose Project's tasks run as copies (docs/cli/task/start.mmd): one clone of the Project's default Instance,
 * named and branched `task-<id>`, that the group owns. The clone is an `instance.create` as every clone is; the group, its binding and
 * the create's Operation commit together, and Swamp starts the run only after that commit.
 */
final readonly class TaskCopyStart
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
        $project = $group->project()->firstOrFail();
        // The create captures missing source facts itself; that read can reach the forge, so it runs here, before the transaction.
        if ($project->source_facts_captured_at === null && $project->source_access !== ProjectSourceAccess::GhCli) {
            app(CaptureProjectSourceFactsAction::class)->execute($project);
        }
        $this->assertDefaultRuns($project);
        $host = $this->readIds($project);

        return DB::transaction(function () use ($group, $caller, $request, $host): Operation {
            $group = Task::topLevel()->with('project')->lockForUpdate()->findOrFail($group->id);
            app(TaskProvisionIntent::class)->assertProvisionable($group);
            $project = $group->project;
            $default = Instance::query()->where('project_id', $project->id)->where('name', DefaultInstanceName::Name)->lockForUpdate()->first();
            $runtime = $default?->runtime;
            // A copy starts only from a running default with a seed: a stopped or unfinished default fails here, before any record.
            if ($default === null || $runtime === null || $default->status !== InstanceState::Active || $runtime->seed_commit === null
                || $runtime->power_state !== InstanceRuntimePowerState::Running) {
                throw new ResourceOperationException('tasks.copy_source_unavailable',
                    "Project [{$project->slug}] has no running default Instance with a seed snapshot to copy.", 409);
            }
            $name = TaskWorkspaceName::for($group);
            if (Instance::query()->where('project_id', $project->id)->where('name', $name)->exists()) {
                throw new ResourceOperationException('tasks.copy_name_taken', "Instance [{$name}] already exists in Project [{$project->slug}].", 409);
            }
            // The cap guards what a nested-fleet copy costs the Node (memory and its isolated ID range); a copy of another Project is a
            // plain clone and counts as every clone does.
            $limit = Config::integer('orbit.tasks.copies_max');
            $live = self::live($default->node_id);
            if ($runtime->incus_project === InstancePlacement::NestedProject && $live >= $limit) {
                throw new ResourceOperationException('tasks.copy_capacity_full',
                    "The Node holds {$live} task copies; the Gateway allows {$limit} at once.", 409, details: ['live' => $live, 'limit' => $limit]);
            }
            $this->assertIdRanges($default, $runtime, $host);
            $operation = app(StartDevelopmentInstanceCreateAction::class)->execute(
                new CreateInstanceData($project->id, $default->node_id, $name, null, null, $name), $caller, $request, $group);
            $group->taskable()->associate(Instance::query()->findOrFail($operation->target_id));
            $group->fill(['status' => TaskGroupStatus::Reserved, 'reserved_at' => now(), 'capacity_wait_reason' => null])->save();

            return $operation;
        });
    }

    /**
     * The default's container must run now. Its power record can be stale, for example after a stop by hand, so one bounded read of the
     * container (ContainerWorkspaceRead, at most about 6 s) decides before the transaction. A stopped, asleep or unreachable container
     * refuses. A timeout does not: the reader first sees the container run, then may run out of time on its Git work. A missing default
     * or runtime is refused inside the transaction.
     */
    private function assertDefaultRuns(Project $project): void
    {
        $default = Instance::query()->with('node')->where('project_id', $project->id)->where('name', DefaultInstanceName::Name)->first();
        if ($default === null || ! $default->runtime()->exists()) {
            return;
        }
        $read = $this->read->read($default);
        if ($read['state'] === 'unknown' && in_array($read['reason'], ['stopped', 'asleep', 'unreachable'], true)) {
            throw new ResourceOperationException('tasks.copy_source_unavailable',
                "Project [{$project->slug}] has no running default Instance with a seed snapshot to copy.", 409, details: ['default' => $read['reason']]);
        }
    }

    /**
     * The ID map facts of the default's host, read before the transaction: one bounded SSH call (NodeIdMapRead, at most about 4 s).
     * Null when there is no default, which the transaction refuses, or when the host cannot be read, which assertIdRanges refuses.
     *
     * @return array{ranges: IsolatedIdRanges, containers: list<string>}|null
     */
    private function readIds(Project $project): ?array
    {
        $default = Instance::query()->with('node')->where('project_id', $project->id)->where('name', DefaultInstanceName::Name)->first();

        return $default === null ? null : $this->ids->read($default->node);
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
    private function assertIdRanges(Instance $default, InstanceRuntime $runtime, ?array $host): void
    {
        if ($host === null) {
            throw new ResourceOperationException('tasks.copy_id_ranges_unreadable',
                "The ID ranges of the default's Node cannot be read, so the Gateway cannot show a free range for the copy and its next seed.", 409);
        }
        $runtimes = InstanceRuntime::query()->where('node_id', $default->node_id)->whereIn('incus_project', InstancePlacement::projects())
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
        $defaults = Instance::query()->where('node_id', $default->node_id)->where('name', DefaultInstanceName::Name)->pluck('id')->all();
        $seeds = $runtimes->filter(static fn (InstanceRuntime $other): bool => in_array($other->instance_id, $defaults, true))
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
        return $runtime->incus_project === InstancePlacement::NestedProject ? ($runtime->idmap_size ?? InstanceHostIntent::TaskPlaceIds) : InstanceHostIntent::IdMapSize;
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
