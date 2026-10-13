<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Tasks\TaskTopology;
use App\Models\Task;

/**
 * The size of a task VM, decided once at the claim from the group's declared topology and its Project. A group whose
 * subtasks declare no `topology` gets a normal VM with the host's size, which takes one slot and may be a warm VM, or
 * a medium VM when its Project is listed in `task_vms.medium_projects`. A topology gets the smallest size whose
 * `testNodes` holds its test nodes: a medium VM, which takes two slots, holds one, and a large VM, which takes three,
 * holds two. Medium and large VMs are always new, only run on an Incus host, and have free page reporting on. The size
 * never changes while the VM exists. A bigger size is one more case: {@see TaskTopology::maxNodes()} follows it.
 */
enum TaskVmSize: string
{
    case Normal = 'normal';
    case Medium = 'medium';
    case Large = 'large';

    /**
     * QEMU configuration of a medium or large VM: free page reporting on its memory balloon, so the host gets back the
     * memory that the guest frees. Without it, memory that a nested topology touched once stays allocated on the host.
     */
    private const string FreePageReporting = "[device \"qemu_balloon\"]\nfree-page-reporting = \"on\"\n";

    /**
     * What a VM of this size takes and holds: the one place that sizes are defined. Only a normal VM may be a warm VM.
     * A medium VM holds the test Gateway, one test node, and the operator (about 2.7 GiB in the spike) beside the
     * group's checks. In the spike, a 12 GiB large VM was full with two test nodes, so a third needs a bigger size.
     * Cases are in ascending order: the first that holds a topology is the smallest.
     */
    public function resources(): TaskVmResources
    {
        return match ($this) {
            self::Normal => new TaskVmResources(slots: 1, warmEligible: true),
            self::Medium => new TaskVmResources(slots: 2, warmEligible: false, cpus: 4, memory: '8GiB', disk: '40GiB', qemuConfig: self::FreePageReporting, testNodes: 1, topologyPool: '25GiB'),
            self::Large => new TaskVmResources(slots: 3, warmEligible: false, cpus: 6, memory: '12GiB', disk: '60GiB', qemuConfig: self::FreePageReporting, testNodes: 2, topologyPool: '45GiB'),
        };
    }

    /**
     * The size of the group's VM: by the test nodes of its declared topology, else medium when the group's Project is
     * one of `$mediumProjectIds`, else normal.
     *
     * @param  list<int>  $mediumProjectIds
     */
    public static function forGroup(Task $group, array $mediumProjectIds = []): self
    {
        $topology = TaskTopology::forGroup($group);

        return match (true) {
            ! $topology->isEmpty() => self::forTopology($topology),
            in_array((int) $group->project_id, $mediumProjectIds, true) => self::Medium,
            default => self::Normal,
        };
    }

    /**
     * The smallest size that holds a topology, or the largest when none does. The test Gateway and the operator run
     * in every topology and do not count.
     */
    public static function forTopology(TaskTopology $topology): self
    {
        foreach (self::cases() as $size) {
            if ($size->holds($topology)) {
                return $size;
            }
        }

        return self::cases()[array_key_last(self::cases())];
    }

    /** The slots of the host's `max_vms` that a VM of this size takes. */
    public function slots(): int
    {
        return $this->resources()->slots;
    }

    /** Whether a VM of this size can hold a topology. */
    public function holds(TaskTopology $topology): bool
    {
        return $topology->count() <= $this->resources()->testNodes;
    }
}
