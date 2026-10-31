<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceEnvironmentSyncAction;
use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\Environment\NativeInstanceEnvironmentMaterial;
use App\Domain\Instances\ProductionPhpRuntimeIdentity;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Process;
use Tests\Support\FakeRoutePublicationSocket;

function nativeEnvironmentOperation(): array
{
    $instance = dev935_instance('native-environment');
    $instance->node->update(['architecture' => null]);
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

function nativeProductionEnvironmentInstance(bool $php = true): Instance
{
    $instance = dev935_instance('native-production-environment', role: 'app-prod');
    $home = '/home/orbit-app-'.$instance->project_id;
    $instance->node->update(['architecture' => 'x86_64']);
    $instance->project->update(['root' => 'apps/site/public']);
    $instance->update(['environment' => 'production', 'production_user' => 'orbit-app-'.$instance->project_id,
        'production_home' => $home, 'checkout_path' => $home.'/releases/r1',
        'source_is_laravel' => true, 'provisioning_step' => 'active', 'selected_php_version' => $php ? '8.5' : null]);
    if ($php) {
        $instance->update(ProductionPhpRuntimeIdentity::forProvisioning($instance, '8.5')->attributes());
    }
    $instance->environmentValues()->create(['env_key' => 'APP_KEY', 'env_value' => 'synthetic-private-value']);

    return $instance->refresh();
}

it('captures production PHP and only opted-in active running Processes in one native environment root', function (): void {
    $instance = nativeProductionEnvironmentInstance();
    $attributes = ['owner_type' => $instance->getMorphClass(), 'owner_id' => $instance->id,
        'runtime' => 'systemd', 'runtime_config' => ['command' => ['/usr/bin/sleep', '30']],
        'restart_policy' => 'always', 'restart_on_deploy' => true, 'desired_state' => 'running', 'status' => 'active'];
    $process = Process::query()->create(['name' => 'worker', ...$attributes]);
    foreach ([['restart_on_deploy' => false], ['status' => 'failed'], ['desired_state' => 'stopped'], ['owner_id' => $instance->id + 1]] as $index => $override) {
        Process::query()->create(['name' => 'excluded-'.$index, ...$attributes, ...$override]);
    }
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'synthetic-shared-run-token']);
    $operation = app(StartInstanceEnvironmentSyncAction::class)->execute($instance);
    $plan = $operation->input['direct_environment'];
    expect($plan)->not->toHaveKey('agent_sha256')
        ->and($plan['runtime']['config'])->toBe(['user' => $instance->production_user,
            'home' => $instance->production_home, 'version' => '8.5',
            'application' => $instance->production_home.'/current/apps/site'])
        ->and($plan['runtime']['php']['marker'])->toBe(ProductionPhpRuntimeIdentity::from($instance)->marker())
        ->and(array_column($plan['runtime']['processes'], 'id'))->toBe([$process->id])
        ->and($plan['runtime']['processes'][0]['input']['process_id'])->toBe($process->id)
        ->and(json_encode($operation->input))->not->toContain('synthetic-private-value')
        ->and(Operation::query()->count())->toBe(1)->and($socket->sent)->toHaveCount(1);
    $context = app(InstanceEnvironmentContextResolver::class)->resolve($instance, true);
    expect(app(NativeInstanceEnvironmentMaterial::class)->forContext($context, false)['runtime'])->toBeNull();
});

it('captures production file sync without PHP and leaves refresh plan unset', function (): void {
    $instance = nativeProductionEnvironmentInstance(false);
    $instance->node->update(['architecture' => null]);
    $context = app(InstanceEnvironmentContextResolver::class)->resolve($instance, true);
    $plan = app(NativeInstanceEnvironmentMaterial::class)->forContext($context);
    expect($plan['runtime'])->toBe(['config' => null, 'php' => null, 'processes' => []])
        ->and($plan)->not->toHaveKey('agent_sha256');
});

it('admits exactly one native environment root with stored placement and no secret values', function (): void {
    [, $instance] = nativeEnvironmentOperation();
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'synthetic-shared-run-token']);
    $operation = app(StartInstanceEnvironmentSyncAction::class)->execute($instance);
    expect($operation->kind)->toBe('instance.sync_environment')
        ->and($operation->node_id)->toBe($instance->node_id)
        ->and($operation->input['direct_environment']['user'])->toBe($instance->node->user)
        ->and($operation->input['direct_environment'])->not->toHaveKey('agent_sha256')
        ->and(json_encode($operation->input))->not->toContain('synthetic-private-value')
        ->and($socket->sent)->toHaveCount(1)
        ->and($socket->sent[0]['payload']['workflowIdOrName'])->toBe('operation-instance-environment')
        ->and($operation->fresh()->state['swamp_run_id'])->toBe('a075eb23-7ac4-4487-80d1-57a038a92676');
});
