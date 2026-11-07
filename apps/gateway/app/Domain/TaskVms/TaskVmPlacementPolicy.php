<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskWait;
use App\Models\Task;

/** Rank free slots on Incus hosts; a group waits when no host has room. */
final readonly class TaskVmPlacementPolicy
{
    public const string NoCapacity = 'Task VM: no capacity.';

    /**
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
        throw new TaskCapacityException(TaskWait::Capacity, $size === TaskVmSize::Normal && $reserve === 0 ? self::NoCapacity : self::noCapacityFor($size, $reserve));
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

    /** The slots a report-only proof leaves free on a host: one large VM, so a critical-path run for one command still finds room. */
    public static function reportOnlyReserve(): int
    {
        return TaskVmSize::Large->slots();
    }
}
