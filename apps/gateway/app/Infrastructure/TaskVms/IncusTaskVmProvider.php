<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\VmObservation;
use App\Infrastructure\AgentCommands\AgentGuest;
use App\Models\TaskVm;

/** The Gateway's placement and resource intent for task VMs on a managed Incus host. */
final readonly class IncusTaskVmProvider implements TaskVmProvider
{
    public function __construct(
        private IncusHost $incus,
        private TaskVmSettings $settings,
        private GuestBootstrap $bootstrap,
    ) {}

    /** @phpstan-impure */
    public function observe(TaskVm $vm): ?VmObservation
    {
        return $this->incus->vmState($this->settings->host((int) $vm->host_node_id), $vm->incusHostNode(), $vm->name);
    }

    public function sshHostFingerprint(TaskVm $vm): string
    {
        return $this->bootstrap->fingerprint($this->guest($vm), "task VM [{$vm->name}]");
    }

    public function bootstrapReady(TaskVm $vm): bool
    {
        return $this->bootstrap->ready($this->guest($vm), "task VM [{$vm->name}]");
    }

    private function guest(TaskVm $vm): AgentGuest
    {
        return AgentGuest::incus($vm->incusHostNode(), $this->settings->host((int) $vm->host_node_id)->project, $vm->name);
    }
}
