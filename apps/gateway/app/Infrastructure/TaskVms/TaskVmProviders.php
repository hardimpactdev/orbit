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
        return $this->for($vm)->observe($vm);
    }

    public function sshHostFingerprint(TaskVm $vm): string
    {
        return $this->for($vm)->sshHostFingerprint($vm);
    }

    public function bootstrapReady(TaskVm $vm): bool
    {
        return $this->for($vm)->bootstrapReady($vm);
    }

    private function for(TaskVm $vm): TaskVmProvider
    {
        return match ($vm->provider) {
            TaskVmProviderKind::Incus => $this->incus,
            TaskVmProviderKind::UpCloud => $this->upcloud,
        };
    }
}
