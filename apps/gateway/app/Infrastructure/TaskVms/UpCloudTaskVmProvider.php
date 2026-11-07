<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmUpCloud;
use App\Domain\TaskVms\VmObservation;
use App\Models\TaskVm;

/** Read-only observations for UpCloud task VMs. Native workflows own lifecycle changes. */
final readonly class UpCloudTaskVmProvider implements TaskVmProvider
{
    public function __construct(private UpCloudServers $servers) {}

    public function observe(TaskVm $vm): ?VmObservation
    {
        $server = $this->servers->find($vm->name, TaskVmUpCloud::Label, (string) $vm->id, $vm->provider_id);

        return $server === null ? null : new VmObservation($server->state === 'started', $server->address, settled: in_array($server->state, ['started', 'stopped'], true));
    }
}
