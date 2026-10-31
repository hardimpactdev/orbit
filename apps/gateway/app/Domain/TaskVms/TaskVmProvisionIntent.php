<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;

/** Immutable allocation intent. Provider and guest work belongs to the native root. */
final readonly class TaskVmProvisionIntent
{
    public function __construct(private TaskVmSettings $settings, private AgentDelivery $agents, private SshKeyProvider $keys, private TaskVmEnrollmentIntent $enrollment) {}

    /** @return array<string,mixed> */
    public function capture(TaskVm $vm): array
    {
        if ($vm->state !== TaskVmState::Provisioning || $vm->name !== 'tvm-'.$vm->id
            || $vm->image_fingerprint === null || $vm->recipe_identity === null) {
            throw new ResourceOperationException('task_vm.provision_refused', 'Provision requires an allocated VM with its recorded recipe image.', 409);
        }
        $gateway = Node::query()->where('status', LifecycleStatus::Active)
            ->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active))->firstOrFail();
        $host = $vm->provider === TaskVmProviderKind::Incus ? Node::query()->findOrFail($vm->host_node_id) : $gateway;
        if ($host->status !== LifecycleStatus::Active) {
            throw new ResourceOperationException('task_vm.provision_refused', 'The admitted provider worker host must be active.', 409);
        }
        $enrollment = $this->enrollment->capture($vm);
        $guest = $vm->node ?? throw new ResourceOperationException('task_vm.provision_refused', 'Provision requires its reserved guest Node.', 409);
        $private = $this->enrollment->privateInput($vm, $host->id, providerCreate: true);
        $private['private_bindings']['upcloud_token'] = ['owner_node_id' => $host->id, 'read_node_ids' => $vm->provider === TaskVmProviderKind::UpCloud ? [$host->id] : [], 'write_node_ids' => []];
        $enrollment = [...$enrollment, 'network' => $private['network'],
            'gateway_node_id' => $private['gateway_node_id'], 'issuer_node_id' => $private['issuer_node_id'],
            'model_router_node_id' => $private['model_router_node_id'], 'model_origin' => $private['model_origin'],
            'wireguard_public_key' => $guest->wireguard_public_key];
        $input = [...Arr::except($private, ['network']), 'task_vm_id' => $vm->id, 'node_id' => $host->id, 'host_node_id' => $vm->host_node_id,
            'guest_node_id' => $guest->id, 'enrollment' => $enrollment, 'name' => $vm->name,
            'provider' => $vm->provider->value, 'provider_id' => $vm->provider_id, 'wireguard_ip' => $vm->wireguard_ip,
            'image_fingerprint' => $vm->image_fingerprint, 'recipe_identity' => $vm->recipe_identity,
            'agent_sha256' => $this->agents->pin($host), 'deadline_at' => now()->addSeconds(1500)->toIso8601String(),
            'gateway_public_key' => $this->keys->publicKey(), 'vm' => null, 'create' => null, 'upcloud' => null];
        if ($vm->provider === TaskVmProviderKind::Incus) {
            $location = $this->settings->host($host->id);
            $resources = $vm->size->resources();
            $input['vm'] = ['project' => $location->project, 'name' => $vm->name, 'cidr' => $location->cidr];
            $input['create'] = ['kind' => 'create', 'image' => $vm->image_fingerprint,
                'expected_fingerprint' => $vm->image_fingerprint, 'base_alias' => $location->imageAlias,
                'gateway_public_key' => $input['gateway_public_key'], 'cpus' => $resources->cpusOn($location),
                'memory' => $resources->memoryOn($location), 'disk' => $resources->diskOn($location),
                'network' => $location->network, 'pool' => $location->pool, 'topology' => $resources->testNodes > 0];
        } else {
            $upcloud = $this->settings->upcloud ?? throw new ResourceOperationException('task_vm.provision_refused', 'UpCloud placement has no configured provider.', 409);
            $input['upcloud'] = ['zone' => $upcloud->zone, 'plan' => $upcloud->plan, 'disk_gb' => $upcloud->diskGb, 'max_vms' => $upcloud->maxVms, 'source_template' => $vm->image_fingerprint, 'gateway_public_key' => $input['gateway_public_key']];
            $input['upcloud_token'] = ['$secret' => 'upcloud.api', 'id' => $vm->id];
        }

        return $input;
    }

    public function owned(Operation $operation, bool $lock = false): TaskVm
    {
        $vm = TaskVm::query()->when($lock, static fn ($query) => $query->lockForUpdate())->findOrFail($operation->target_id);
        $input = $operation->input;
        $guest = $vm->node;
        if ($operation->kind !== 'task_vm.provision' || $operation->target_type !== 'task_vm'
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

        return [...Arr::except($operation->input, ['upcloud_token', 'installation', 'enrollment_network', 'first_visit', 'model_key', 'model_router', 'pi_token', 'pi_artifact_file', 'pi_artifact', 'secret_hash', 'worker_credentials', 'provider_bootstrap']),
            'enrollment' => [...ValidatedData::object($operation->input['enrollment']), 'wireguard_public_key' => $vm->node?->wireguard_public_key],
            'eligible' => $vm->state === TaskVmState::Provisioning];
    }
}
