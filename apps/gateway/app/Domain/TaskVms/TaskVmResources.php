<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

/**
 * What a task VM of one size takes: its CPU, memory and disk. `qemuConfig` is raw QEMU configuration that
 * Incus can set only before the VM first starts. `testNodes` is how many declared test nodes its nested topology may
 * have, beside the test Gateway and the operator. A size with test Nodes boots with a clone of the host's topology
 * seed, which is the pool its nested topology uses.
 */
final readonly class TaskVmResources
{
    public function __construct(
        public ?int $cpus = null,
        public ?string $memory = null,
        public ?string $disk = null,
        public ?string $qemuConfig = null,
        public int $testNodes = 0,
    ) {}
}
