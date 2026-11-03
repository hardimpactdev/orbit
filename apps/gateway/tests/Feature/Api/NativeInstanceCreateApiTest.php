<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\DatabaseServers\DatabaseStatements;
use App\Domain\Instances\DevelopmentInstanceCreateProjection;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\InstanceEnvironmentValue;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Project;
use App\Models\ProjectLifecycleStep;
use App\Models\Route;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    // The Route's TLS probe runs from Node 209 (route.create).
    Node::query()->forceCreate(['id' => 209, 'name' => 'swamp-control', 'platform' => 'linux', 'architecture' => 'x86_64',
        'user' => 'orbit', 'wireguard_ip' => '10.44.0.209', 'public_ssh_host' => '192.0.2.209', 'status' => 'active',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'control-host-key']);
    config(['swamp.run_token' => 'fixture-shared-token']);
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = static fn (array $sent): array => $sent['type'] === 'vault.put'
        ? [['type' => 'vault.put', 'id' => $sent['id'], 'payload' => ['data' => ['vaultName' => $sent['payload']['vaultName'], 'secretKey' => $sent['payload']['key'], 'vaultType' => 'local_encryption']]]]
        : [['type' => 'event', 'id' => $sent['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-instance-create', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]];
    app()->instance(WebSocketClient::class, $this->socket);
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldNotReceive('run');
    app()->instance(AgentCommandRunner::class, $agents);
    $accounts = Mockery::mock(ManagedUserAccountResolver::class);
    $accounts->shouldNotReceive('resolve');
    app()->instance(ManagedUserAccountResolver::class, $accounts);
    $this->node = Node::query()->create(['name' => 'create-dev', 'user' => 'orbit', 'platform' => 'linux', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG',
        'architecture' => 'amd64', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9',
        'status' => 'active', 'settings' => ['apps' => ['path' => '/srv/apps']]]);
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $this->node->accessibleNodes()->attach($this->node);
    $this->project = Project::query()->create(['name' => 'Native create', 'slug' => 'native-create',
        'type' => 'node-package', 'root' => '.', 'repository_url' => 'https://github.com/example/native-create.git',
        'default_branch' => 'main']);
    $this->payload = ['project_id' => $this->project->id, 'node_id' => $this->node->id, 'name' => 'proof'];
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    Queue::fake();
});

