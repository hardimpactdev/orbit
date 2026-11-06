<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskWait;
use App\Models\Task;
use App\Models\TaskVm;

/**
 * Where a task VM goes: rank free slots. Hosts with as many free slots keep their config order. A size that no host has room for overflows to UpCloud when the rules
 * allow it, else the group waits.
 */
final readonly class TaskVmPlacementPolicy
{
    public const string NoCapacity = 'Task VM: no capacity.';

    public function __construct(private TaskVmSettings $settings) {}

    /**
     * The active host with the most free slots, when it has room for the size. Hosts with as many free slots keep
     * their config order. Without room, a VM of a size that UpCloud supports goes to UpCloud: only while the overflow
     * is on, the group's Project is opted in, and fewer than `max_vms` UpCloud VMs count against its cap, failed and
     * parked ones too. This row cap is the placement rule; the native UpCloud provider child also counts the account's servers
     * before each create, as its own cost guard. A VM of another size waits for an Incus host.
     *
     * @param  array<int, int>  $free  host Node id => free slots, in config order
     *
     * @throws TaskCapacityException when the VM fits nowhere
     */
    public function choose(TaskVmSize $size, Task $group, array $free, int $reserve = 0): TaskVmPlacementTarget
    {
        $best = null;
        $bestFree = $size->slots() - 1;
        foreach ($free as $hostId => $slots) {
            if ($slots > $bestFree) {
                [$best, $bestFree] = [$hostId, $slots];
            }
        }
        if ($best !== null) {
            return TaskVmPlacementTarget::incusHost($best);
        }
        // A report-only proof yields: it keeps `$reserve` slots free on an Incus host and never spends on UpCloud.
        if ($reserve > 0 || ! TaskVmProviderKind::UpCloud->supports($size)) {
            throw new TaskCapacityException(TaskWait::Capacity, self::noCapacityFor($size, $reserve));
        }
        $upcloud = $this->settings->upcloud;
        if ($upcloud?->allows($group->project_id) === true
            && TaskVm::query()->countedAgainstCap(TaskVmProviderKind::UpCloud)->count() < $upcloud->maxVms) {
            return TaskVmPlacementTarget::upCloud();
        }
        throw new TaskCapacityException(TaskWait::Capacity, self::NoCapacity);
    }

    /**
     * The wait reason of a VM that only an Incus host can run, while no host has its free slots: `Task VM: no host has 3
     * free slots for a large VM.` for two test nodes or a Project in `task_vms.large_projects`, or 2 for a medium VM:
     * one test node or a Project in `task_vms.medium_projects`.
     */
    public static function noCapacityFor(TaskVmSize $size, int $reserve = 0): string
    {
        $message = "Task VM: no host has {$size->slots()} free slots for a {$size->value} VM.";

        return $reserve > 0 ? $message." A report-only proof leaves {$reserve} slots free for task and proof work." : $message;
    }

    /** The slots a report-only proof leaves free on a host: one large VM, so a critical-path run always finds room. */
    public static function reportOnlyReserve(): int
    {
        return TaskVmSize::Large->slots();
    }
}
