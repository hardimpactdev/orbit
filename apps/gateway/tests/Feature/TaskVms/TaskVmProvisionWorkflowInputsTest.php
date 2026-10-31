<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmProvisionWorkflowInputs;

/** @return array<string,mixed> */
function commandProvisionAllocation(): array
{
    return ['task_vm_id' => 12, 'node_id' => 9, 'host_node_id' => 9, 'guest_node_id' => 210,
        'gateway_node_id' => 14, 'issuer_node_id' => 209, 'model_router_node_id' => 6,
        'provider' => 'incus', 'provider_id' => null, 'name' => 'tvm-12', 'wireguard_ip' => '10.44.0.210',
        'image_fingerprint' => str_repeat('a', 64), 'recipe_identity' => 'sha256:'.str_repeat('b', 64),
        'vm' => ['project' => 'orbit-tasks', 'name' => 'tvm-12', 'cidr' => '10.240.9.0/24'],
        'create' => ['expected_fingerprint' => str_repeat('a', 64), 'cpus' => 2], 'upcloud' => null,
        'private_bindings' => ['pi_token' => ['read_node_ids' => [9]]],
        'pi_token' => 'old-private-token', 'model_key' => 'old-private-key',
        'installation' => ['$secret' => 'task_vm.installation', 'id' => 12]];
}

/** @return array{intent:array<string,mixed>,execution_secrets:array<string,mixed>} */
function commandProvisionEnrollment(): array
{
    return ['intent' => ['task_vm_id' => 12, 'execution_node_id' => 9, 'guest_node_id' => 210,
        'gateway_node_id' => 14, 'issuer_node_id' => 209, 'model_router_node_id' => 6,
        'provider' => 'incus', 'name' => 'tvm-12', 'user' => 'orbit', 'runtime' => ['pi_sha256' => str_repeat('c', 64)]],
        'execution_secrets' => ['pi_token' => ['vault' => 'orbit-control-inputs', 'key' => 'task-vm-12-pi-token']]];
}

it('composes full public provider and enrollment intent without copying the private-material protocol', function (): void {
    $result = new TaskVmProvisionWorkflowInputs()->compose(commandProvisionAllocation(), commandProvisionEnrollment());

    expect($result['intent'])->toMatchArray(['task_vm_id' => 12, 'node_id' => 9, 'guest_node_id' => 210,
        'image_fingerprint' => str_repeat('a', 64), 'runtime' => ['pi_sha256' => str_repeat('c', 64)]]);
    expect($result['execution_secrets'])->toBe(['pi_token' => ['vault' => 'orbit-control-inputs', 'key' => 'task-vm-12-pi-token']]);
    foreach (['old-private-token', 'old-private-key', '$secret', 'private_bindings'] as $private) {
        expect(json_encode($result, JSON_THROW_ON_ERROR))->not->toContain($private);
    }
});

it('refuses a fragment for a different provider or participant before a native start', function (string $field): void {
    $enrollment = commandProvisionEnrollment();
    $enrollment['intent'][$field] = in_array($field, ['provider', 'name'], true) ? 'foreign' : 999;

    expect(fn () => new TaskVmProvisionWorkflowInputs()->compose(commandProvisionAllocation(), $enrollment))
        ->toThrow(ResourceOperationException::class);
})->with(['task_vm_id', 'execution_node_id', 'guest_node_id', 'gateway_node_id', 'issuer_node_id', 'model_router_node_id', 'provider', 'name']);
