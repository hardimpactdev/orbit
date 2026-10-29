<?php

declare(strict_types=1);

use App\Domain\Operations\OperationSecrets;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\TaskVm;

it('refuses a model credential reference on another target type even when IDs overlap', function (): void {
    $vm = TaskVm::query()->create(['name' => 'tvm-1', 'provider' => 'incus', 'state' => 'provisioning', 'wireguard_ip' => '10.44.0.129', 'model_key' => 'test-model-key']);
    $operation = new Operation(['kind' => 'task_vm.model_issue', 'target_type' => 'task_vm_image', 'target_id' => $vm->id]);
    expect(fn () => app(OperationSecrets::class)->references($operation, ['key' => ['$secret' => 'task_vm.model_key', 'id' => $vm->id]], ['key' => 'task_vm.model_key']))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.execution_forbidden'));
});

it('refuses UpCloud hydration for an Incus VM before reading the configured token file', function (): void {
    $vm = TaskVm::query()->create(['name' => 'tvm-1', 'provider' => 'incus', 'state' => 'provisioning', 'wireguard_ip' => '10.44.0.129']);
    $operation = new Operation(['kind' => 'task_vm.upcloud_create', 'target_type' => 'task_vm', 'target_id' => $vm->id]);
    expect(fn () => app(OperationSecrets::class)->references($operation, ['token' => ['$secret' => 'upcloud.api', 'id' => $vm->id]], ['token' => 'upcloud.api']))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.execution_forbidden'));
});

it('refuses a package cache credential on a different execution Node', function (): void {
    $operation = new Operation(['kind' => 'task_vm.fill_package_cache', 'target_type' => 'task_vm_cache', 'target_id' => 4, 'node_id' => 5]);
    expect(fn () => app(OperationSecrets::class)->references($operation, ['github_token' => ['$secret' => 'task_vm.package_cache_github', 'id' => 4]], ['github_token' => 'task_vm.package_cache_github']))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.execution_forbidden'));
});
