<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\VmObservation;
use App\Models\TaskVm;

/** The `TaskVmProvider` of every job: sends each row to the provider of its `provider` kind. */
final readonly class TaskVmProviders implements TaskVmProvider
{
    public function __construct(
        private IncusTaskVmProvider $incus,
        private UpCloudTaskVmProvider $upcloud,
    ) {}

    public function observe(TaskVm $vm): ?VmObservation
    {
        return match ($vm->provider) {
            TaskVmProviderKind::Incus => $this->incus->observe($vm),
            TaskVmProviderKind::UpCloud => $this->upcloud->observe($vm),
        };
    }
}
