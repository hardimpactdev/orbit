<?php

declare(strict_types=1);

use App\Actions\Instances\StartDevelopmentInstanceCreateAction;
use App\Data\Instances\CreateInstanceData;
use App\Domain\Instances\DevelopmentInstanceCreateProjection;
use App\Domain\Instances\Environment\NativeInstanceEnvironmentMaterial;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\NativeDevelopmentInstanceInput;
use App\Domain\Instances\NativeInstanceProvisioningIntent;
use App\Domain\Instances\NativeInstanceProvisioningSetup;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Projects\GatewaySetupSteps;
use App\Domain\Projects\LifecycleStep;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\ProjectLifecycleStep;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->project = Project::query()->create(['name' => 'Native source', 'slug' => 'native-source',
        'type' => 'node-package', 'repository_url' => 'https://github.com/example/native-source.git', 'root' => '.', 'default_branch' => 'main']);
    $this->node = Node::query()->create(['name' => 'native-source', 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'amd64', 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9']);
    orbit_test_set_app_placement_role($this->node, false);
    $this->instance = Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id,
        'name' => 'proof', 'checkout_path' => '/srv/apps/native-source/proof', 'source_layout' => 'checkout', 'status' => 'reserved',
        'source_prepare_id' => '0c4f71cf-84bd-4b80-8da4-8cd1b39cb8b6', 'first_setup_pending' => true]);
    $this->input = ['instance_id' => $this->instance->id, 'project_id' => $this->project->id, 'checkout' => $this->instance->checkout_path,
        'user' => 'orbit', 'group' => 'orbit', 'source_root' => '/srv/apps', 'prepare_id' => $this->instance->source_prepare_id,
        'repository' => $this->project->repository_url, 'repository_identity' => $this->project->repository_identity,
        'seed_repository' => null, 'seed_commit' => null, 'branch_override' => null, 'default_branch' => 'main', 'members' => []];
    $this->input['direct_provisioning'] = app(NativeInstanceProvisioningIntent::class)->create($this->instance, $this->input);
    $this->input += ['php_version' => null, 'laravel' => false, 'environment_keys' => ['APP_NAME', 'MODE']];
});

