<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Tasks\Pi\PiConnection;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVm;
use Illuminate\Support\Arr;

/** Frozen provider and guest identity for a same-root native resume. No execution. */
final readonly class TaskVmResumeIntent
{
    public function __construct(private TaskVmSettings $settings, private AgentDelivery $agents, private PiConnection $pi) {}

    /** @return array<string,mixed> */
    public function capture(TaskVm $vm): array
    {
        $guest = $vm->node;
        if ($vm->state !== TaskVmState::Parked || ! $guest instanceof Node || $guest->wireguard_ip !== $vm->wireguard_ip
            || $guest->status !== LifecycleStatus::Active || $vm->pi_token === null || $this->settings->piArtifactSha256 === null) {
            throw new ResourceOperationException('task_vm.resume_refused', 'Resume requires the parked VM, its active guest identity and its configured runtime.', 409);
        }
        $host = $vm->provider === TaskVmProviderKind::Incus ? Node::query()->findOrFail($vm->host_node_id)
            : Node::query()->where('status', LifecycleStatus::Active)->whereHas('roles', static fn ($q) => $q->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active))->firstOrFail();
        if ($host->status !== LifecycleStatus::Active) {
            throw new ResourceOperationException('task_vm.resume_refused', 'Resume requires an active provider host.', 409);
        }
        $location = $vm->provider === TaskVmProviderKind::Incus ? $this->settings->host($host->id) : null;

        return ['task_vm_id' => $vm->id, 'node_id' => $host->id, 'guest_node_id' => $guest->id,
            'participant_node_ids' => array_values(array_unique([$host->id, $guest->id])),
            'private_bindings' => [
                'pi_token' => ['owner_node_id' => $guest->id, 'read_node_ids' => [$host->id], 'write_node_ids' => []],
                'upcloud_token' => ['owner_node_id' => $host->id, 'read_node_ids' => $vm->provider === TaskVmProviderKind::UpCloud ? [$host->id] : [], 'write_node_ids' => []],
            ],
            'name' => $vm->name, 'provider' => $vm->provider->value, 'provider_id' => $vm->provider_id,
            'agent_sha256' => $this->agents->pin($host), 'guest_agent_sha256' => $this->agents->pin($guest),
            'user' => $guest->user, 'pi_sha256' => $this->settings->piArtifactSha256, 'pi_url' => $this->pi->baseUrl($guest),
            'deadline_at' => now()->addSeconds(540)->timestamp,
            'vm' => $location === null ? null : ['project' => $location->project, 'name' => $vm->name, 'cidr' => $location->cidr],
            'pi_token' => ['$secret' => 'task_vm.pi_token', 'id' => $vm->id],
            'upcloud_token' => $vm->provider === TaskVmProviderKind::UpCloud ? ['$secret' => 'upcloud.api', 'id' => $vm->id] : null];
    }

    public function owned(Operation $operation, bool $lock = false): TaskVm
    {
        $input = $operation->input;
        $vm = TaskVm::query()->when($lock, static fn ($query) => $query->lockForUpdate())->findOrFail($operation->target_id);
        $guest = $vm->node;
        if ($operation->kind !== 'task_vm.resume' || $operation->target_type !== 'task_vm'
            || ! $guest instanceof Node || $guest->status !== LifecycleStatus::Active
            || $guest->wireguard_ip !== $vm->wireguard_ip
            || ($input['task_vm_id'] ?? null) !== $vm->id || ($input['guest_node_id'] ?? null) !== $guest->id
            || ($input['node_id'] ?? null) !== $operation->node_id || ($input['user'] ?? null) !== $guest->user
            || ($input['name'] ?? null) !== $vm->name || ($input['provider'] ?? null) !== $vm->provider->value
            || ($input['provider_id'] ?? null) !== $vm->provider_id || ($input['pi_url'] ?? null) !== $this->pi->baseUrl($guest)
            || ($vm->provider === TaskVmProviderKind::Incus && $vm->host_node_id !== $operation->node_id)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The resume no longer owns its admitted VM, guest and provider placement.', 422);
        }

        return $vm;
    }

    /** @return array<string,mixed> */
    public function input(Operation $operation): array
    {
        $vm = $this->owned($operation);

        return [...Arr::except($operation->input, ['pi_token', 'upcloud_token', 'participant_node_ids', 'private_bindings']), 'eligible' => $vm->state === TaskVmState::Parked];
    }
}
