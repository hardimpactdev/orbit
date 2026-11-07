<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmPromotedImage;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Node;
use App\Models\TaskVm;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

/**
 * Compares the instances of a task VM host's Incus project with the task VM rows of that host, and finds the Nodes that no task VM
 * row owns. Nothing here changes anything: a failed start is released through its own run, and the rest is the operator's.
 *
 * - An orphaned VM is a `tvm-<number>` instance with no live row of that name on the host.
 * - A missing VM is a `ready`, or `parked` row whose instance is gone. Other states have jobs that check the VM.
 *
 * It also finds strays, which only a report names ({@see self::strays()}, {@see self::strayNodes()}).
 */
final readonly class TaskVmHostAudit
{
    private const string TaskVmName = '/\Atvm-[0-9]+\z/D';

    /** A `ready` or `parked` row without an open group for longer than this is a stray. */
    public const int RecordMinutes = 60;

    /** A `tvm-<number>` Node without a live row for longer than this is a stray: enrollment links them in one job. */
    public const int NodeMinutes = 15;

    public function __construct(private IncusHost $incus) {}

    /** @return array{orphaned: list<string>, missing: list<TaskVm>} */
    public function audit(TaskVmHost $host, Node $node): array
    {
        $names = $this->incus->instanceNames($host, $node);
        $live = TaskVm::query()->live()->where('host_node_id', $node->id)->get();
        $recorded = $live->pluck('name')->all();

        return [
            'orphaned' => array_values(array_filter(
                $names,
                static fn (string $name): bool => preg_match(self::TaskVmName, $name) === 1 && ! in_array($name, $recorded, true),
            )),
            'missing' => array_values($live->filter(
                static fn (TaskVm $vm): bool => in_array($vm->state, [TaskVmState::Ready, TaskVmState::Parked], true) && ! in_array($vm->name, $names, true),
            )->all()),
        ];
    }

    /**
     * What Orbit does not own on the host, in every Incus project and storage pool, and the host's `ready` or `parked`
     * rows without an open group. Orbit owns, in the host's project, the task VMs `tvm-<number>` (the audit checks them
     * against their rows) and the base VM and, in the host's pool, the seed volume of the base VM and the volumes of
     * {@see TaskVmVolumes::owns()}: VM clones and the topology seeds of the images the project keeps.
     * The host's `operator_owned` entries are the operator's. Everything else is a stray. Nothing here deletes or
     * changes anything.
     *
     * @return array{instances: list<string>, volumes: list<string>, records: list<TaskVm>} instances as `project/name`,
     *                                                                                      volumes as `project/pool/name`
     */
    public function strays(TaskVmHost $host, Node $node): array
    {
        $inventory = $this->incus->inventory($node);
        $ciRunner = $node->roles()->where('role', RoleName::CiRunner)->where('status', LifecycleStatus::Active)->exists();
        $instances = [];
        foreach ($inventory['instances'] as ['project' => $project, 'name' => $name]) {
            if (! ($ciRunner && $project === 'orbit-ci') && ! $host->operatorOwns($project, $name) && ! ($project === $host->project && self::ownsInstance($node, $name))) {
                $instances[] = "{$project}/{$name}";
            }
        }
        $volumes = [];
        $fingerprints = array_keys($this->incus->images($host, $node));
        foreach ($inventory['volumes'] as ['project' => $project, 'pool' => $pool, 'name' => $name]) {
            $owned = $project === $host->project && $pool === $host->pool && (TaskVmVolumes::owns($name, $fingerprints) || $name === TaskVmPromotedImage::baseSeed($node));
            if (! $owned && ! ($ciRunner && $project === 'orbit-ci') && ! $host->operatorOwns($project, $name)) {
                $volumes[] = "{$project}/{$pool}/{$name}";
            }
        }

        return ['instances' => $instances, 'volumes' => $volumes, 'records' => self::strayRecords($node)];
    }

    /**
     * The host's `ready` or `parked` rows with no group, or with an ended group, unchanged for
     * {@see self::RecordMinutes}, counted from the later change of the row and its group.
     *
     * @return list<TaskVm>
     */
    public static function strayRecords(Node $node): array
    {
        $cutoff = now()->subMinutes(self::RecordMinutes);

        return array_values(TaskVm::query()->where('host_node_id', $node->id)
            ->whereIn('state', [TaskVmState::Ready, TaskVmState::Parked])
            ->where('updated_at', '<=', $cutoff)
            ->with('group')->orderBy('id')->get()
            ->filter(static fn (TaskVm $vm): bool => $vm->group === null
                || (in_array($vm->group->groupStatus(), TaskGroupStatus::endedStatuses(), true)
                    && $vm->group->getAttribute('updated_at') instanceof Carbon && $vm->group->getAttribute('updated_at')->lessThanOrEqualTo($cutoff)))
            ->all());
    }

    /**
     * The Nodes named `tvm-<number>`, created {@see self::NodeMinutes} ago or earlier, that no live task VM row is.
     *
     * @return list<Node>
     */
    public static function strayNodes(): array
    {
        return array_values(Node::query()->where('name', 'like', 'tvm-%')
            ->where('created_at', '<=', now()->subMinutes(self::NodeMinutes))
            ->whereNotExists(static fn (Builder $rows): Builder => $rows->from('task_vms')
                ->whereColumn('task_vms.node_id', 'nodes.id')->where('task_vms.state', '!=', TaskVmState::Destroyed->value))
            ->orderBy('id')->get()
            ->filter(static fn (Node $node): bool => preg_match(self::TaskVmName, $node->name) === 1)
            ->all());
    }

    /** The Node is a stray `tvm-<number>` Node ({@see self::strayNodes()}). */
    public static function isStrayNode(Node $node): bool
    {
        return preg_match(self::TaskVmName, $node->name) === 1
            && in_array($node->id, array_map(static fn (Node $stray): int => $stray->id, self::strayNodes()), true);
    }

    private static function ownsInstance(Node $node, string $name): bool
    {
        return preg_match(self::TaskVmName, $name) === 1 || $name === TaskVmPromotedImage::baseVm($node);
    }
}
