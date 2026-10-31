<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceDeployAction;
use App\Domain\Instances\Deployment\InstanceDeploymentDirectInput;
use App\Domain\Instances\Deployment\InstanceDeploymentSecrets;
use App\Domain\Instances\Deployment\InstanceDeployProjection;
use App\Domain\Instances\ProductionPhpRuntimeIdentity;
use App\Domain\Operations\OperationStatus;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Schedules\ScheduleRuntimeAccountResolver;
use App\Domain\Schedules\ScheduleRuntimeManager;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\Operation;
use App\Models\Process;
use App\Models\ProcessDefinition;
use App\Models\Route;
use App\Models\Schedule;
use App\Models\ScheduleDefinition;
use Tests\Support\FakeRoutePublicationSocket;

function production_native_deploy_target(): Instance
{
    [, , , $instance] = deployment_api_fixture();
    $instance->node->update(['architecture' => 'x86_64']);
    $route = Route::query()->create(['project_id' => $instance->project_id, 'node_id' => $instance->node_id,
        'domain' => 'native-production.example.test', 'provenance' => 'explicit', 'publication' => 'public', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active']);
    $user = 'orbit-app-'.$instance->project_id;
    $instance->update(['production_user' => $user, 'production_home' => '/home/'.$user,
        'checkout_path' => '/home/'.$user.'/releases/previous', 'status' => 'active',
        'provisioning_step' => 'active', 'source_is_laravel' => false]);
    ProcessDefinition::query()->create(['project_id' => $instance->project_id, 'name' => 'queue', 'environments' => ['production'],
        'spec' => ['runtime' => 'systemd', 'command' => ['/usr/bin/true'], 'environment' => ['TOKEN' => 'private-member-token']]]);
    ScheduleDefinition::query()->create(['project_id' => $instance->project_id, 'name' => 'daily', 'environments' => ['production'],
        'spec' => ['calendar' => 'daily', 'command' => 'printf private-schedule', 'timeout_seconds' => 60]]);
    $instance->environmentValues()->create(['env_key' => 'API_TOKEN', 'env_value' => 'private-deploy-environment']);

    return $instance->fresh();
}

beforeEach(function (): void {
    app()->instance(WebSocketClient::class, new FakeRoutePublicationSocket);
    config(['swamp.run_token' => 'private.production-native.fixture']);
    $this->mock(AgentCommandRunner::class)->shouldNotReceive('run');
    $this->mock(ProcessRuntimeManager::class)->shouldNotReceive('converge');
    $this->mock(ScheduleRuntimeManager::class)->shouldNotReceive('install');
    $this->mock(ScheduleRuntimeAccountResolver::class)->shouldNotReceive('resolve');
});

it('admits one production parent with canonical account and private captured members', function (): void {
    $instance = production_native_deploy_target();
    $operation = app(StartInstanceDeployAction::class)->execute($instance, 'operator', null);
    $public = app(InstanceDeploymentDirectInput::class)->input($operation);
    $secrets = app(InstanceDeploymentSecrets::class);
    $environment = $secrets->resolve($operation, $instance->id, '', 'environment_material')->value;
    $members = $secrets->resolve($operation, $instance->id, '', 'definitions_material')->value;

    expect($public['deployment'])->toMatchArray(['variant' => 'production', 'user' => 'orbit-app-'.$instance->project_id,
        'home' => '/home/orbit-app-'.$instance->project_id, 'runtime_synchronous' => false])
        ->and($public['environment'])->toMatchArray(['base' => $instance->production_home, 'user' => $instance->production_user, 'environment' => 'production'])
        ->and($public['runtime_definitions']['processes'])->toHaveCount(1)
        ->and($public['runtime_definitions']['schedules'])->toHaveCount(1)
        ->and($public['php_input'])->toBeNull()
        ->and(json_encode($public))->not->toContain('private-member-token', 'private-schedule', 'private-deploy-environment')
        ->and(json_encode($members))->toContain('private-member-token', 'private-schedule')
        ->and($environment['values']['API_TOKEN'])->toBe('private-deploy-environment')
        ->and(Operation::query()->count())->toBe(1)
        ->and(InstanceDeployment::query()->count())->toBe(0)
        ->and($instance->refresh()->runtime_definitions_captured_at)->not->toBeNull();
});

it('rolls definition copies back when protected environment admission refuses', function (): void {
    $instance = production_native_deploy_target();
    $instance->update(['provisioning_step' => 'not-ready']);

    expect(fn () => app(StartInstanceDeployAction::class)->execute($instance, 'operator', null))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('env.owner_unavailable'));
    expect(Process::query()->count())->toBe(0)->and(Schedule::query()->count())->toBe(0)
        ->and($instance->refresh()->runtime_definitions_captured_at)->toBeNull()
        ->and(Operation::query()->count())->toBe(0);
});

it('captures the canonical pinned PHP package-only profile and dedicated runtime plan without effects', function (): void {
    $instance = production_native_deploy_target();
    $identity = ProductionPhpRuntimeIdentity::forProvisioning($instance, '8.5');
    $instance->update(['selected_php_version' => '8.5', ...$identity->attributes()]);
    $operation = app(StartInstanceDeployAction::class)->execute($instance, 'operator', null);
    $php = app(InstanceDeploymentDirectInput::class)->input($operation)['php_input'];

    expect($php['packages']['fpm'])->toBe('none')
        ->and($php['packages']['versions']['8.5']['packages'])->not->toBeEmpty()
        ->and($php['packages']['versions']['8.5']['packages'][0])->toHaveKeys(['version', 'architecture', 'url', 'sha256'])
        ->and($php['profile'])->toHaveKeys(['version', 'runtime_ini', 'release'])
        ->and($php['runtime'])->toMatchArray(['mode' => 'converge', 'user' => $instance->production_user, 'home' => $instance->production_home])
        ->and($php['runtime'])->toHaveKeys(['configuration', 'application', 'initial_application'])
        ->and($php['refresh']['deadline_seconds'])->toBe(30)
        ->and(Operation::query()->count())->toBe(1);
});

it('refuses a production account that is not the canonical admitted Project account', function (): void {
    $instance = production_native_deploy_target();
    $instance->update(['production_user' => 'foreign-account', 'production_home' => '/home/foreign-account']);
    expect(fn () => app(StartInstanceDeployAction::class)->execute($instance, 'operator', null))->toThrow(ResourceOperationException::class);
    expect(Operation::query()->count())->toBe(0)->and(Process::query()->count())->toBe(0)
        ->and(Schedule::query()->count())->toBe(0)->and($instance->refresh()->runtime_definitions_captured_at)->toBeNull();
});

it('refuses private hydration after immutable production ownership changes', function (string $field): void {
    $instance = production_native_deploy_target();
    $operation = app(StartInstanceDeployAction::class)->execute($instance, 'operator', null);
    match ($field) {
        'account' => $instance->update(['production_user' => 'foreign']),
        'home' => $instance->update(['production_home' => '/home/foreign']),
        'repository' => $instance->project->update(['repository_url' => 'https://example.test/foreign.git']),
        'node-user' => $instance->node->update(['user' => 'foreign']),
    };
    expect(fn () => app(InstanceDeploymentSecrets::class)->resolve($operation, $instance->id, '', 'environment_material'))
        ->toThrow(ResourceOperationException::class);
})->with(['account', 'home', 'repository', 'node-user']);

it('records native partial failure and owned member outcomes without runtime revalidation', function (bool $confirmed): void {
    $instance = production_native_deploy_target();
    $operation = app(StartInstanceDeployAction::class)->execute($instance, 'operator', null);
    $operation->update(['status' => 'running', 'state' => [...$operation->state, 'swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]);
    $process = Process::query()->sole();
    $process->update(['runtime_config' => ['command' => ['/usr/bin/false']]]);
    $selected = ['name' => 'reported-selected', 'commit' => str_repeat('a', 40)];
    $data = ['run_id' => $operation->state['swamp_run_id'], 'status' => 'failed', 'error_code' => 'deployment.step_failed',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['release' => $selected, 'selected_release' => $selected, 'selection_confirmed' => $confirmed,
            'commit' => $selected['commit'], 'failed_step' => 'process_restart',
            'runtime_definitions' => ['processes' => [['process_id' => $process->id, 'ready' => true]]]]];
    $projection = app(InstanceDeployProjection::class);
    $result = $projection->apply($operation, $data);
    $projection->apply($operation->fresh(), $data);

    expect($result->status)->toBe(OperationStatus::Failed)->and($result->lock_key)->toBeNull()
        ->and($instance->refresh()->checkout_path)->toBe($instance->production_home.'/releases/'.($confirmed ? 'reported-selected' : 'previous'))
        ->and($process->refresh()->status)->toBe(LifecycleStatus::Active)
        ->and(Schedule::query()->sole()->status)->toBe(LifecycleStatus::Provisioning)
        ->and(InstanceDeployment::query()->sole()->status)->toBe('failed')
        ->and(InstanceDeployment::query()->count())->toBe(1)->and(Operation::query()->count())->toBe(1);
})->with([true, false]);

it('keeps declared private material available after cooperative cancellation while the root remains running', function (): void {
    $instance = production_native_deploy_target();
    $operation = app(StartInstanceDeployAction::class)->execute($instance, 'operator', null);
    $run = '552822e1-e120-4bd2-a12b-2b57a145f513';
    $operation->update(['status' => 'running', 'cancel_requested_at' => now(),
        'state' => [...$operation->state, 'swamp_run_id' => $run]]);
    $process = Process::query()->sole();
    $this->withServerVariables(['REMOTE_ADDR' => $instance->node->wireguard_ip]);

    $response = $this->getJson('/api/v1/direct-operations/'.$operation->id.'/private-material?run_id='.$run);
    expect($response->json('error.code'))->toBeNull($response->json('error.message') ?? '');
    $response->assertOk()->assertJsonPath('data.definitions_material.processes.'.$process->id.'.installation.runtime', 'systemd');
    expect($operation->fresh()->status)->toBe(OperationStatus::Running)
        ->and($operation->fresh()->cancel_requested_at)->not->toBeNull();
});
