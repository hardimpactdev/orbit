<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmUpCloud;
use App\Domain\TaskVms\VmObservation;
use App\Infrastructure\AgentCommands\AgentGuest;
use App\Models\TaskVm;

/** Read-only observations for UpCloud task VMs. Native workflows own lifecycle changes. */
final readonly class UpCloudTaskVmProvider implements TaskVmProvider
{
    public function __construct(
        private UpCloudServers $servers,
        private UpCloudGuest $guest,
        private GuestBootstrap $bootstrap,
    ) {}

    public function observe(TaskVm $vm): ?VmObservation
    {
        $server = $this->server($vm);

        return $server === null ? null : new VmObservation($server->state === 'started', $server->address, settled: in_array($server->state, ['started', 'stopped'], true));
    }

    /** The key the Gateway generated and gave the server: the server proves it holds it on every SSH connection. */
    public function sshHostFingerprint(TaskVm $vm): string
    {
        return UpCloudGuest::fingerprint($this->hostKey($vm));
    }

    public function bootstrapReady(TaskVm $vm): bool
    {
        $address = $this->observe($vm)?->address;
        if ($address === null) {
            return false;
        }
        $guest = AgentGuest::ssh($vm->name, 'x86_64', $this->guest->connection($address, $this->hostKey($vm)));

        return $this->bootstrap->ready($guest, "task VM [{$vm->name}]");
    }

    private function server(TaskVm $vm): ?UpCloudServer
    {
        return $this->servers->find($vm->name, TaskVmUpCloud::Label, (string) $vm->id, $vm->provider_id);
    }

    private function hostKey(TaskVm $vm): string
    {
        return $vm->ssh_host_key ?? throw new TaskVmException('task_vm.host_key_failed', "Task VM [{$vm->name}] has no pinned host key.", 500);
    }
}
