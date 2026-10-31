<?php

declare(strict_types=1);

use App\Actions\Instances\StartDevelopmentInstanceCreateAction;
use App\Data\Instances\CreateInstanceData;
use App\Domain\Instances\Environment\NativeInstanceEnvironmentMaterial;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\NativeInstanceProvisioningIntent;
use App\Domain\Instances\NativeInstanceProvisioningProjection;
use App\Domain\Instances\NativeInstanceProvisioningSetup;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\ProjectLifecycleStep;

beforeEach(function (): void {
    $this->project = Project::query()->create(['name' => 'Native source', 'slug' => 'native-source',
        'type' => 'node-package', 'repository_url' => 'https://github.com/example/native-source.git', 'root' => '.', 'default_branch' => 'main']);
    $this->node = Node::query()->create(['name' => 'native-source', 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'amd64', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9']);
    orbit_test_set_app_placement_role($this->node, false);
    $this->instance = Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id,
        'name' => 'proof', 'checkout_path' => '/srv/apps/native-source/proof', 'source_layout' => 'checkout', 'status' => 'reserved',
        'source_prepare_id' => '0c4f71cf-84bd-4b80-8da4-8cd1b39cb8b6', 'first_setup_pending' => true]);
    $this->input = ['instance_id' => $this->instance->id, 'project_id' => $this->project->id, 'checkout' => $this->instance->checkout_path,
        'user' => 'orbit', 'group' => 'orbit', 'source_root' => '/srv/apps', 'prepare_id' => $this->instance->source_prepare_id,
        'repository' => $this->project->repository_url, 'repository_identity' => $this->project->repository_identity,
        'seed_repository' => null, 'seed_commit' => null, 'branch_override' => null, 'default_branch' => 'main'];
});

it('records a complete immutable development source and setup plan without executing a Node command', function (): void {
    ProjectLifecycleStep::query()->create(['project_id' => $this->project->id, 'phase' => 'setup', 'position' => 0,
        'name' => 'prepare', 'command' => 'printf private-setup-program', 'timeout_seconds' => 30]);
    $plan = app(NativeInstanceProvisioningIntent::class)->create($this->instance, $this->input);

    expect($plan)->toMatchArray(['instance_id' => $this->instance->id, 'node_id' => $this->node->id,
        'node_user' => 'orbit', 'user' => 'orbit', 'environment' => 'development', 'already_active' => false,
        'platform' => 'linux', 'architecture' => 'x86_64'])
        ->and($plan['vp_materialize']['user'])->toBe('orbit')
        ->and($plan['vp_materialize']['architecture'])->toBe('amd64')
        ->and($plan['source'])->toMatchArray(['prepare_id' => $this->instance->source_prepare_id, 'checkout' => $this->instance->checkout_path])
        ->and($plan['setup']['execution']['user'])->toBe('orbit')
        ->and($plan['setup']['steps'][0])->toBe(['name' => 'prepare', 'command_digest' => hash('sha256', 'printf private-setup-program'), 'timeout_seconds' => 30])
        ->and(json_encode($plan))->not->toContain('private-setup-program')
        ->and($plan)->not->toHaveKey('agent_sha256');
});

it('retains resolved source identity for an explicit retry after native setup failure', function (): void {
    $this->instance->update(['status' => InstanceState::SourceResolved, 'branch' => 'proof', 'starting_commit' => str_repeat('a', 40),
        'source_is_laravel' => false, 'selected_php_version' => null, 'provisioning_step' => 'php-selected',
        'failed_step' => 'setup', 'error_code' => 'instance.provisioning_failed']);
    $plan = app(NativeInstanceProvisioningIntent::class)->create($this->instance->refresh(), $this->input);

    expect($plan['retained_source'])->toBe(['branch' => 'proof', 'commit' => str_repeat('a', 40), 'php_version' => null, 'laravel' => false])
        ->and($plan['already_active'])->toBeFalse();
});

