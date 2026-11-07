<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Infrastructure\Tasks\Pi\PiConnection;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVm;

/** Frozen provider and guest identity for a same-root native resume. No execution. */
final readonly class TaskVmResumeIntent
{
    public function __construct(private TaskVmSettings $settings, private PiConnection $pi, private TaskVmProviderWorkflowInput $provider, private SwampSshExecutionInput $ssh) {}

    /** @return array<string,mixed> */
    public function capture(TaskVm $vm): array
    {
        $guest = $vm->node;
        if (! in_array($vm->state, [TaskVmState::Parked, TaskVmState::Ready], true) || ! $guest instanceof Node || $guest->wireguard_ip !== $vm->wireguard_ip || $guest->user !== 'orbit'
            || $guest->status !== LifecycleStatus::Active || $guest->ssh_host_key === null
            || ($vm->state === TaskVmState::Parked && ($vm->pi_token === null || $this->settings->piArtifactSha256 === null))) {
            throw new ResourceOperationException('task_vm.resume_refused', 'Resume requires the parked VM, its active guest identity with its recorded host key, and its configured runtime.', 409);
        }
        $host = Node::query()->findOrFail($vm->host_node_id);
        if ($host->status !== LifecycleStatus::Active) {
            throw new ResourceOperationException('task_vm.resume_refused', 'Resume requires an active provider host.', 409);
        }
        $provider = $this->provider->capture($vm);

        return ['task_vm_id' => $vm->id, 'node_id' => $host->id, 'guest_node_id' => $guest->id,
            'name' => $vm->name, 'provider' => $vm->provider->value,
            'user' => $guest->user, 'pi_url' => $this->pi->baseUrl($guest),
            'admitted_state' => $vm->state->value, 'mode' => $vm->state === TaskVmState::Ready ? 'converge' : 'resume',
            'node' => ['node_id' => $guest->id, 'wireguard_ip' => $guest->wireguard_ip],
            'ssh_identity' => $this->ssh->identity(),
            // The end check reads the guest over its recorded host key pin; resume collects no new key.
            'ssh_execution' => $this->ssh->forNode($guest),
            'runtime_json' => json_encode(['pi_sha256' => $this->settings->piArtifactSha256, 'pi_port' => $this->pi->port(), 'pi_url' => $this->pi->baseUrl($guest)], JSON_THROW_ON_ERROR),
            ...$provider];
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
            || ($input['pi_url'] ?? null) !== $this->pi->baseUrl($guest)
            || ($vm->host_node_id !== $operation->node_id)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The resume no longer owns its admitted VM, guest and provider placement.', 422);
        }

        return $vm;
    }

    /** @return array<string,mixed> */
    public function input(Operation $operation): array
    {
        // Admission checked the state under the VM's Operation lock, so no step re-checks eligibility.
        $this->owned($operation);

        return TaskVmProviderWorkflowInput::envelope($operation->input);
    }
}
