<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Models\TaskVm;

/**
 * The size of a task VM, the cast of {@see TaskVm::$size}. Medium and large VMs take two and three slots,
 * only run on an Incus host, and have free page reporting on. The removed slot pool took a large VM; old rows keep their size.
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
     * What a VM of this size takes and holds: the one place that sizes are defined.
     * A medium VM holds the test Gateway, one test node, and the operator (about 2.7 GiB in the spike) beside the
     * group's checks. In the spike, a 12 GiB large VM was full with two test nodes, so a third needs a bigger size.
     */
    public function resources(): TaskVmResources
    {
        return match ($this) {
            self::Normal => new TaskVmResources,
            self::Medium => new TaskVmResources(cpus: 4, memory: '8GiB', disk: '40GiB', qemuConfig: self::FreePageReporting, testNodes: 1),
            self::Large => new TaskVmResources(cpus: 6, memory: '12GiB', disk: '60GiB', qemuConfig: self::FreePageReporting, testNodes: 2),
        };
    }
}
