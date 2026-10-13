<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

/**
 * Where a task VM runs, as its row's `provider` stores it. An `incus` VM runs on a configured host Node: the host's
 * `max_vms` slots bound it, and the host holds its workspace templates. An `upcloud` VM is an UpCloud server, the
 * overflow of the Incus hosts: it has no host Node, a fixed plan, and costs money for as long as it exists.
 */
enum TaskVmProviderKind: string
{
    case Incus = 'incus';
    case UpCloud = 'upcloud';

    /** Whether the VM runs on a host Node, whose id its row holds in `host_node_id`. */
    public function needsHostNode(): bool
    {
        return $this === self::Incus;
    }

    /**
     * Whether the provider runs a VM of this size. UpCloud runs only a normal VM: it has a fixed plan, and no nested
     * virtualization that a topology relies on.
     */
    public function supports(TaskVmSize $size): bool
    {
        return $this === self::Incus || $size === TaskVmSize::Normal;
    }

    /** Whether a claim may attach a workspace template volume, which lives on an Incus host. */
    public function hasWorkspaceTemplates(): bool
    {
        return $this === self::Incus;
    }

    /**
     * Whether a failed VM still counts against the provider's `max_vms`. A failed Incus VM holds no host slot, so it
     * never blocks placement. A failed UpCloud server costs money until it is destroyed, so it counts against the
     * cost cap.
     */
    public function countsFailedVms(): bool
    {
        return $this === self::UpCloud;
    }
}
