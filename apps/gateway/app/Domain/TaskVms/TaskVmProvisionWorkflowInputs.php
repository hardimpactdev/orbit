<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Shared\ResourceOperationException;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;

/** Compose admitted public provider data with the enrollment owner's command-step envelope. */
final readonly class TaskVmProvisionWorkflowInputs
{
    /** @param array<string,mixed> $allocation
     * @param  array{intent:array<string,mixed>,execution_secrets:array<string,mixed>}  $enrollment
     * @return array{intent:array<string,mixed>,execution_secrets:array<string,mixed>}
     */
    public function compose(array $allocation, array $enrollment): array
    {
        $public = ValidatedData::object($enrollment['intent']);
        foreach (['task_vm_id', 'guest_node_id', 'gateway_node_id', 'issuer_node_id', 'model_router_node_id', 'provider', 'name'] as $field) {
            if (($public[$field] ?? null) !== ($allocation[$field] ?? null)) {
                throw new ResourceOperationException('task_vm.provision_refused', 'Provider and enrollment must use the same admitted public identity.', 409);
            }
        }
        if (($public['execution_node_id'] ?? null) !== ($allocation['node_id'] ?? null)) {
            throw new ResourceOperationException('task_vm.provision_refused', 'Provider and enrollment must use the same admitted executor.', 409);
        }

        return ['intent' => [...$public, ...Arr::only($allocation, [
            'task_vm_id', 'node_id', 'host_node_id', 'guest_node_id', 'gateway_node_id', 'issuer_node_id', 'model_router_node_id',
            'provider', 'provider_id', 'name', 'wireguard_ip', 'image_fingerprint', 'recipe_identity', 'gateway_public_key', 'vm', 'create', 'upcloud',
        ])], 'execution_secrets' => $enrollment['execution_secrets']];
    }
}