it('records one native root with source environment and setup steps before any Node effect', function (): void {
    $this->withHeader('Idempotency-Key', 'native-create-proof');
    $id = $this->postJson('/api/v1/instances', $this->payload)->assertAccepted()
        ->assertJsonPath('data.kind', 'instance.create')->assertJsonPath('data.status', 'running')->json('data.id');
    $this->postJson('/api/v1/instances', $this->payload)->assertAccepted()->assertJsonPath('data.id', $id);

    $instance = Instance::query()->sole();
    $operation = Operation::query()->sole();
    expect($instance->status)->toBe(InstanceState::Reserved)
        ->and($instance->checkout_path)->toBe('/srv/apps/native-create/proof')
        ->and($operation->input['participant_node_ids'])->toBe([$this->node->id])
        ->and($operation->input['direct_provisioning']['setup']['execution']['user'])->toBe('orbit')
        ->and($operation->input['direct_provisioning'])->not->toHaveKey('agent_sha256')
        ->and($operation->input['run_budget_seconds'])->toBe(90)
        ->and(array_column(array_column($this->socket->sent, 'payload'), 'key'))->toBe(['instance-git-operation-'.$operation->id,
            'instance-git-credential-'.$operation->id, 'instance-create-environment-input-'.$operation->id])
        ->and($this->socket->sent[3]['payload']['workflowIdOrName'])->toBe('operation-instance-create')
        ->and(Route::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('budgets the run as 60 seconds of create work, the admitted setup timeouts and 30 seconds for Processes', function (): void {
    ProjectLifecycleStep::query()->create(['project_id' => $this->project->id, 'phase' => 'setup', 'position' => 0,
        'name' => 'install', 'command' => 'composer install', 'timeout_seconds' => 300]);
    $this->withHeader('Idempotency-Key', 'native-create-budget');
    $this->postJson('/api/v1/instances', $this->payload)->assertAccepted();
    expect(Operation::query()->sole()->input['run_budget_seconds'])->toBe(390);
});

it('freezes the selected default source seed in the first native admission', function (): void {
    $seed = Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id,
        'name' => 'default', 'checkout_path' => '/srv/apps/native-create/default', 'status' => 'active',
        'branch' => 'main', 'seed_path' => '/srv/apps/native-create/default', 'seed_commit' => str_repeat('a', 40)]);

    $id = $this->postJson('/api/v1/instances', $this->payload)->assertAccepted()->json('data.id');
    $input = Operation::query()->findOrFail($id)->input;
    expect($input['seed_repository'])->toBe($seed->checkout_path)
        ->and($input['seed_commit'])->toBe($seed->seed_commit)
        ->and($input['direct_provisioning']['source']['seed_repository'])->toBe($seed->checkout_path)
        ->and($input['direct_provisioning']['setup']['execution']['seed_path'])->toBe($seed->seed_path)
        ->and($input['direct_provisioning']['setup']['execution']['seed_commit'])->toBe($seed->seed_commit);
});

it('copies the default Instance\'s SQLite database into the new checkout and attaches it under DB', function (): void {
    $default = Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id, 'name' => 'default',
        'checkout_path' => '/srv/apps/native-create/default', 'status' => 'active', 'branch' => 'main']);
    $source = DatabaseConnection::query()->create(['slug' => 'native-create-default', 'driver' => 'sqlite', 'node_id' => $this->node->id,
        'owner_instance_id' => $default->id, 'path' => '/srv/apps/native-create/default/database/database.sqlite']);
    DatabaseConnectionTarget::query()->create(['instance_id' => $default->id, 'database_connection_id' => $source->id, 'prefix' => 'DB']);
    $this->withHeader('Idempotency-Key', 'native-create-sqlite-copy');
    $this->postJson('/api/v1/instances', $this->payload)->assertAccepted();

    $instance = Instance::query()->where('name', 'proof')->sole();
    $operation = Operation::query()->sole();
    $copy = DatabaseConnection::query()->where('slug', 'native-create-proof')->sole();
    $database = $operation->input['direct_provisioning']['database'];
    expect($copy->path)->toBe('/srv/apps/native-create/proof/database/database.sqlite')
        ->and($copy->owner_instance_id)->toBe($instance->id)
        ->and($instance->databaseConnectionTargets()->sole()->database_connection_id)->toBe($copy->id)
        ->and($database['sqlite'])->toBe(['source' => $source->path, 'user' => 'orbit',
            'snapshot' => '/tmp/orbit-create-sqlite-'.$instance->id.'.sqlite', 'target' => $copy->path])
        ->and($database['candidate']['ssh_execution']['target']['host'])->toBe('10.44.0.9')
        ->and(collect($this->socket->sent)->firstWhere('payload.workflowIdOrName', 'operation-instance-create')['payload']['inputs']['intent']['environment_testing'])->toBeFalse()
        ->and(app(InstanceEnvironmentStore::class)->values($instance))->toMatchArray(['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $copy->path])
        ->and($operation->input['run_budget_seconds'])->toBe(270);

    // An identical retry reuses the copy this create owns.
    $operation->update(['status' => 'failed']);
    $this->withHeader('Idempotency-Key', 'native-create-sqlite-copy-retry');
    $this->postJson('/api/v1/instances', $this->payload)->assertAccepted();
    expect(DatabaseConnection::query()->where('slug', 'native-create-proof')->count())->toBe(1);
});

it('owns its database on a Database server and copies a MySQL default database into it', function (bool $explicit): void {
    $server = DatabaseServer::query()->create(['slug' => 'beast-mysql', 'driver' => 'mysql', 'node_id' => $this->node->id, 'port' => 3306,
        'tag' => '8.4', 'root_password' => 'root-secret', 'status' => 'active']);
    $process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $this->node->id, 'name' => 'beast-mysql', 'runtime' => 'docker',
        'runtime_config' => ['image' => 'mysql:8.4'], 'restart_policy' => 'unless-stopped', 'desired_state' => 'running', 'status' => 'active']);
    $server->update(['process_id' => $process->id]);
    // A Database server is a Gateway resource, so --database-server needs access to the Gateway Node too.
    $gateway = Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.1', 'user' => 'orbit', 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.1', 'status' => 'active']);
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->node->accessibleNodes()->attach($gateway);
    if (! $explicit) {
        $default = Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id, 'name' => 'default',
            'checkout_path' => '/srv/apps/native-create/default', 'status' => 'active', 'branch' => 'main']);
        $source = DatabaseConnection::query()->create(['slug' => 'native-create-default', 'driver' => 'mysql', 'node_id' => $this->node->id,
            'database_server_id' => $server->id, 'database' => 'native_create', 'host' => '10.44.0.9', 'port' => 3306, 'username' => 'native', 'password' => 'secret']);
        DatabaseConnectionTarget::query()->create(['instance_id' => $default->id, 'database_connection_id' => $source->id, 'prefix' => 'DB']);
    }
    $this->withHeader('Idempotency-Key', 'native-create-server-'.(int) $explicit);
    $this->postJson('/api/v1/instances', [...$this->payload, ...($explicit ? ['database_server' => 'beast-mysql'] : [])])->assertAccepted();

    $instance = Instance::query()->where('name', 'proof')->sole();
    $operation = Operation::query()->sole();
    $owned = DatabaseConnection::query()->where('slug', 'native-create-proof')->sole();
    $admitted = $operation->input['direct_provisioning']['server_database'];
    expect($owned->database_server_id)->toBe($server->id)->and($owned->owner_instance_id)->toBe($instance->id)
        ->and($instance->databaseConnectionTargets()->sole()->database_connection_id)->toBe($owned->id)
        ->and(app(InstanceEnvironmentStore::class)->values($instance))->toMatchArray(['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $owned->database])
        ->and($admitted)->toMatchArray(['connection_id' => $owned->id, 'server_id' => $server->id, 'node_id' => $this->node->id,
            'source' => $explicit ? null : 'native_create'])
        ->and($operation->input['direct_provisioning']['database'])->toBeNull()
        ->and($operation->input['run_budget_seconds'])->toBe(270);

    $start = collect($this->socket->sent)->firstWhere('payload.workflowIdOrName', 'operation-instance-create');
    $inputs = $start['payload']['inputs'];
    expect($inputs['intent']['server_database']['intent']['owner_client'][3])->toBe(app(DockerProcessRenderer::class)->containerName($process))
        ->and($inputs['execution_secrets']['server_database_create']['key'])->toBe('database-create-intent-'.$admitted['intent_id'])
        ->and($inputs['execution_secrets']['server_database_check']['key'])->toBe('database-create-owner-'.$admitted['intent_id'])
        ->and($inputs['intent']['environment_testing'])->toBeTrue()
        ->and($inputs['execution_secrets']['environment_testing']['key'])->toBe('instance-create-environment-testing-'.$operation->id)
        ->and($owned->test_database)->not->toBeNull()
        ->and($inputs['intent']['server_database']['fill'])->toBe($explicit ? null
            : DatabaseStatements::copy(app(DockerProcessRenderer::class)->containerName($process), 'native_create', $owned->database));

    // An identical retry records the same owned database again.
    $operation->update(['status' => 'failed']);
    $this->withHeader('Idempotency-Key', 'native-create-server-retry-'.(int) $explicit);
    $this->postJson('/api/v1/instances', [...$this->payload, ...($explicit ? ['database_server' => 'beast-mysql'] : [])])->assertAccepted();
    expect(DatabaseConnection::query()->where('slug', 'native-create-proof')->count())->toBe(1);
})->with(['--database-server' => true, 'MySQL default database' => false]);

