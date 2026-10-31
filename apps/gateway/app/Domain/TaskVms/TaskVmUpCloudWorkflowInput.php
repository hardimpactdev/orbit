<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\TaskVms\UpCloudApi;
use App\Models\TaskVm;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Capture existing provider credentials once; API work belongs to placed native steps. */
final readonly class TaskVmUpCloudWorkflowInput
{
    public function __construct(private TaskVmProviderHostKey $keys, private UpCloudApi $api, private SwampCommandStep $shared) {}

    /** @param array<string,mixed> $allocation
     * @return array{intent:array<string,mixed>,execution_secrets:array<string,array{vault:string,key:string}>}
     */
    public function capture(TaskVm $vm, array $allocation): array
    {
        $gatewayId = $allocation['gateway_node_id'] ?? null;
        if ($vm->provider !== TaskVmProviderKind::UpCloud || $vm->state !== TaskVmState::Provisioning
            || $vm->name !== 'tvm-'.$vm->id || ($allocation['task_vm_id'] ?? null) !== $vm->id
            || ($allocation['provider'] ?? null) !== 'upcloud' || ($allocation['name'] ?? null) !== $vm->name
            || ! array_key_exists('provider_id', $allocation) || $allocation['provider_id'] !== $vm->provider_id
            || ! is_int($gatewayId) || $gatewayId < 1 || ($allocation['node_id'] ?? null) !== $gatewayId
            || ($allocation['image_fingerprint'] ?? null) !== $vm->image_fingerprint
            || $vm->image_fingerprint === null || ($allocation['recipe_identity'] ?? null) !== $vm->recipe_identity
            || ! is_array($allocation['upcloud'] ?? null)
            || ($allocation['upcloud']['source_template'] ?? null) !== $vm->image_fingerprint) {
            throw $this->refused();
        }
        $pair = DB::transaction(function () use ($vm): array {
            $current = TaskVm::query()->lockForUpdate()->findOrFail($vm->id);
            if ($current->state !== TaskVmState::Provisioning || $current->name !== $vm->name
                || $current->provider !== TaskVmProviderKind::UpCloud || $current->provider_id !== $vm->provider_id
                || $current->image_fingerprint !== $vm->image_fingerprint || $current->recipe_identity !== $vm->recipe_identity) {
                throw $this->refused();
            }
            $pair = $current->provider_bootstrap;
            if ($pair === null) {
                if ($current->ssh_host_key !== null || $current->provider_id !== null) {
                    throw $this->refused();
                }
                $pair = $this->keys->generate($current->name);
                $current->update(['provider_bootstrap' => $pair, 'ssh_host_key' => $pair['public']]);
            }
            if ($current->ssh_host_key !== $pair['public']) {
                throw $this->refused();
            }

            return $pair;
        });

        return ['intent' => [...Arr::only($allocation, ['task_vm_id', 'node_id', 'gateway_node_id', 'provider', 'provider_id', 'name', 'image_fingerprint', 'recipe_identity']),
            'upcloud' => Arr::only($allocation['upcloud'], ['zone', 'plan', 'disk_gb', 'max_vms', 'source_template', 'gateway_public_key']),
            'provider_host_public_key' => $pair['public']], 'execution_secrets' => [
                'upcloud_token' => $this->shared->executionCopy('orbit-control-inputs', 'upcloud-api-gateway-'.$gatewayId, $this->api->token()),
                'provider_bootstrap' => $this->shared->executionCopy('orbit-control-inputs', 'task-vm-provider-bootstrap-'.$vm->id, json_encode($pair, JSON_THROW_ON_ERROR)),
            ]];
    }

    private function refused(): ResourceOperationException
    {
        return new ResourceOperationException('task_vm.provision_refused', 'UpCloud requires its allocated VM, image, Gateway and host pin.', 409);
    }
}
