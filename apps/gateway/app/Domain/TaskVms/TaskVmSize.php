<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskTopology;
use App\Models\Task;

/**
 * The size of a task VM, decided once at the claim from the group's declared topology and its Project. A group whose
 * subtasks declare no `topology` gets a normal VM with the host's size, which takes one slot and may be a warm VM, or
 * a medium VM when its Project is listed in `task_vms.medium_projects`, which takes two slots and is always new. A
 * group with a topology gets a large VM, which takes three slots and is always new. Medium and large VMs only run on
 * an Incus host. The size never changes while the VM exists.
 */
enum TaskVmSize: string
{
    case Normal = 'normal';
    case Medium = 'medium';
    case Large = 'large';

    public const int MediumCpus = 4;

    public const string MediumMemory = '8GiB';

    public const int LargeCpus = 6;

    public const string LargeMemory = '12GiB';

    public const string LargeDisk = '60GiB';

    /**
     * QEMU configuration of a large VM: free page reporting on its memory balloon, so the host gets back the memory
     * that the guest frees. Without it, memory that a nested topology touched once stays allocated on the host.
     */
    public const string LargeQemuConfig = "[device \"qemu_balloon\"]\nfree-page-reporting = \"on\"\n";

    /**
     * The size of the group's VM: large when any subtask that is not cancelled declares a topology, else medium when the
     * group's Project is one of `$mediumProjectIds`, else normal.
     *
     * @param  list<int>  $mediumProjectIds
     */
    public static function forGroup(Task $group, array $mediumProjectIds = []): self
    {
        return match (true) {
            self::topology($group) !== [] => self::Large,
            in_array((int) $group->project_id, $mediumProjectIds, true) => self::Medium,
            default => self::Normal,
        };
    }

    /**
     * The union of the `topology` fields of the group's subtasks that are not cancelled, in role order.
     *
     * @return list<string>
     */
    public static function topology(Task $group): array
    {
        $declared = $group->tasks()->where('status', '!=', TaskStatus::Cancelled->value)->pluck('topology')
            ->flatMap(static fn (mixed $topology): array => TaskTopology::from($topology ?? []))
            ->all();

        return array_values(array_intersect(TaskTopology::Roles, $declared));
    }

    /** The slots of the host's `max_vms` that a VM of this size takes. */
    public function slots(): int
    {
        return match ($this) {
            self::Normal => 1,
            self::Medium => 2,
            self::Large => 3,
        };
    }

    /** Whether a VM of this size can hold the topology a subtask declares. */
    public function holds(Task $subtask): bool
    {
        return $this === self::Large || TaskTopology::from($subtask->topology ?? []) === [];
    }
}