it('refuses --database-server for an Instance that finished its first setup', function (): void {
    $gateway = Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.1', 'user' => 'orbit', 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.1', 'status' => 'active']);
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->node->accessibleNodes()->attach($gateway);
    Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id, 'name' => 'proof',
        'checkout_path' => '/srv/apps/native-create/proof', 'status' => 'active', 'branch' => 'proof', 'first_setup_pending' => false,
        'starting_commit' => str_repeat('a', 40)]);
    $this->postJson('/api/v1/instances', [...$this->payload, 'database_server' => 'beast-mysql'])->assertConflict()
        ->assertJsonPath('error.code', 'instance.database_server_existing');
    expect(Operation::query()->count())->toBe(0);

    // Once it owns a database on that server, the repeat is the finished create's no-op.
    $server = DatabaseServer::query()->create(['slug' => 'beast-mysql', 'driver' => 'mysql', 'node_id' => $this->node->id, 'port' => 3306,
        'tag' => '8.4', 'root_password' => 'root-secret', 'status' => 'active']);
    DatabaseConnection::query()->create(['slug' => 'native-create-proof', 'driver' => 'mysql', 'node_id' => $this->node->id, 'database_server_id' => $server->id,
        'owner_instance_id' => Instance::query()->sole()->id, 'database' => 'native_create_proof', 'host' => '10.44.0.9', 'port' => 3306, 'username' => 'u', 'password' => 'p']);
    $this->withHeader('Idempotency-Key', 'native-create-server-existing-noop');
    $this->postJson('/api/v1/instances', [...$this->payload, 'database_server' => 'beast-mysql'])->assertAccepted();
    expect(Operation::query()->sole()->input['direct_provisioning'])->toMatchArray(['already_active' => true, 'server_database' => null]);
});

