<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Data\Tasks\TaskCapacityData;
use App\Data\Tasks\TaskPlaceHolderData;
use App\Data\Tasks\TaskPlaceHostData;
use App\Data\Tasks\TaskPlaceQueueData;
use App\Data\Tasks\TaskPlaceWaitData;
use App\Domain\Operations\OperationStatus;
use App\Domain\TaskVms\TaskVmCapacity;
use App\Domain\TaskVms\TaskVmRuntime;
use App\Models\Operation;
use App\Models\Task;
use DateTimeInterface;

/**
 * What a caller sees of task capacity (docs/reference/tasks.md#task-places): the places of each host, the VMs that hold them with the
 * group each one serves, and the queue of admissions that wait for a task place. It reads; placement stays in {@see TaskVmCapacity}.
 */
final readonly class TaskPlaces
{
    public function __construct(private TaskVmCapacity $capacity) {}

    public function capacity(): TaskCapacityData
    {
        $hosts = $this->capacity->activeHosts();
        $holders = $this->holders(array_map(static fn (array $pair): int => $pair[0]->nodeId, $hosts));

        // The view counts VM places; container places stay out until container placement is switched on.
        $taskFree = $this->capacity->freeSlots($hosts, false, TaskVmRuntime::Vm);
        $ciFree = $this->capacity->freeSlots($hosts, true, TaskVmRuntime::Vm);
        $places = [];
        foreach ($hosts as [$host, $node]) {
            $own = $holders[$host->nodeId] ?? [];
            $used = static fn (string $pool): int => count(array_filter($own, static fn (TaskPlaceHolderData $holder): bool => $holder->pool === $pool));
            $places[] = new TaskPlaceHostData($host->nodeId, $node->name, $host->taskPlaces, $used('task'), $taskFree[$host->nodeId],
                $host->ciPlaces, $used('ci'), $ciFree[$host->nodeId], $own);
        }

        return new TaskCapacityData(hosts: $places, queue: $this->queue());
    }

    /** The place in the task queue of a waiting admission on a group, or null for any other Operation. */
    public function waitOf(Operation $operation): ?TaskPlaceWaitData
    {
        if ($operation->status !== OperationStatus::Waiting || $operation->target_type !== 'task_group' || ! isset($operation->input['capacity_request'])) {
            return null;
        }
        $hosts = array_map(static fn (array $pair): int => $pair[0]->nodeId, $this->capacity->activeHosts());
        $holders = array_values(array_filter(array_merge(...array_values($this->holders($hosts))),
            static fn (TaskPlaceHolderData $holder): bool => $holder->pool === 'task'));

        return new TaskPlaceWaitData($operation->id, TaskVmAdmission::queue()->where('id', '<=', $operation->id)->count(), $holders);
    }

    /** The place in the task queue of the group's waiting admission, or null when the group waits for none. */
    public function waitOfGroup(Task $group): ?TaskPlaceWaitData
    {
        $operation = TaskVmAdmission::queue()->where('target_id', $group->id)->first();

        return $operation instanceof Operation ? $this->waitOf($operation) : null;
    }

    /** @return list<TaskPlaceQueueData> */
    private function queue(): array
    {
        $waiting = TaskVmAdmission::queue()->get();
        $groups = Task::topLevel()->whereKey($waiting->pluck('target_id')->all())->get(['id', 'title'])->keyBy('id');

        return array_values($waiting->values()->map(static fn (Operation $operation, int $index): TaskPlaceQueueData => new TaskPlaceQueueData(
            $index + 1, $operation->id, $operation->kind, $operation->target_id, $groups->get($operation->target_id)?->title,
            $operation->created_at?->format(DateTimeInterface::ATOM)))->all());
    }

    /**
     * @param  list<int>  $hosts
     * @return array<int, list<TaskPlaceHolderData>> host Node id => the VMs that hold its places, by slot
     */
    private function holders(array $hosts): array
    {
        $vms = $this->capacity->placed()->where('runtime', TaskVmRuntime::Vm)->whereIn('host_node_id', $hosts)->with('group')->orderBy('host_node_id')->orderBy('slot_number')->orderBy('id')->get();
        $activity = TaskGroupActivity::of(array_values(array_unique(array_filter($vms->pluck('group_id')->all(), is_int(...)))));
        $holders = [];
        foreach ($vms as $vm) {
            $group = $vm->group;
            $seen = $group instanceof Task ? $activity[$group->id] : null;
            $holders[(int) $vm->host_node_id][] = new TaskPlaceHolderData($vm->name, $vm->capacity_pool,
                $group?->id, $group?->title, $group?->groupStatus(), $seen['last_activity_at'] ?? null, $seen['active_operation_id'] ?? null);
        }

        return $holders;
    }
}
