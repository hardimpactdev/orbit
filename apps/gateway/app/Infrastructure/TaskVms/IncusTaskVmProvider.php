<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\VmObservation;
use App\Models\TaskVm;

/** Reads the power of a task VM on a managed Incus host. */
final readonly class IncusTaskVmProvider implements TaskVmProvider
{
    public function __construct(
        private IncusHost $incus,
        private TaskVmSettings $settings,
    ) {}

    /** @phpstan-impure */
    public function observe(TaskVm $vm): ?VmObservation
    {
        return $this->incus->vmState($this->settings->host((int) $vm->host_node_id), $vm->incusHostNode(), $vm->name);
    }
}