it('records a complete immutable development source and setup plan without executing a Node command', function (): void {
    ProjectLifecycleStep::query()->create(['project_id' => $this->project->id, 'phase' => 'setup', 'position' => 0,
        'name' => 'prepare', 'command' => 'printf private-setup-program', 'timeout_seconds' => 30]);
    $plan = app(NativeInstanceProvisioningIntent::class)->create($this->instance, $this->input);

    expect($plan)->toMatchArray(['instance_id' => $this->instance->id, 'node_id' => $this->node->id,
        'node_user' => 'orbit', 'user' => 'orbit', 'environment' => 'development', 'already_active' => false,
        'platform' => 'linux', 'architecture' => 'x86_64'])
        ->and($plan)->not->toHaveKey('vp_materialize')
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

    expect($plan['retained_source'])->toBe(['commit' => str_repeat('a', 40)])
        ->and($plan['resolve']['branch'])->toBe('proof')
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

it('expands Gateway owned setup commands through the canonical supplier after validating their original identity', function (): void {
    $step = ProjectLifecycleStep::query()->create(['project_id' => $this->project->id, 'phase' => 'setup', 'position' => 0,
        'name' => 'ci-caches', 'command' => LifecycleStep::ImportCiCaches, 'timeout_seconds' => 60]);
    $plan = app(NativeInstanceProvisioningIntent::class)->create($this->instance, $this->input);
    $operation = new Operation(['kind' => 'instance.create', 'target_type' => 'instance', 'target_id' => $this->instance->id,
        'node_id' => $this->node->id, 'input' => [...$this->input, 'direct_provisioning' => $plan]]);
    $value = app(NativeInstanceProvisioningSetup::class)->resolve($operation, $this->instance->id, '', 'setup_programs');
    expect($value->value['steps'][0]['command'])->toBe(GatewaySetupSteps::script(LifecycleStep::ImportCiCaches))
        ->and($plan['setup']['steps'][0]['command_digest'])->toBe(hash('sha256', LifecycleStep::ImportCiCaches));
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

it('freezes development clone placement without inspecting either Node', function (): void {
    $candidate = ['node_id' => 17, 'checkout' => '/srv/source/default', 'repository_identity' => $this->project->repository_identity, 'branch' => 'main'];
    $plan = app(NativeInstanceProvisioningIntent::class)->clone($this->instance, [
        'repository' => $this->project->repository_url, 'source_root' => '/srv/apps', 'branch' => 'main', 'candidate' => $candidate]);
    expect($plan['candidate'])->toBe($candidate)->and($plan['environment'])->toBe('development')
        ->and($plan['user'])->toBe('orbit')->and($plan['source']['checkout'])->toBe($this->instance->checkout_path)
        ->and($plan['resolve']['require_branch'])->toBeTrue()->and($plan)->not->toHaveKey('setup');
});

it('admits development Process definitions, which create starts after its setup steps', function (): void {
    $this->project->processDefinitions()->create(['name' => 'worker', 'environments' => ['development'], 'spec' => ['runtime' => 'systemd', 'command' => ['/usr/bin/sleep', '300'], 'restart_policy' => 'never']]);

    expect(fn () => app(NativeInstanceProvisioningIntent::class)->assertCreateSupported($this->project, $this->node, false))->not->toThrow(ResourceOperationException::class);
});

it('refuses an unavailable create before reserving a new Instance', function (): void {
    $before = Instance::query()->count();

    expect(fn () => app(StartDevelopmentInstanceCreateAction::class)->execute(
        new CreateInstanceData($this->project->id, $this->node->id, 'unavailable', null, null, null, 'unported-server'), 'test', null,
    ))->toThrow(ResourceOperationException::class, 'native publication');
    expect(Instance::query()->count())->toBe($before);
});

it('projects the checked-out commit beside the admitted branch, profile and environment keys, idempotently', function (): void {
    $run = '0c4f71cf-84bd-4b80-8da4-8cd1b39cb8b6';
    $operation = Operation::query()->create(['kind' => 'instance.create', 'target_type' => 'instance', 'target_id' => $this->instance->id, 'node_id' => $this->node->id, 'status' => 'running', 'input' => $this->input, 'state' => ['swamp_run_id' => $run]]);
    $data = ['run_id' => $run, 'status' => 'succeeded', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'error_code' => null,
        'result' => ['instance_id' => $this->instance->id, 'ready' => true, 'commit' => str_repeat('a', 40)]];
    $projection = app(DevelopmentInstanceCreateProjection::class);
    $projection->apply($operation, $data);
    expect($this->instance->refresh()->status)->toBe(InstanceState::Active)
        ->and($this->instance->branch)->toBe('proof')
        ->and($this->instance->starting_commit)->toBe(str_repeat('a', 40))
        ->and($this->instance->source_is_laravel)->toBeFalse()
        ->and($this->instance->first_setup_pending)->toBeFalse()
        ->and($this->instance->environment_owned_keys)->toBe(['APP_NAME', 'MODE'])
        ->and($operation->refresh()->status->value)->toBe('succeeded');
    $projection->apply($operation, $data);
    expect($this->instance->refresh()->status)->toBe(InstanceState::Active);
});

it('retains source after setup failure for a fresh normal admission', function (): void {
    $run = '0c4f71cf-84bd-4b80-8da4-8cd1b39cb8b6';
    $operation = Operation::query()->create(['kind' => 'instance.create', 'target_type' => 'instance', 'target_id' => $this->instance->id, 'node_id' => $this->node->id, 'status' => 'running', 'input' => $this->input, 'state' => ['swamp_run_id' => $run]]);
    app(DevelopmentInstanceCreateProjection::class)->apply($operation, ['run_id' => $run, 'status' => 'failed', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'error_code' => 'instance.setup_step_failed', 'result' => ['ready' => false, 'commit' => str_repeat('a', 40), 'failed_step' => 'setup']]);
    expect($this->instance->refresh()->status)->toBe(InstanceState::SourceResolved)
        ->and($this->instance->first_setup_pending)->toBeTrue()
        ->and($this->instance->failed_step)->toBe('setup')
        ->and($this->instance->environment_owned_keys)->toBe(['APP_NAME', 'MODE']);
    $plan = app(NativeInstanceProvisioningIntent::class)->create($this->instance, $this->input);
    expect($plan['retained_source']['commit'])->toBe(str_repeat('a', 40));
});

it('keeps no environment ownership when create fails before writing .env', function (): void {
    $run = '0c4f71cf-84bd-4b80-8da4-8cd1b39cb8b6';
    $operation = Operation::query()->create(['kind' => 'instance.create', 'target_type' => 'instance', 'target_id' => $this->instance->id, 'node_id' => $this->node->id, 'status' => 'running', 'input' => $this->input, 'state' => ['swamp_run_id' => $run]]);
    app(DevelopmentInstanceCreateProjection::class)->apply($operation, ['run_id' => $run, 'status' => 'failed', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'error_code' => 'instance.branch_in_use', 'result' => ['ready' => false, 'failed_step' => 'prepare']]);
    expect($this->instance->refresh()->status)->toBe(InstanceState::Reserved)
        ->and($this->instance->failed_step)->toBe('prepare')
        ->and($this->instance->environment_owned_keys)->toBeNull();
});

it('refuses incomplete success without activating the Instance', function (): void {
    $run = '0c4f71cf-84bd-4b80-8da4-8cd1b39cb8b6';
    $operation = Operation::query()->create(['kind' => 'instance.create', 'target_type' => 'instance', 'target_id' => $this->instance->id, 'node_id' => $this->node->id, 'status' => 'running', 'input' => $this->input, 'state' => ['swamp_run_id' => $run]]);
    expect(fn () => app(DevelopmentInstanceCreateProjection::class)->apply($operation, ['run_id' => $run, 'status' => 'succeeded', 'result' => ['instance_id' => $this->instance->id, 'ready' => true]]))->toThrow(ResourceOperationException::class);
    expect($this->instance->refresh()->status)->toBe(InstanceState::Reserved);
});

it('captures checkout scope and admitted seed values in the one private setup list copy', function (): void {
    ProjectLifecycleStep::query()->create(['project_id' => $this->project->id, 'phase' => 'setup', 'position' => 0,
        'name' => 'prepare', 'command' => 'printf private-command', 'timeout_seconds' => 30]);
    $plan = app(NativeInstanceProvisioningIntent::class)->create($this->instance, $this->input);
    $plan['application'] = $plan['checkout'].'/nested/application';
    $operation = Operation::query()->create(['kind' => 'instance.create', 'target_type' => 'instance', 'target_id' => $this->instance->id,
        'node_id' => $this->node->id, 'status' => 'queued', 'input' => [...$this->input, 'seed_path' => '/captured/default', 'seed_commit' => str_repeat('a', 40),
            'ssh_execution' => [], 'direct_provisioning' => $plan]]);
    $this->instance->update(['seed_path' => '/later/default', 'seed_commit' => str_repeat('b', 40)]);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $request): array => [['type' => 'vault.put', 'id' => $request['id'], 'payload' => ['data' => ['vaultName' => $request['payload']['vaultName'], 'secretKey' => $request['payload']['key'], 'vaultType' => 'local_encryption']]]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'synthetic-token']);
    $public = app(NativeDevelopmentInstanceInput::class)->create($operation);
    $setup = json_decode($socket->sent[2]['payload']['value'], true, flags: JSON_THROW_ON_ERROR);
    expect($socket->sent[2]['payload']['key'])->toBe('instance-create-'.$operation->id)
        ->and($public['execution_secrets']['setup'])->toBe(['vault' => 'orbit-control-inputs', 'key' => 'instance-create-'.$operation->id])
        ->and($setup['steps'])->toHaveCount(1)
        ->and($setup['steps'][0])->toMatchArray(['name' => 'prepare', 'command' => 'printf private-command', 'sha256' => hash('sha256', 'printf private-command'),
            'timeout_seconds' => 30, 'directory' => $plan['checkout'], 'seed_path' => '/captured/default', 'seed_commit' => str_repeat('a', 40)])
        ->and(json_encode($public))->not->toContain('private-command');
});

it('renders .env at admission from the Project template and pushes exactly that file at run start', function (): void {
    $this->project->update(['type' => 'monorepo', 'root' => 'apps/site/public', 'is_laravel' => true]);
    app(App\Domain\Projects\ProjectEnvironmentTemplateRefresh::class)->recordRead($this->project, str_repeat('c', 40), null, "TEMPLATE=\"from template\"\nAPP_ENV=local\nGONE=1\n");
    app(App\Domain\Projects\ProjectEnvironmentTemplateRefresh::class)->recordRead($this->project, str_repeat('d', 40), null, "TEMPLATE=\"from template\"\nAPP_ENV=local\n");
    $environment = app(App\Domain\Instances\Environment\DevelopmentCreateEnvironment::class)->admit($this->instance->refresh(), $this->project->refresh());
    $stored = app(App\Domain\Instances\Environment\InstanceEnvironmentStore::class)->values($this->instance);
    expect($environment)->toBe(['APP_DEBUG', 'APP_ENV', 'APP_KEY', 'APP_NAME', 'TEMPLATE'])
        ->and($stored['APP_ENV'])->toBe('local')->and($stored['APP_KEY'])->toStartWith('base64:');
    $again = app(App\Domain\Instances\Environment\DevelopmentCreateEnvironment::class)->admit($this->instance, $this->project);
    expect(app(App\Domain\Instances\Environment\InstanceEnvironmentStore::class)->values($this->instance)['APP_KEY'])->toBe($stored['APP_KEY'])
        ->and($again)->toBe($environment);

    $plan = app(NativeInstanceProvisioningIntent::class)->create($this->instance->refresh(), $this->input);
    $operation = Operation::query()->create(['kind' => 'instance.create', 'target_type' => 'instance', 'target_id' => $this->instance->id,
        'node_id' => $this->node->id, 'status' => 'queued', 'input' => [...$this->input, 'ssh_execution' => [], 'direct_provisioning' => $plan]]);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $request): array => [['type' => 'vault.put', 'id' => $request['id'], 'payload' => ['data' => ['vaultName' => $request['payload']['vaultName'], 'secretKey' => $request['payload']['key'], 'vaultType' => 'local_encryption']]]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'synthetic-token']);

    $public = app(NativeDevelopmentInstanceInput::class)->create($operation);

    $file = json_decode($socket->sent[1]['payload']['value'], true, flags: JSON_THROW_ON_ERROR);
    expect($socket->sent[1]['payload']['key'])->toBe('instance-create-environment-input-'.$operation->id)
        ->and($file['directory'])->toBe($this->instance->checkout_path.'/apps/site')
        ->and($file['checkout'])->toBe($this->instance->checkout_path)
        ->and($file['content'])->toBe(app(App\Domain\Instances\Environment\DevelopmentCreateEnvironment::class)->content($this->instance))
        ->and($file['content'])->toContain('TEMPLATE="from template"')
        ->and(json_encode($public))->not->toContain($stored['APP_KEY'])
        ->and(json_encode($public))->not->toContain('from template');
});

it('serializes an empty stored environment and empty template as defaults only', function (): void {
    $environment = app(App\Domain\Instances\Environment\DevelopmentCreateEnvironment::class)->admit($this->instance, $this->project);
    expect($environment)->toBe(['APP_DEBUG', 'APP_ENV', 'APP_NAME']);
});
