<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceProjectionRepairAction;
use App\Domain\Instances\Environment\InstanceEnvironmentContext;
use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\Environment\NativeInstanceEnvironmentMaterial;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\Process;

function nativeEnvironmentOperation(): array
{
    $instance = dev935_instance('native-environment');
    $instance->node->update(['architecture' => 'x86_64']);
    $instance->forceFill(['provisioning_step' => 'active', 'source_is_laravel' => false,
        'environment_owned_keys' => ['OLD']])->saveOrFail();
    $instance->environmentValues()->create(['env_key' => 'API_TOKEN', 'env_value' => 'synthetic-private-value']);
    $context = app(InstanceEnvironmentContextResolver::class)->resolve($instance, true);
    $plan = app(NativeInstanceEnvironmentMaterial::class)->forContext($context);
    $operation = new Operation(['kind' => 'instance.sync_environment', 'target_type' => 'instance',
        'target_id' => $instance->id, 'node_id' => $instance->node_id,
        'input' => ['instance_id' => $instance->id, 'direct_environment' => $plan,
            'environment_material' => ['$secret' => 'instance.environment_sync', 'id' => $instance->id]]]);

    return [$operation, $instance];
}

it('renders native environment material without a Gateway Node preflight and keeps input secret-free', function (): void {
    [$operation] = nativeEnvironmentOperation();
    $material = app(NativeInstanceEnvironmentMaterial::class);
    $value = $material->resolve($operation, $operation->target_id, '', 'environment_material');
    expect($value->value)->toMatchArray(['content' => "API_TOKEN=\"synthetic-private-value\"\n",
        'keys' => ['API_TOKEN'], 'owned_keys' => ['OLD'], 'testing' => null])
        ->and(json_encode($operation->input))->not->toContain('synthetic-private-value')
        ->and(json_encode($value))->toBe('"[REDACTED]"');
});

it('refuses a substituted native environment identity before protected rendering', function (string $field): void {
    [$operation] = nativeEnvironmentOperation();
    if ($field === 'node_id') {
        $operation->node_id++;
    } else {
        $input = $operation->input;
        $input['direct_environment'][$field] = $field === 'node_id' ? 987654 : 'substituted';
        $operation->input = $input;
    }
    expect(fn () => app(NativeInstanceEnvironmentMaterial::class)->resolve($operation, $operation->target_id, '', 'environment_material'))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.execution_forbidden'));
})->with(['node_id', 'base', 'user', 'environment']);

it('refuses another material field or owner with the existing private source type', function (string $parameter): void {
    [$operation] = nativeEnvironmentOperation();
    expect(fn () => app(NativeInstanceEnvironmentMaterial::class)->resolve($operation, $operation->target_id, '', $parameter))
        ->toThrow(ResourceOperationException::class);
})->with(['content', 'git_read']);

it('makes reserved parent environment material null without reading configuration', function (): void {
    [$operation, $instance] = nativeEnvironmentOperation();
    $instance->update(['status' => 'reserved', 'provisioning_step' => 'reserved']);
    $instance->environmentValues()->delete();
    $operation->kind = 'instance.create';
    $operation->input = ['instance_id' => $instance->id, 'direct_provisioning' => [
        'environment' => 'development', 'user' => $instance->node->user,
        'project_id' => $instance->project_id, 'checkout' => $instance->checkout_path]];
    expect(app(NativeInstanceEnvironmentMaterial::class)->resolve($operation, $instance->id, '', 'environment_material')->value)->toBeNull();
    $input = $operation->input;
    $input['direct_provisioning']['user'] = 'root';
    $operation->input = $input;
    expect(fn () => app(NativeInstanceEnvironmentMaterial::class)->resolve($operation, $instance->id, '', 'environment_material'))
        ->toThrow(ResourceOperationException::class);
});

it('does not claim projection repair by admitting a Route child', function (): void {
    [, $instance] = nativeEnvironmentOperation();
    $instance->update(['development_projection_pending' => true]);
    expect(fn () => app(StartInstanceProjectionRepairAction::class)->execute($instance))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.kind_unavailable'));
    expect($instance->fresh()->development_projection_pending)->toBeTrue()
        ->and(Operation::query()->count())->toBe(0);
});

it('refuses production synchronization before admission when an owned Process restart lacks its native lock', function (): void {
    [, $instance] = nativeEnvironmentOperation();
    Process::query()->create(['owner_type' => $instance->getMorphClass(), 'owner_id' => $instance->id,
        'name' => 'environment-worker', 'runtime' => 'systemd', 'runtime_config' => [],
        'restart_policy' => 'always', 'restart_on_deploy' => true, 'desired_state' => 'running', 'status' => 'active']);
    $context = new InstanceEnvironmentContext(instanceId: $instance->id, projectId: $instance->project_id,
        nodeId: $instance->node_id, environment: 'production', path: '/home/orbit-app-'.$instance->project_id,
        executionUser: 'orbit-app-'.$instance->project_id, laravel: false, routeId: null, routeDomain: null,
        nodeStatus: 'active', node: $instance->node);

    expect(fn () => app(NativeInstanceEnvironmentMaterial::class)->forContext($context))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.kind_unavailable'));
    expect(Operation::query()->count())->toBe(0);
    expect(app(NativeInstanceEnvironmentMaterial::class)->forContext($context, false)['runtime'])->toBeNull();
});


it('refuses production PHP refresh before admitting or changing environment intent', function (): void {
    [, $instance] = nativeEnvironmentOperation();
    $instance->update(['selected_php_version' => '8.5']);
    $before = $instance->fresh()->getAttributes();
    $context = new InstanceEnvironmentContext(instanceId: $instance->id, projectId: $instance->project_id,
        nodeId: $instance->node_id, environment: 'production', path: '/home/orbit-app-'.$instance->project_id,
        executionUser: 'orbit-app-'.$instance->project_id, laravel: true, routeId: null, routeDomain: null,
        nodeStatus: 'active', node: $instance->node);
    expect(fn () => app(NativeInstanceEnvironmentMaterial::class)->forContext($context))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.kind_unavailable'));
    expect($instance->fresh()->getAttributes())->toBe($before)->and(Operation::query()->count())->toBe(0);
});