it('answers an already active Instance with a database as a no-op', function (): void {
    $instance = Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id, 'name' => 'proof',
        'checkout_path' => '/srv/apps/native-create/proof', 'status' => 'active', 'branch' => 'proof', 'first_setup_pending' => false,
        'starting_commit' => str_repeat('a', 40)]);
    $connection = DatabaseConnection::query()->create(['slug' => 'native-create-proof', 'driver' => 'sqlite', 'node_id' => $this->node->id,
        'owner_instance_id' => $instance->id, 'path' => '/srv/apps/native-create/proof/database/database.sqlite']);
    DatabaseConnectionTarget::query()->create(['instance_id' => $instance->id, 'database_connection_id' => $connection->id, 'prefix' => 'DB']);
    $this->withHeader('Idempotency-Key', 'native-create-active-database');
    $this->postJson('/api/v1/instances', $this->payload)->assertAccepted();
    expect(Operation::query()->sole()->input['direct_provisioning'])->toMatchArray(['already_active' => true, 'database' => null]);
});

it('refuses immutable placement changes on retry', function (): void {
    $this->postJson('/api/v1/instances', $this->payload)->assertAccepted();
    $this->postJson('/api/v1/instances', [...$this->payload, 'branch' => 'other'])->assertConflict()
        ->assertJsonPath('error.code', 'instance.placement_conflict');
    expect(Instance::query()->count())->toBe(1)->and(Operation::query()->count())->toBe(1);
});

