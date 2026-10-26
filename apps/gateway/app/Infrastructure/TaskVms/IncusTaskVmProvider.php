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

    public function create(TaskVm $vm, string $gatewayPublicKey): void
    {
        $host = $this->settings->host((int) $vm->host_node_id);
        $resources = $vm->size->resources();
        $this->incus->changeVm($host, $vm->incusHostNode(), $vm->name, [
            'kind' => 'create', 'image' => $vm->image_fingerprint ?? $host->imageAlias,
            'expected_fingerprint' => $vm->recipe_identity === null ? null : $vm->image_fingerprint,
            'base_alias' => $host->imageAlias, 'gateway_public_key' => $gatewayPublicKey,
            'cpus' => $resources->cpusOn($host), 'memory' => $resources->memoryOn($host), 'disk' => $resources->diskOn($host),
            'network' => $host->network, 'pool' => $host->pool, 'topology' => $resources->testNodes > 0,
        ]);
    }

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

    public function start(TaskVm $vm): void
    {
        $this->change($vm, ['kind' => 'start']);
    }

    public function stop(TaskVm $vm): void
    {
        $this->change($vm, ['kind' => 'stop']);
    }

    public function destroy(TaskVm $vm): void
    {
        $this->change($vm, ['kind' => 'delete', 'pool' => $this->settings->host((int) $vm->host_node_id)->pool]);
    }

    /** @param array<string, mixed> $operation */
    private function change(TaskVm $vm, array $operation): void
    {
        $this->incus->changeVm($this->settings->host((int) $vm->host_node_id), $vm->incusHostNode(), $vm->name, $operation);
    }

    private function guest(TaskVm $vm): AgentGuest
    {
        return AgentGuest::incus($vm->incusHostNode(), $this->settings->host((int) $vm->host_node_id)->project, $vm->name);
    }
}
