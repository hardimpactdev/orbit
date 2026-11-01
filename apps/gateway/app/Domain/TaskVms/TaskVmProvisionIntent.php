<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;

/** Immutable allocation intent. Provider and guest work belongs to the native root. */
final readonly class TaskVmProvisionIntent
{
    public function __construct(private TaskVmSettings $settings, private TaskVmEnrollmentIntent $enrollment, private TaskVmEnrollmentWorkflowInput $workflow, private SwampSshExecutionInput $ssh) {}

    /** @return array<string,mixed> */
    public function capture(TaskVm $vm): array
    {
        if ($vm->state !== TaskVmState::Provisioning || $vm->name !== 'tvm-'.$vm->id
            || $vm->image_fingerprint === null || $vm->recipe_identity === null) {
            throw new ResourceOperationException('task_vm.provision_refused', 'Provision requires an allocated VM with its recorded recipe image.', 409);
        }
        if ($vm->provider !== TaskVmProviderKind::Incus) {
            throw new ResourceOperationException('operation.kind_unavailable', 'Native UpCloud provision requires its vault-backed bootstrap and supported network rollback.', 503);
        }
        $host = Node::query()->findOrFail($vm->host_node_id);
        if ($host->status !== LifecycleStatus::Active) {
            throw new ResourceOperationException('task_vm.provision_refused', 'The admitted provider host must be active.', 409);
        }
        $enrollment = $this->enrollment->capture($vm);
        $guest = $vm->node ?? throw new ResourceOperationException('task_vm.provision_refused', 'Provision requires its reserved guest Node.', 409);
        $enrollment = $this->workflow->fromCaptured($vm, $enrollment);
        $input = [...$enrollment, 'task_vm_id' => $vm->id, 'node_id' => $host->id, 'host_node_id' => $vm->host_node_id,
            'guest_node_id' => $guest->id, 'enrollment' => Arr::except($enrollment, ['execution_secrets']), 'name' => $vm->name,
            'provider' => $vm->provider->value, 'provider_id' => $vm->provider_id, 'wireguard_ip' => $vm->wireguard_ip,
            'image_fingerprint' => $vm->image_fingerprint, 'recipe_identity' => $vm->recipe_identity,
            'gateway_public_key' => $this->ssh->identity()['public_key'], 'vm' => null, 'create' => null, 'upcloud' => null];
        $location = $this->settings->host($host->id);
        $resources = $vm->size->resources();
        $input['vm'] = ['project' => $location->project, 'name' => $vm->name, 'cidr' => $location->cidr];
        $input['create'] = ['kind' => 'create', 'image' => $vm->image_fingerprint,
            'expected_fingerprint' => $vm->image_fingerprint, 'base_alias' => $location->imageAlias,
            'gateway_public_key' => $input['gateway_public_key'], 'cpus' => $resources->cpusOn($location),
            'memory' => $resources->memoryOn($location), 'disk' => $resources->diskOn($location),
            'network' => $location->network, 'pool' => $location->pool, 'topology' => $resources->testNodes > 0];

        return $input;
    }

    public function owned(Operation $operation, bool $lock = false): TaskVm
    {
        $taskRoot = $operation->kind === 'tasks.provision' && $operation->target_type === 'task_group';
        $vm = TaskVm::query()->when($lock, static fn ($query) => $query->lockForUpdate())->findOrFail($taskRoot ? ValidatedData::integer($operation->input['task_vm_id']) : $operation->target_id);
        $input = $operation->input;
        $guest = $vm->node;
        if ((! $taskRoot && ($operation->kind !== 'task_vm.provision' || $operation->target_type !== 'task_vm'))
            || ($taskRoot && $vm->group_id !== $operation->target_id)
            || ($input['task_vm_id'] ?? null) !== $vm->id || ($input['node_id'] ?? null) !== $operation->node_id
            || ($input['host_node_id'] ?? null) !== $vm->host_node_id || ($input['name'] ?? null) !== $vm->name
            || ($input['provider'] ?? null) !== $vm->provider->value || ($input['wireguard_ip'] ?? null) !== $vm->wireguard_ip
            || ($input['image_fingerprint'] ?? null) !== $vm->image_fingerprint || ($input['recipe_identity'] ?? null) !== $vm->recipe_identity
            || (($input['guest_node_id'] ?? null) !== null && $input['guest_node_id'] !== $vm->node_id)
            || (($input['provider_id'] ?? null) !== null && $input['provider_id'] !== $vm->provider_id)
            || ($vm->node_id !== null && (! $guest instanceof Node || $guest->name !== $vm->name || $guest->wireguard_ip !== $vm->wireguard_ip))) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The provision no longer owns its admitted VM, image and placement.', 422);
        }

        return $vm;
    }

    /** @return array<string,mixed> */
    public function input(Operation $operation): array
    {
        $vm = $this->owned($operation);

        return TaskVmProviderWorkflowInput::envelope([...$operation->input,
            'eligible' => $vm->state === TaskVmState::Provisioning || ($operation->kind === 'tasks.provision' && $vm->state === TaskVmState::Ready)]);
    }
}