it('refuses unavailable native publication and database combinations before reservation', function (bool $database, string $type): void {
    $this->project->update(['type' => $type]);

    expect(fn () => app(NativeInstanceProvisioningIntent::class)->assertCreateSupported($this->project->refresh(), $this->node, $database))
        ->toThrow(ResourceOperationException::class, 'native publication');
})->with([[true, 'node-package'], [false, 'laravel-app'], [false, 'symfony-app']]);

it('does not accept a caller-selected account in development source admission', function (): void {
    expect(fn () => app(NativeInstanceProvisioningIntent::class)->create($this->instance, [...$this->input, 'user' => 'root']))
        ->toThrow(ResourceOperationException::class);
});

it('resolves exact Project setup programs only in the existing private material boundary', function (): void {
    $command = 'printf private-source-program-89221';
    $step = ProjectLifecycleStep::query()->create(['project_id' => $this->project->id, 'phase' => 'setup', 'position' => 0,
        'name' => 'prepare', 'command' => $command, 'timeout_seconds' => 30]);
    $plan = app(NativeInstanceProvisioningIntent::class)->create($this->instance, $this->input);
    $operation = new Operation(['kind' => 'instance.create', 'target_type' => 'instance', 'target_id' => $this->instance->id,
        'node_id' => $this->node->id, 'input' => [...$this->input, 'direct_provisioning' => $plan]]);
    $value = app(NativeInstanceProvisioningSetup::class)->resolve($operation, $this->instance->id, '', 'setup_programs');

    expect($value)->toBeInstanceOf(OperationSecretValue::class)->and($value->value)->toBe(['steps' => [
        ['name' => 'prepare', 'timeout_seconds' => 30, 'command' => $command],
    ]])->and(json_encode($value))->toBe('"[REDACTED]"');
    $step->update(['command' => 'printf changed']);
    expect(fn () => app(NativeInstanceProvisioningSetup::class)->resolve($operation, $this->instance->id, '', 'setup_programs'))
        ->toThrow(ResourceOperationException::class);
});

it('allows source Git reads before environment readiness without exposing unavailable environment material', function (): void {
    $plan = app(NativeInstanceProvisioningIntent::class)->create($this->instance, $this->input);
    $operation = new Operation(['kind' => 'instance.create', 'target_type' => 'instance', 'target_id' => $this->instance->id,
        'node_id' => $this->node->id, 'input' => [...$this->input, 'direct_provisioning' => $plan]]);

    expect(app(NativeInstanceEnvironmentMaterial::class)->resolve($operation, $this->instance->id, '', 'environment_material')->value)->toBeNull();
    $this->node->update(['user' => 'changed']);
    expect(fn () => app(NativeInstanceEnvironmentMaterial::class)->resolve($operation, $this->instance->id, '', 'environment_material'))
        ->toThrow(ResourceOperationException::class);
});

it('freezes clone target account and candidate placement without inspecting either Node', function (): void {
    orbit_test_set_app_placement_role($this->node, true);
    $this->instance->update(['production_user' => 'orbit-app-'.$this->project->id,
        'production_home' => '/home/orbit-app-'.$this->project->id,
        'checkout_path' => '/home/orbit-app-'.$this->project->id.'/releases/initial']);
    $candidate = ['node_id' => 17, 'agent_sha256' => str_repeat('c', 64), 'environment' => 'development',
        'base' => '/srv/source/default', 'expected_source' => '/srv/source/default', 'user' => 'source',
        'repository' => $this->project->repository_url, 'configured_branch' => 'main', 'target_branch' => 'main'];
    $plan = app(NativeInstanceProvisioningIntent::class)->clone($this->instance->refresh(), [
        'repository' => $this->project->repository_url, 'branch' => 'main', 'candidate' => $candidate]);

    expect($plan['candidate'])->toBe($candidate)->and($plan['environment'])->toBe('production')
        ->and($plan['user'])->toBe('orbit-app-'.$this->project->id)
        ->and($plan['source'])->toMatchArray(['instance' => $this->instance->id, 'user' => $plan['user']])
        ->and($plan)->not->toHaveKey('setup');
});

