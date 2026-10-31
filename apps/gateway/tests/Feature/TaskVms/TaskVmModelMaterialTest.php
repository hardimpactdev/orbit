<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationContribution;
use App\Domain\Operations\DirectOperationPrivateBindings;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\ModelKeyIssuer;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmImageProjection;
use App\Infrastructure\TaskVms\TaskVmSecretSource;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Support\Facades\Http;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;
use Tests\Support\TaskVmSettingsBuilder;

function modelMaterialVm(): TaskVm
{
    $project = Project::query()->create(['name' => 'Model material', 'slug' => 'model-material', 'repository_url' => 'https://github.com/example/models.git', 'default_branch' => 'main']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Prepare models', 'brief' => 'Model material', 'status' => 'todo']);
    $node = Node::query()->create(['name' => 'tvm-models', 'status' => LifecycleStatus::Provisioning, 'user' => 'orbit', 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.130', 'public_ssh_host' => '10.44.0.130']);

    return TaskVm::query()->create(['group_id' => $group->id, 'node_id' => $node->id, 'provider' => 'upcloud', 'name' => 'tvm-models',
        'state' => 'provisioning', 'wireguard_ip' => '10.44.0.130', 'model_key' => str_repeat('a', 64),
        'pi_token' => str_repeat('b', 64), 'ai_router_origin' => TaskVmSettingsBuilder::Origin]);
}

function modelMaterialOperation(TaskVm $vm, string $targetType = 'task_group'): Operation
{
    $router = Node::query()->create(['name' => 'model-router', 'status' => LifecycleStatus::Active, 'user' => 'orbit', 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.3', 'public_ssh_host' => '10.44.0.3']);
    app(AiRouterState::class)->enable($router->id, 'cache', TaskVmSettingsBuilder::Origin, 'management-secret', 'read-secret', 'control-secret');
    $direct = new DirectOperation([new class implements DirectOperationContribution
    {
        public function definitions(): array
        {
            return ['test.task_vm_material' => ['workflow' => 'operation-test-material', 'projection' => TaskVmImageProjection::class,
                'private_material' => ['model_router' => 'task_vm.model_router', 'model_key' => 'task_vm.model_key', 'pi_token' => 'task_vm.pi_token']]];
        }
    }]);
    app()->instance(DirectOperationPrivateBindings::class, new DirectOperationPrivateBindings($direct));
    $bindings = [];
    foreach (['model_router' => $router->id, 'model_key' => $vm->node_id, 'pi_token' => $vm->node_id] as $field => $owner) {
        $bindings[$field] = ['owner_node_id' => $owner, 'read_node_ids' => [$router->id], 'write_node_ids' => []];
    }

    return new Operation(['kind' => 'test.task_vm_material', 'target_type' => $targetType,
        'target_id' => $targetType === 'task_group' ? $vm->group_id : $vm->id, 'node_id' => $router->id,
        'input' => ['task_vm_id' => $vm->id, 'origin' => TaskVmSettingsBuilder::Origin,
            'participant_node_ids' => [$router->id, $vm->node_id], 'private_bindings' => $bindings]]);
}

it('returns existing router credentials through the plug-in without a router request', function (): void {
    Http::preventStrayRequests();
    TaskVmSettingsBuilder::bind();
    app(AiRouterState::class)->enable(42, 'cache', TaskVmSettingsBuilder::Origin, 'management-secret', 'read-secret', 'control-secret');
    $issuer = app(ModelKeyIssuer::class);

    $first = $issuer->privateMaterial(TaskVmSettingsBuilder::Origin, 42);
    $again = $issuer->privateMaterial(TaskVmSettingsBuilder::Origin, 42);

    expect($issuer->ownerNodeId())->toBe(42)
        ->and($first)->toBe($again)
        ->and($first['management_key'])->toBe('management-secret')
        ->and($first['anchor_key'])->toMatch('/\A[a-f0-9]{64}\z/D');
    $stored = Setting::query()->where('key', 'ai_router.anchor.'.hash('sha256', TaskVmSettingsBuilder::Origin))->sole();
    expect($stored->is_secret)->toBeTrue()->and($stored->value)->not->toBe($first['anchor_key']);
    Http::assertNothingSent();
});

it('refuses a router owner mismatch before staging or generating an anchor', function (): void {
    Http::preventStrayRequests();
    TaskVmSettingsBuilder::bind();
    app(AiRouterState::class)->enable(42, 'cache', TaskVmSettingsBuilder::Origin, 'management-secret', 'read-secret', 'control-secret');

    expect(fn () => app(ModelKeyIssuer::class)->privateMaterial(TaskVmSettingsBuilder::Origin, 43))
        ->toThrow(ResourceOperationException::class);
    expect(Setting::query()->where('key', 'like', 'ai_router.anchor.%')->exists())->toBeFalse();
    Http::assertNothingSent();
});

it('refuses absent router management credentials without returning material', function (): void {
    Http::preventStrayRequests();
    TaskVmSettingsBuilder::bind();
    app(AiRouterState::class)->enable(42, 'cache', TaskVmSettingsBuilder::Origin, '', 'read-secret', 'control-secret');

    expect(fn () => app(ModelKeyIssuer::class)->privateMaterial(TaskVmSettingsBuilder::Origin, 42))
        ->toThrow(TaskVmException::class);
    expect(Setting::query()->where('key', 'like', 'ai_router.anchor.%')->exists())->toBeFalse();
    Http::assertNothingSent();
});

it('resolves admitted group and VM credentials with fixed field custody and redaction', function (string $targetType): void {
    Http::preventStrayRequests();
    TaskVmSettingsBuilder::bind();
    $vm = modelMaterialVm();
    $operation = modelMaterialOperation($vm, $targetType);

    $model = new TaskVmSecretSource('task_vm.model_key');
    $pi = new TaskVmSecretSource('task_vm.pi_token');
    $router = new TaskVmSecretSource('task_vm.model_router');
    expect($model->ownerId($operation))->toBe($vm->id)
        ->and($pi->resolve($operation, $vm->id, '', 'pi_token')->value)->toBe(str_repeat('b', 64))
        ->and($model->resolve($operation, $vm->id, '', 'model_key')->value)->toBe(str_repeat('a', 64));
    $material = $router->resolve($operation, $vm->id, '', 'model_router');
    expect($material->value['management_key'])->toBe('management-secret')
        ->and(json_encode($material, JSON_THROW_ON_ERROR))->toBe('"[REDACTED]"');
    Http::assertNothingSent();
})->with(['task_group', 'task_vm']);

it('refuses a different group or an altered immutable VM id before reading secrets', function (): void {
    TaskVmSettingsBuilder::bind();
    $vm = modelMaterialVm();
    $operation = modelMaterialOperation($vm);
    $source = new TaskVmSecretSource('task_vm.model_key');
    $operation->target_id++;
    expect($source->ownerId($operation))->toBeNull();
    $operation->target_id = $vm->group_id;
    $operation->input = [...$operation->input, 'task_vm_id' => $vm->id + 1000];
    expect($source->ownerId($operation))->toBeNull();
});

it('refuses a guest credential bound to another Node', function (): void {
    TaskVmSettingsBuilder::bind();
    $vm = modelMaterialVm();
    $operation = modelMaterialOperation($vm);
    $input = $operation->input;
    $input['private_bindings']['model_key']['owner_node_id'] = $operation->node_id;
    $operation->input = $input;
    $source = new TaskVmSecretSource('task_vm.model_key');

    expect($source->ownerId($operation))->toBeNull()
        ->and(fn () => $source->resolve($operation, $vm->id, '', 'model_key'))->toThrow(ResourceOperationException::class);
});

it('refuses a changed router owner or an origin outside the admitted VM intent', function (): void {
    TaskVmSettingsBuilder::bind();
    $vm = modelMaterialVm();
    $operation = modelMaterialOperation($vm);
    $source = new TaskVmSecretSource('task_vm.model_router');
    $input = $operation->input;
    $input['origin'] = 'http://other.orbit:8317';
    $operation->input = $input;
    expect(fn () => $source->resolve($operation, $vm->id, '', 'model_router'))->toThrow(ResourceOperationException::class);
    app(SettingRepository::class)->put(new SettingScope(SettingScopeType::Gateway), 'ai_router.node_id', (string) $vm->node_id);
    expect($source->ownerId($operation))->toBeNull();
});
