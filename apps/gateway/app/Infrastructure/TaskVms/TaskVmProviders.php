<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\VmObservation;
use App\Models\TaskVm;

/** The `TaskVmProvider` of every job: sends each row to the provider that its `provider` names. */
final readonly class TaskVmProviders implements TaskVmProvider
{
    public function __construct(
        private IncusTaskVmProvider $incus,
        private UpCloudTaskVmProvider $upcloud,
    ) {}

    public function create(TaskVm $vm, string $userData): void
    {
        $this->for($vm)->create($vm, $userData);
    }

    public function observe(TaskVm $vm): ?VmObservation
    {
        return $this->for($vm)->observe($vm);
    }

    public function bootstrapReady(TaskVm $vm): bool
    {
        return $this->for($vm)->bootstrapReady($vm);
    }

    public function sshHostFingerprint(TaskVm $vm): string
    {
        return $this->for($vm)->sshHostFingerprint($vm);
    }

    public function imageManifest(TaskVm $vm): ?string
    {
        return $this->for($vm)->imageManifest($vm);
    }

    public function start(TaskVm $vm): void
    {
        $this->for($vm)->start($vm);
    }

    public function stop(TaskVm $vm): void
    {
        $this->for($vm)->stop($vm);
    }

    public function destroy(TaskVm $vm): void
    {
        $this->for($vm)->destroy($vm);
    }

    private function for(TaskVm $vm): TaskVmProvider
    {
        return match ($vm->provider) {
            TaskVm::Incus => $this->incus,
            TaskVm::UpCloud => $this->upcloud,
            default => throw new TaskVmException('task_vm.unknown_provider', "Task VM [{$vm->name}] has the unknown provider [{$vm->provider}].", 500),
        };
    }
}
