<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\ModelKeyIssuer;
use App\Domain\TaskVms\TaskVmModelKeyInputs;
use App\Models\Operation;
use App\Models\TaskVm;

final class ModelKeyStepIssuer implements ModelKeyIssuer
{
    /** @var list<array{origin:string,node_id:int}> */
    public array $reads = [];

    public function assertReady(): void {}

    public function origin(): string
    {
        return 'http://10.44.0.3:8317';
    }

    public function ownerNodeId(): int
    {
        return 3;
    }

    public function privateMaterial(string $origin, int $ownerNodeId): array
    {
        $this->reads[] = ['origin' => $origin, 'node_id' => $ownerNodeId];

        return ['management_key' => 'synthetic-management', 'anchor_key' => str_repeat('2', 64)];
    }
}

/** @return array<string,array<string,string>> */
function modelKeyStepDescriptors(): array
{
    return ['model_key' => ['vault' => 'orbit-control-inputs', 'key' => 'task-vm-model-key-42'],
        'model_router_management' => ['vault' => 'orbit-control-inputs', 'key' => 'model-router-management-node-3'],
        'model_router_anchor' => ['vault' => 'orbit-control-inputs', 'key' => 'model-router-anchor-node-3']];
}

function modelKeyStepOperation(string $kind = 'task_vm.destroy'): Operation
{
    return (new Operation)->forceFill(['id' => 7001, 'kind' => $kind, 'node_id' => 9,
        'target_type' => 'task_vm', 'target_id' => 42,
        'input' => ['task_vm_id' => 42, 'gateway_node_id' => 14, 'model_router_node_id' => 3,
            'model_origin' => 'http://10.44.0.3:8317',
            'model_execution_secrets' => [
                'model_key' => ['vault' => 'orbit-control-inputs', 'key' => 'task-vm-model-key-42'],
                'model_router_management' => ['vault' => 'orbit-control-inputs', 'key' => 'model-router-management-node-3'],
                'model_router_anchor' => ['vault' => 'orbit-control-inputs', 'key' => 'model-router-anchor-node-3'],
            ], 'pi_token' => 'must-not-cross', 'private_bindings' => ['must-not-cross']]]);
}

it('provides the full model intent and names without changing the root Node', function (string $kind): void {
    $operation = modelKeyStepOperation($kind);
    $input = (new TaskVmModelKeyInputs(new ModelKeyStepIssuer))->workflowInputs($operation);

    expect($input['operation_id'])->toBe(7001)->and($input['kind'])->toBe($kind)->and($input['node_id'])->toBe(9);
    expect($input['intent'])->toBe(['task_vm_id' => 42, 'gateway_node_id' => 14,
        'model_router_node_id' => 3, 'model_origin' => 'http://10.44.0.3:8317'])
        ->and($input['execution_secrets'])->toBe($operation->input['model_execution_secrets'])
        ->and($operation->node_id)->toBe(9);
    expect(str_contains(json_encode($input, JSON_THROW_ON_ERROR), 'must-not-cross'))->toBeFalse();
})->with(['task_vm.provision', 'tasks.provision', 'task_vm.enroll', 'task_vm.prepare_runtime', 'task_vm.destroy']);

it('refuses missing or foreign vault descriptors before native start', function (string $fault): void {
    $operation = modelKeyStepOperation();
    $input = $operation->input;
    $input['model_execution_secrets'] = modelKeyStepDescriptors();
    if ($fault === 'missing') {
        unset($input['model_execution_secrets']['model_key']);
    } elseif ($fault === 'vm') {
        $input['model_execution_secrets']['model_key']['key'] = 'task-vm-model-key-43';
    } elseif ($fault === 'router') {
        $input['model_execution_secrets']['model_router_management']['key'] = 'model-router-management-node-4';
    } elseif ($fault === 'vault') {
        $input['model_execution_secrets']['model_router_anchor']['vault'] = 'other';
    } else {
        $input['model_execution_secrets']['model_key']['value'] = 'must-not-cross';
    }
    $operation->input = $input;

    expect(fn () => (new TaskVmModelKeyInputs(new ModelKeyStepIssuer))->workflowInputs($operation))
        ->toThrow(ResourceOperationException::class, 'The admitted model key intent is unavailable.');
})->with(['missing', 'vm', 'router', 'vault', 'value']);

it('refuses an unsafe router origin without reaching the router', function (string $origin): void {
    $operation = modelKeyStepOperation();
    $operation->input = [...$operation->input, 'model_origin' => $origin];

    expect(fn () => (new TaskVmModelKeyInputs(new ModelKeyStepIssuer))->workflowInputs($operation))
        ->toThrow(ResourceOperationException::class);
})->with(['file:///tmp/router', 'http://user@router', 'http://user:pass@router', 'http://router/path', 'http://router?key=x', 'http://router#x']);

it('refuses a different VM target or a kind outside its existing parents', function (): void {
    $operation = modelKeyStepOperation();
    $operation->target_id = 43;
    $inputs = new TaskVmModelKeyInputs(new ModelKeyStepIssuer);

    expect(fn () => $inputs->workflowInputs($operation))->toThrow(ResourceOperationException::class);
    $operation = modelKeyStepOperation('node.add');
    expect(fn () => $inputs->workflowInputs($operation))->toThrow(ResourceOperationException::class);
});

it('captures existing credential types through the one supplied shared helper and retains only names', function (): void {
    $vm = (new TaskVm)->forceFill(['id' => 42, 'model_key' => str_repeat('1', 64), 'ai_router_origin' => 'http://10.44.0.3:8317']);
    $issuer = new ModelKeyStepIssuer;
    $calls = [];
    $capture = (new TaskVmModelKeyInputs($issuer))->capture($vm, 14,
        function (string $vault, string $key, string $value) use (&$calls): array {
            $calls[] = [$vault, $key, $value];

            return ['vault' => $vault, 'key' => $key];
        });

    expect(array_column($calls, 1))->toBe(['task-vm-model-key-42', 'model-router-management-node-3', 'model-router-anchor-node-3'])
        ->and(array_column($calls, 2))->toBe([str_repeat('1', 64), 'synthetic-management', str_repeat('2', 64)])
        ->and($capture['intent'])->toBe(['task_vm_id' => 42, 'gateway_node_id' => 14, 'model_router_node_id' => 3, 'model_origin' => 'http://10.44.0.3:8317']);
    foreach ([str_repeat('1', 64), str_repeat('2', 64), 'synthetic-management'] as $secret) {
        expect(str_contains(json_encode($capture, JSON_THROW_ON_ERROR), $secret))->toBeFalse();
    }
    expect($issuer->reads)->toBe([['origin' => 'http://10.44.0.3:8317', 'node_id' => 3]]);
});

it('stops capture when the shared vault helper refuses instead of returning partial references', function (): void {
    $vm = (new TaskVm)->forceFill(['id' => 42, 'model_key' => str_repeat('1', 64), 'ai_router_origin' => 'http://10.44.0.3:8317']);
    $issuer = new ModelKeyStepIssuer;
    $calls = 0;
    $copy = function () use (&$calls): array {
        $calls++;
        throw new ResourceOperationException('swamp.vault_copy_failed', 'Execution copy unavailable.', 503);
    };

    expect(fn () => (new TaskVmModelKeyInputs($issuer))->capture($vm, 14, $copy))->toThrow(ResourceOperationException::class)
        ->and($calls)->toBe(1);
});
