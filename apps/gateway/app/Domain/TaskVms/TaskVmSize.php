<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Tasks\TaskTopology;
use App\Models\Task;

/**
 * The size of a task VM, decided once at the claim from the group's declared topology and its Project. A group whose
 * subtasks declare no `topology` gets a normal VM with the host's size, which takes one slot and may be a warm VM, or
 * a medium VM when its Project is listed in `task_vms.medium_projects`. A topology with one test node gets a medium
 * VM, which takes two slots, and one with two or more a large VM, which takes three. Medium and large VMs are always
 * new, only run on an Incus host, and have free page reporting on. The size never changes while the VM exists.
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
     * What a VM of this size takes: the one place that sizes are defined. Only a normal VM may be a warm VM. A medium VM
     * holds the test Gateway, one test node, and the operator (about 2.7 GiB in the spike) beside the group's checks.
     */
    public function resources(): TaskVmResources
    {
        return match ($this) {
            self::Normal => new TaskVmResources(slots: 1, warmEligible: true),
            self::Medium => new TaskVmResources(slots: 2, warmEligible: false, cpus: 4, memory: '8GiB', disk: '40GiB', qemuConfig: self::FreePageReporting),
            self::Large => new TaskVmResources(slots: 3, warmEligible: false, cpus: 6, memory: '12GiB', disk: '60GiB', qemuConfig: self::FreePageReporting),
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
            $topology !== [] => self::forTopology($topology),
            in_array((int) $group->project_id, $mediumProjectIds, true) => self::Medium,
            default => self::Normal,
        };
    }

    /**
     * The smallest size that holds a topology: medium for one test node, large for more. The test Gateway and the
     * operator run in every topology and do not count.
     *
     * @param  list<string>  $topology
     */
    public static function forTopology(array $topology): self
    {
        return match (count(TaskTopology::nodes($topology))) {
            0 => self::Normal,
            1 => self::Medium,
            default => self::Large,
        };
    }

    /** The slots of the host's `max_vms` that a VM of this size takes. */
    public function slots(): int
    {
        return $this->resources()->slots;
    }

    /**
     * Whether a VM of this size can hold a topology.
     *
     * @param  list<string>  $topology
     */
    public function holds(array $topology): bool
    {
        return self::forTopology($topology)->slots() <= $this->slots();
    }

    /** The size of the inner btrfs pool for nested topologies, below the root disk. Normal VMs run none. */
    public function topologyPoolSize(): string
    {
        return $this === self::Large ? '45GiB' : '25GiB';
    }
}
