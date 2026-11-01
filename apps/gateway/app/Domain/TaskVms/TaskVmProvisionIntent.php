<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Infrastructure\TaskVms\UpCloudApi;
use App\Infrastructure\TaskVms\UpCloudGuest;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;

/** Immutable allocation intent. Provider and guest work belongs to the native root. */
final readonly class TaskVmProvisionIntent
{
    public function __construct(private TaskVmSettings $settings, private TaskVmEnrollmentIntent $enrollment, private TaskVmEnrollmentWorkflowInput $workflow, private SwampSshExecutionInput $ssh, private SwampCommandStep $shared, private UpCloudApi $upcloud, private UpCloudGuest $cloudGuest) {}

    /** @return array<string,mixed> */
    public function capture(TaskVm $vm): array
    {
        if ($vm->state !== TaskVmState::Provisioning || $vm->name !== 'tvm-'.$vm->id
            || $vm->image_fingerprint === null || $vm->recipe_identity === null) {
            throw new ResourceOperationException('task_vm.provision_refused', 'Provision requires an allocated VM with its recorded recipe image.', 409);
        }
        $incus = $vm->provider === TaskVmProviderKind::Incus;
        $host = $incus ? Node::query()->findOrFail($vm->host_node_id) : Node::query()->where('status', LifecycleStatus::Active)
            ->whereHas('roles', static fn ($q) => $q->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active))->sole();
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
        if (! $incus) {
            $cloud = $this->settings->upcloud ?? throw new ResourceOperationException('operation.kind_unavailable', 'UpCloud is not configured.', 503);
            $bootstrap = $vm->provider_bootstrap ?? $this->cloudGuest->generateHostKey($vm->name);
            if ($vm->ssh_host_key !== null && $vm->ssh_host_key !== $bootstrap['public']) {
                throw new ResourceOperationException('operation.execution_forbidden', 'The provider bootstrap no longer matches the pinned VM identity.', 422);
            }
            UpCloudGuest::fingerprint($bootstrap['public']);
            $vm->update(['provider_bootstrap' => $bootstrap, 'ssh_host_key' => $bootstrap['public']]);
            $input['bootstrap_host_key'] = $bootstrap['public'];
            $input['upcloud'] = ['zone' => $cloud->zone, 'plan' => $cloud->plan, 'disk_gb' => $cloud->diskGb,
                'max_vms' => $cloud->maxVms, 'source_template' => $vm->image_fingerprint];
            $input['execution_secrets'] = [...ValidatedData::object($input['execution_secrets']),
                'upcloud' => $this->shared->executionCopy('orbit-control-inputs', 'task-vm-upcloud-token', $this->upcloud->token()),
                'bootstrap' => $this->shared->executionCopy('orbit-control-inputs', 'task-vm-provider-bootstrap-'.$vm->id, json_encode($bootstrap, JSON_THROW_ON_ERROR))];

            return $input;
        }
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
            || ($vm->provider === TaskVmProviderKind::UpCloud && ($input['bootstrap_host_key'] ?? null) !== $vm->ssh_host_key)
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