it('refuses runtime definition installation before create reserves an Instance', function (): void {
    $this->project->processDefinitions()->create(['name' => 'worker', 'environments' => ['development'], 'spec' => ['runtime' => 'systemd', 'command' => ['/usr/bin/sleep', '300'], 'restart_policy' => 'never']]);

    expect(fn () => app(NativeInstanceProvisioningIntent::class)->assertCreateSupported($this->project, $this->node, false))
        ->toThrow(ResourceOperationException::class, 'native publication');
});

it('refuses an unavailable create before reserving a new Instance', function (): void {
    $before = Instance::query()->count();

    expect(fn () => app(StartDevelopmentInstanceCreateAction::class)->execute(
        new CreateInstanceData($this->project->id, $this->node->id, 'unavailable', null, null, null, 'unported-server'), 'test', null,
    ))->toThrow(ResourceOperationException::class, 'native publication');
    expect(Instance::query()->count())->toBe($before);
});

it('projects native source and success without Gateway step receipts', function (): void {
    $run = '0c4f71cf-84bd-4b80-8da4-8cd1b39cb8b6';
    $plan = app(NativeInstanceProvisioningIntent::class)->create($this->instance, $this->input);
    $operation = Operation::query()->create(['kind' => 'instance.create', 'target_type' => 'instance',
        'target_id' => $this->instance->id, 'node_id' => $this->node->id, 'status' => 'queued',
        'input' => [...$this->input, 'direct_provisioning' => $plan], 'state' => ['swamp_run_id' => $run]]);
    $projection = app(NativeInstanceProvisioningProjection::class);
    $callback = ['run_id' => $run, 'status' => 'running', 'started_at' => now()->toIso8601String(),
        'error_code' => null, 'result' => ['phase' => 'source', 'branch' => 'proof', 'commit' => str_repeat('a', 40),
            'php_version' => null, 'laravel' => false]];
    $projection->apply($operation, $callback);
    expect($this->instance->refresh()->status)->toBe(InstanceState::SourceResolved)
        ->and($this->instance->starting_commit)->toBe(str_repeat('a', 40))
        ->and($projection->input($operation)['environment_plan'])->toMatchArray(['user' => 'orbit', 'node_id' => $this->node->id]);

    $projection->apply($operation, [...$callback, 'status' => 'succeeded', 'finished_at' => now()->toIso8601String(),
        'result' => ['instance_id' => $this->instance->id, 'ready' => true, 'name_converted_from' => null]]);
    expect($this->instance->refresh()->status)->toBe(InstanceState::Active)
        ->and($this->instance->first_setup_pending)->toBeFalse()
        ->and($operation->refresh()->status->value)->toBe('succeeded');
});

it('retains native VP checking pins before installation and records the final runtime status', function (): void {
    $run = '0c4f71cf-84bd-4b80-8da4-8cd1b39cb8b6';
    $operation = Operation::query()->create(['kind' => 'instance.create', 'target_type' => 'instance',
        'target_id' => $this->instance->id, 'node_id' => $this->node->id, 'status' => 'queued',
        'input' => $this->input, 'state' => ['swamp_run_id' => $run]]);
    $runtime = ['store' => '/opt/orbit/vite-plus', 'vp_version' => '0.3.3', 'node_version' => '24.22.0',
        'package_manager' => 'npm', 'package_manager_version' => '11.12.0', 'package_manager_hash' => null];
    $projection = app(NativeInstanceProvisioningProjection::class);
    $callback = ['run_id' => $run, 'status' => 'running', 'started_at' => now()->toIso8601String(),
        'error_code' => null, 'result' => ['phase' => 'vp', 'environment' => $runtime, 'runtime_status' => 'checking']];

    $projection->apply($operation, $callback);

    expect($this->instance->refresh()->getAttribute('vp_environment'))->toMatchArray([
        ...$runtime, 'status' => 'checking', 'checkout_path' => $this->instance->checkout_path]);
    $projection->apply($operation, [...$callback, 'result' => [...$callback['result'], 'runtime_status' => 'active']]);
    expect($this->instance->refresh()->getAttribute('vp_environment')['status'])->toBe('active')
        ->and($this->instance->getAttribute('vp_environment')['previous'])->toBeNull();
});