it('reserves the derived Route of a web-serving Project and creates it in the same run', function (string $status): void {
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('public-root');
    $this->node->update(['tld' => 'test']);
    $this->project->update(['type' => 'laravel-app', 'root' => 'public', 'php_version' => '8.4', 'is_laravel' => true, 'source_facts_captured_at' => now()]);
    $this->withHeader('Idempotency-Key', 'native-create-web-'.$status);
    $this->postJson('/api/v1/instances', $this->payload)->assertAccepted();

    $operation = Operation::query()->sole();
    $route = Route::query()->sole();
    $start = collect($this->socket->sent)->firstWhere('payload.workflowIdOrName', 'operation-instance-create');
    $intent = $start['payload']['inputs']['intent'];
    expect($route->domain)->toBe('proof.native-create.test')
        ->and($operation->input['route_id'])->toBe($route->id)
        ->and($operation->input['run_budget_seconds'])->toBe(270)
        ->and(json_encode($intent['route']['caddy']))->toContain('proof.native-create.test')
        ->and(json_encode($intent['route']['php_files'], JSON_UNESCAPED_SLASHES))->toContain('chdir = /srv/apps/native-create/proof')
        ->and(json_encode($intent['route']['probes']))->toContain('proof.native-create.test')
        ->and($intent['route']['awake'])->toBe([['node_id' => $this->node->id, 'instance_id' => $operation->target_id,
            'path' => '/dev/shm/orbit/hibernation/app-instance-'.$operation->target_id.'.awake']])
        ->and($intent['route'])->toBe($operation->input['route_create'])
        ->and($intent['route_secrets'])->toBe($operation->input['execution_secrets']);

    $operation->update(['state' => ['swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]);
    app(DevelopmentInstanceCreateProjection::class)->apply($operation->refresh(), ['run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676',
        'status' => $status, 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'error_code' => $status === 'failed' ? 'instance.create_route_failed' : null,
        'result' => ['instance_id' => $operation->target_id, 'ready' => $status === 'succeeded', 'commit' => str_repeat('a', 40),
            ...($status === 'failed' ? ['failed_step' => 'route', 'error_code' => 'instance.create_route_failed'] : [])]]);
    $route->refresh();
    expect(Instance::query()->sole()->environment_owned_keys)->toBe($operation->input['environment_keys']);
    expect($route->status->value)->toBe($status === 'succeeded' ? 'active' : $route->getOriginal('status')->value)
        ->and($route->sites_published)->toBe($status === 'succeeded')
        ->and($route->error_code)->toBe($status === 'succeeded' ? null : 'instance.create_route_failed');
})->with(['succeeded', 'failed']);

it('marks a web Instance awake only when the run starts every one of its Processes', function (bool $covered): void {
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('public-root');
    $this->node->update(['tld' => 'test']);
    $this->project->update(['type' => 'laravel-app', 'root' => 'public', 'php_version' => '8.4', 'is_laravel' => true, 'source_facts_captured_at' => now()]);
    $this->project->processDefinitions()->create(['name' => 'dev-server', 'environments' => ['development'],
        'spec' => ['runtime' => 'systemd', 'command' => ['/usr/bin/sleep', '300'], 'restart_policy' => 'never']]);
    $this->withHeader('Idempotency-Key', 'native-create-wake-first');
    $this->postJson('/api/v1/instances', $this->payload)->assertAccepted();
    $instance = Instance::query()->sole();
    expect(Operation::query()->sole()->input['route_create']['awake'])->toHaveCount(1);

    // The first run failed after starting its Process; the rerun checks that active Process again.
    Process::query()->update(['status' => 'active']);
    if (! $covered) {
        $instance->processes()->create(['name' => 'hand-made',
            'runtime' => 'systemd', 'runtime_config' => ['command' => ['/usr/bin/sleep', '300']], 'restart_policy' => 'never',
            'desired_state' => 'running', 'status' => 'active']);
    }
    $instance->update(['failed_step' => 'route', 'error_code' => 'instance.create_route_failed']);
    Operation::query()->update(['status' => 'failed']);
    $this->withHeader('Idempotency-Key', 'native-create-wake-rerun');
    $this->postJson('/api/v1/instances', $this->payload)->assertAccepted();

    $rerun = Operation::query()->latest('id')->first();
    expect(array_column($rerun->input['members'], 'id'))->toContain(Process::query()->where('name', 'dev-server')->sole()->id)
        ->and($rerun->input['route_create']['awake'])->toHaveCount($covered ? 1 : 0);
})->with(['every Process is covered' => true, 'a Process is not covered' => false]);

it('starts an active container again on a rerun instead of creating it', function (): void {
    $this->project->processDefinitions()->create(['name' => 'cache', 'environments' => ['development'],
        'spec' => ['runtime' => 'docker', 'image' => 'redis:7', 'command' => ['redis-server'], 'restart_policy' => 'unless-stopped']]);
    $this->withHeader('Idempotency-Key', 'native-create-docker-first');
    $this->postJson('/api/v1/instances', $this->payload)->assertAccepted();
    Process::query()->update(['status' => 'active']);
    Instance::query()->update(['failed_step' => 'processes', 'error_code' => 'instance.create_processes_failed']);
    Operation::query()->update(['status' => 'failed']);
    $this->withHeader('Idempotency-Key', 'native-create-docker-rerun');
    $this->postJson('/api/v1/instances', $this->payload)->assertAccepted();

    $start = collect($this->socket->sent)->where('payload.workflowIdOrName', 'operation-instance-create')->last();
    expect(array_column($start['payload']['inputs']['intent']['processes'], 'kind'))->toBe(['process.start']);
});

it('reserves no Route for an already active web-serving Instance, which runs no phase', function (): void {
    $this->node->update(['tld' => 'test']);
    $this->project->update(['type' => 'laravel-app', 'root' => 'public']);
    Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id, 'name' => 'proof',
        'checkout_path' => '/srv/apps/native-create/proof', 'status' => InstanceState::Active, 'branch' => 'proof',
        'first_setup_pending' => false, 'starting_commit' => str_repeat('a', 40)]);
    $this->withHeader('Idempotency-Key', 'native-create-web-active');
    $this->postJson('/api/v1/instances', $this->payload)->assertAccepted();

    $operation = Operation::query()->sole();
    expect(Route::query()->count())->toBe(0)->and($operation->input)->not->toHaveKey('route_create')
        ->and($operation->input['direct_provisioning']['route'])->toBeNull();
});

it('refuses a web-serving Project before reservation when its Node has no TLD for the derived Route', function (): void {
    $this->project->update(['type' => 'laravel-app', 'root' => 'public']);
    $this->postJson('/api/v1/instances', $this->payload)->assertConflict()->assertJsonPath('error.code', 'route.tld_required');
    expect(Instance::query()->count())->toBe(0)->and(Operation::query()->count())->toBe(0)->and($this->socket->sent)->toBe([]);
});

it('refuses a domain for a Project that serves no web pages', function (): void {
    $this->postJson('/api/v1/instances', [...$this->payload, 'domain' => 'proof.example.test'])->assertUnprocessable()
        ->assertJsonPath('error.code', 'instance.route_unserved');
    expect(Instance::query()->count())->toBe(0)->and($this->socket->sent)->toBe([]);
});

it('requires owning Node access before creating an Instance', function (): void {
    $other = Node::query()->create(['name' => 'outside', 'user' => 'orbit', 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.18', 'wireguard_ip' => '10.44.0.18', 'status' => 'active']);
    $this->withServerVariables(['REMOTE_ADDR' => $other->wireguard_ip]);
    $this->postJson('/api/v1/instances', $this->payload)->assertForbidden()
        ->assertJsonPath('error.code', 'node_access.required');
    expect(Instance::query()->count())->toBe(0)->and($this->socket->sent)->toBe([]);
});

it('rejects a managed checkout overlap before starting a native root', function (): void {
    Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id,
        'name' => 'existing', 'checkout_path' => '/srv/apps/native-create/proof/nested', 'status' => 'active']);
    $this->postJson('/api/v1/instances', $this->payload)->assertConflict()->assertJsonPath('error.code', 'instance.path_taken');
    expect(Instance::query()->count())->toBe(1)->and(Operation::query()->count())->toBe(0);
});

it('creates the development Processes after setup and records them from the terminal result', function (string $status): void {
    $this->project->processDefinitions()->create(['name' => 'dev-server', 'environments' => ['development'],
        'spec' => ['runtime' => 'systemd', 'command' => ['/usr/bin/python3', '-m', 'http.server', '8765'], 'restart_policy' => 'on-failure']]);
    $this->project->processDefinitions()->create(['name' => 'production-only', 'environments' => ['production'],
        'spec' => ['runtime' => 'systemd', 'command' => ['/usr/bin/sleep', '300'], 'restart_policy' => 'never']]);
    $this->withHeader('Idempotency-Key', 'native-create-processes-'.$status);
    $this->postJson('/api/v1/instances', $this->payload)->assertAccepted();

    $operation = Operation::query()->sole();
    $process = Process::query()->sole();
    $start = collect($this->socket->sent)->firstWhere('payload.workflowIdOrName', 'operation-instance-create');
    $inputs = $start['payload']['inputs'];
    expect($process->name)->toBe('dev-server')->and($process->desired_state->value)->toBe('running')
        ->and($operation->input['members'])->toHaveCount(1)
        ->and($inputs['intent']['processes'][0])->toMatchArray(['index' => 0, 'id' => $process->id, 'kind' => 'process.create'])
        ->and($inputs['intent']['processes'][0]['intent']['control']['name'])->toBe('orbit-process-'.$process->id.'-dev-server.service')
        ->and($inputs['intent']['processes'][0]['intent']['installation']['unit'])->toContain('http.server');

    $operation->update(['state' => ['swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]);
    app(DevelopmentInstanceCreateProjection::class)->apply($operation->refresh(), ['run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676',
        'status' => $status, 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'error_code' => $status === 'failed' ? 'instance.create_processes_failed' : null,
        'result' => ['instance_id' => $operation->target_id, 'ready' => $status === 'succeeded', 'commit' => str_repeat('a', 40),
            ...($status === 'failed' ? ['failed_step' => 'processes'] : [])]]);
    expect($process->refresh()->status->value)->toBe($status === 'succeeded' ? 'active' : 'failed')
        ->and(Instance::query()->sole()->status)->toBe($status === 'succeeded' ? InstanceState::Active : InstanceState::SourceResolved);
})->with(['succeeded', 'failed']);

it('records no environment or Process for an already active Instance, which runs no phase', function (): void {
    $this->withHeader('Idempotency-Key', 'native-create-active-first');
    $this->postJson('/api/v1/instances', $this->payload)->assertAccepted();
    $instance = Instance::query()->sole();
    $instance->update(['status' => InstanceState::Active, 'first_setup_pending' => false, 'starting_commit' => str_repeat('a', 40)]);
    InstanceEnvironmentValue::query()->where('instance_id', $instance->id)->delete();
    $this->project->processDefinitions()->create(['name' => 'dev-server', 'environments' => ['development'],
        'spec' => ['runtime' => 'systemd', 'command' => ['/usr/bin/sleep', '300'], 'restart_policy' => 'never']]);
    Operation::query()->update(['status' => 'succeeded']);

    $this->withHeader('Idempotency-Key', 'native-create-active-again');
    $this->postJson('/api/v1/instances', $this->payload)->assertAccepted();

    $operation = Operation::query()->latest('id')->first();
    expect($operation->input['direct_provisioning']['already_active'])->toBeTrue()
        ->and($operation->input['members'])->toBe([])->and($operation->input['environment_keys'])->toBe([])
        ->and(Process::query()->count())->toBe(0)
        ->and(InstanceEnvironmentValue::query()->where('instance_id', $instance->id)->count())->toBe(0);
});

it('refuses create before reservation for a Project whose source profile is unsupported', function (): void {
    $this->project->update(['source_profile_error' => 'app-dev.php_version_unsupported', 'source_facts_captured_at' => now()]);
    $this->withHeader('Idempotency-Key', 'native-create-unsupported');
    $this->postJson('/api/v1/instances', $this->payload)->assertStatus(422)->assertJsonPath('error.code', 'app-dev.php_version_unsupported');
    expect(Instance::query()->count())->toBe(0);
});
