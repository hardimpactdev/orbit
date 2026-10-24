<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmGuestCommand;
use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\VmObservation;
use App\Infrastructure\Processes\CommandResult;
use App\Models\TaskVm;

/** The `TaskVmProvider` of every job: sends each row to the provider of its `provider` kind. */
final readonly class TaskVmProviders implements TaskVmProvider
{
    public function __construct(
        private IncusTaskVmProvider $incus,
        private UpCloudTaskVmProvider $upcloud,
    ) {}

    public function create(TaskVm $vm, string $gatewayPublicKey): void
    {
        $this->for($vm)->create($vm, $gatewayPublicKey);
    }

    public function observe(TaskVm $vm): ?VmObservation
    {
        return $this->for($vm)->observe($vm);
    }

    public function sshHostFingerprint(TaskVm $vm): string
    {
        return $this->for($vm)->sshHostFingerprint($vm);
    }

    public function exec(TaskVm $vm, TaskVmGuestCommand $command): CommandResult
    {
        return $this->for($vm)->exec($vm, $command);
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
            TaskVmProviderKind::Incus => $this->incus,
            TaskVmProviderKind::UpCloud => $this->upcloud,
        };
    }
}
