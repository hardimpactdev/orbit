<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

/**
 * What a task VM of one size takes: its slots of the host's `max_vms`, whether a warm VM can serve it, and its CPU,
 * memory and disk. A null CPU, memory or disk is the host's own value. `qemuConfig` is raw QEMU configuration that
 * Incus can set only before the VM first starts.
 */
final readonly class TaskVmResources
{
    public function __construct(
        public int $slots,
        public bool $warmEligible,
        public ?int $cpus = null,
        public ?string $memory = null,
        public ?string $disk = null,
        public ?string $qemuConfig = null,
    ) {}

    public function cpusOn(TaskVmHost $host): int
    {
        return $this->cpus ?? $host->cpus;
    }

    public function memoryOn(TaskVmHost $host): string
    {
        return $this->memory ?? $host->memory;
    }

    public function diskOn(TaskVmHost $host): string
    {
        return $this->disk ?? $host->disk;
    }
}
