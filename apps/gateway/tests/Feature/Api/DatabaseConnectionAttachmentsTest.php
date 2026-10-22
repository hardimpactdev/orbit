<?php

declare(strict_types=1);

use App\Domain\DatabaseConnections\DatabaseConnectionEnvProjection;
use App\Domain\Doctor\DatabaseConnectionDoctorInspection;
use App\Domain\Instances\Environment\InstanceEnvironmentContext;
use App\Domain\Instances\Environment\InstanceEnvironmentReader;
use App\Domain\Instances\Environment\InstanceEnvironmentWriter;
use App\Domain\Instances\Environment\InstanceEnvironmentWriteResult;
use App\Domain\Instances\Environment\InstanceOperationPreflight;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Activity;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\InstanceEnvironmentValue;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use App\Models\Route;

const DATABASE_ATTACHMENT_SECRET = 'db-attach-secret-7c21';

beforeEach(function (): void {
    [$this->caller, $this->instance, $this->route] = database_attachment_fixture();
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);
    app()->instance(InstanceOperationPreflight::class, new DatabaseAttachmentAccess);
    app()->instance(InstanceEnvironmentReader::class, new DatabaseAttachmentAccess);
    app()->instance(InstanceEnvironmentWriter::class, new DatabaseAttachmentAccess);
});

it('attaches mysql keys into stored Instance env and redacts the password', function (): void {
    $this->postJson('/api/v1/database-connections', [
        'slug' => 'app',
        'driver' => 'mysql',
        'host' => 'db.example.test',
        'database' => 'app',
        'username' => 'app',
        'password' => DATABASE_ATTACHMENT_SECRET,
    ])->assertCreated();

    $attach = $this->call(
        'PUT',
        "/api/v1/instances/{$this->instance->id}/database-connections/app",
        server: ['CONTENT_TYPE' => 'application/json'],
        content: '{}',
    );

    $attach
        ->assertOk()
        ->assertJsonPath('data.instance_id', $this->instance->id)
        ->assertJsonPath('data.slug', 'app')
        ->assertJsonPath('data.prefix', 'DB')
        ->assertJsonPath('data.operation', 'attach')
        ->assertJsonPath('data.changed', true)
        ->assertJsonPath('data.host', 'db.example.test')
        ->assertJsonPath('data.port', 3306)
        ->assertJsonPath('data.keys', [
            'DB_CONNECTION',
            'DB_DATABASE',
            'DB_HOST',
            'DB_PASSWORD',
            'DB_PORT',
            'DB_USERNAME',
        ])
        ->assertJsonMissingPath('data.password');

    expect($attach->getContent())->not->toContain(DATABASE_ATTACHMENT_SECRET);
    expect(stored_env($this->instance))->toBe([
        'DB_CONNECTION' => 'mysql',
        'DB_DATABASE' => 'app',
        'DB_HOST' => 'db.example.test',
        'DB_PASSWORD' => DATABASE_ATTACHMENT_SECRET,
        'DB_PORT' => '3306',
        'DB_USERNAME' => 'app',
    ]);
    expect(DatabaseConnectionTarget::query()->sole()->prefix)->toBe('DB');

    $activity = Activity::query()->where('request_id', $attach->json('meta.request_id'))->sole();
    $encoded = json_encode($activity->properties?->toArray() ?? [], JSON_THROW_ON_ERROR);

    expect($activity->command)
        ->toBe('instance:database:add')
        ->and($activity->subject_type)
        ->toBe('instance')
        ->and($activity->subject_id)
        ->toBe($this->instance->id)
        ->and($activity->target_node_id)
        ->toBe($this->instance->node_id)
        ->and($encoded)
        ->not->toContain(DATABASE_ATTACHMENT_SECRET)
        ->and(print_r($this->instance->environmentValues, true))
        ->not->toContain(DATABASE_ATTACHMENT_SECRET);

    $this->deleteJson('/api/v1/database-connections/app')
        ->assertConflict()
        ->assertJsonPath('error.code', 'database.connection_attached');
});

it('writes sqlite-appropriate keys and clears leftover host keys on prefix reuse', function (): void {
    $this->postJson('/api/v1/database-connections', [
        'slug' => 'app',
        'driver' => 'mysql',
        'host' => 'db.example.test',
        'database' => 'app',
        'username' => 'app',
        'password' => DATABASE_ATTACHMENT_SECRET,
    ])->assertCreated();
    $this->call(
        'PUT',
        "/api/v1/instances/{$this->instance->id}/database-connections/app",
        server: ['CONTENT_TYPE' => 'application/json'],
        content: '{}',
    )->assertOk();

    $this->postJson('/api/v1/database-connections', [
        'slug' => 'local',
        'driver' => 'sqlite',
        'node_id' => $this->instance->node_id,
        'path' => '/var/lib/app/database.sqlite',
    ])->assertCreated();

    $this->call(
        'PUT',
        "/api/v1/instances/{$this->instance->id}/database-connections/local",
        server: ['CONTENT_TYPE' => 'application/json'],
        content: '{"replace":true}',
    )
        ->assertOk()
        ->assertJsonPath('data.keys', [
            'DB_CONNECTION',
            'DB_DATABASE',
        ])
        ->assertJsonPath('data.host', null)
        ->assertJsonPath('data.port', null);

    expect(stored_env($this->instance))->toBe([
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => '/var/lib/app/database.sqlite',
    ]);
    expect(DatabaseConnectionTarget::query()->sole()->database_connection_id)
        ->toBe(DatabaseConnection::query()->where('slug', 'local')->sole()->id);
});

it('writes the registry host and port for a same-Node Instance even when a Node Docker Process publishes the port', function (string $ports): void {
    Process::query()->create([
        'owner_type' => Node::class,
        'owner_id' => $this->instance->node_id,
        'name' => 'mysql-84',
        'runtime' => ProcessRuntime::Docker,
        'working_directory' => '/app',
        'runtime_config' => [
            'image' => 'mysql:8.4',
            'command' => ['mysqld'],
            'environment' => [],
            'ports' => [$ports],
            'volumes' => [],
        ],
        'restart_policy' => 'unless-stopped',
        'desired_state' => DesiredProcessState::Running,
        'status' => LifecycleStatus::Active,
    ]);

    $this->postJson('/api/v1/database-connections', [
        'slug' => 'app',
        'driver' => 'mysql',
        'node_id' => $this->instance->node_id,
        'host' => '10.44.0.201',
        'port' => 3306,
        'database' => 'app',
        'username' => 'app',
        'password' => DATABASE_ATTACHMENT_SECRET,
    ])->assertCreated();

    $this->call(
        'PUT',
        "/api/v1/instances/{$this->instance->id}/database-connections/app",
        server: ['CONTENT_TYPE' => 'application/json'],
        content: '{}',
    )
        ->assertOk()
        ->assertJsonPath('data.host', '10.44.0.201')
        ->assertJsonPath('data.port', 3306);

    expect(stored_env($this->instance)['DB_HOST'])->toBe('10.44.0.201')
        ->and(stored_env($this->instance)['DB_PORT'])->toBe('3306');

    $remote = Node::query()->create([
        'name' => 'remote-app',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.210',
        'wireguard_ip' => '10.44.0.210',
        'user' => 'orbit',
    ]);
    orbit_test_set_app_placement_role($remote, false);
    $remoteInstance = Instance::query()->create([
        'project_id' => $this->instance->project_id,
        'node_id' => $remote->id,
        'name' => 'remote',
        'environment' => 'development',
        'checkout_path' => '/srv/orbit/environment-api/remote',
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
    ]);
    $remoteRoute = Route::query()->create([
        'project_id' => $this->instance->project_id,
        'node_id' => $remote->id,
        'domain' => 'environment-api-remote.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $remoteRoute->targets()->create(['instance_id' => $remoteInstance->id, 'position' => 0]);
    $remoteRoute->update(['status' => RouteStatus::Active]);
    $remoteInstance->update(['status' => 'active']);

    $this->call(
        'PUT',
        "/api/v1/instances/{$remoteInstance->id}/database-connections/app",
        server: ['CONTENT_TYPE' => 'application/json'],
        content: '{}',
    )
        ->assertOk()
        ->assertJsonPath('data.host', '10.44.0.201')
        ->assertJsonPath('data.port', 3306);

    expect(stored_env($remoteInstance->fresh())['DB_HOST'])->toBe('10.44.0.201')
        ->and(stored_env($remoteInstance->fresh())['DB_PORT'])->toBe('3306');
})->with(['3308:3306', '127.0.0.1:3307:3306/tcp', '0.0.0.0:3307:3306/tcp', '10.44.0.201:3307:3306']);
it('detaches the mapping and clears related stored keys without logging the password', function (): void {
    $this->postJson('/api/v1/database-connections', [
        'slug' => 'app',
        'driver' => 'mysql',
        'host' => 'db.example.test',
        'database' => 'app',
        'username' => 'app',
        'password' => DATABASE_ATTACHMENT_SECRET,
    ])->assertCreated();
    $this->putJson("/api/v1/instances/{$this->instance->id}/database-connections/app", [
        'prefix' => 'CACHE_DB',
    ])->assertOk();
    $this->putJson("/api/v1/instances/{$this->instance->id}/environment/APP_KEY", [
        'value' => 'keep-me',
    ])->assertOk();

    $detach = $this->call(
        'DELETE',
        "/api/v1/instances/{$this->route->domain}/database-connections/app",
        server: ['CONTENT_TYPE' => 'application/json'],
        content: '{"prefix":"CACHE_DB"}',
    );

    $detach
        ->assertOk()
        ->assertJsonPath('data.operation', 'detach')
        ->assertJsonPath('data.prefix', 'CACHE_DB')
        ->assertJsonPath('data.changed', true);

    expect($detach->getContent())->not->toContain(DATABASE_ATTACHMENT_SECRET);
    expect(Activity::query()->where('request_id', $detach->json('meta.request_id'))->sole())
        ->command->toBe('instance:database:remove')
        ->subject_type->toBe('instance')
        ->subject_id->toBe($this->instance->id)
        ->target_node_id->toBe($this->instance->node_id);
    expect(stored_env($this->instance))->toBe(['APP_KEY' => 'keep-me']);
    expect(DatabaseConnectionTarget::query()->exists())->toBeFalse();

    $this->call(
        'DELETE',
        "/api/v1/instances/{$this->instance->id}/database-connections/app",
        server: ['CONTENT_TYPE' => 'application/json'],
        content: '{}',
    )
        ->assertNotFound()
        ->assertJsonPath('error.code', 'database.attachment_missing');

    $this->deleteJson('/api/v1/database-connections/app')
        ->assertOk()
        ->assertJsonPath('data.slug', 'app');
});

it('rejects sqlite attachments without the same Node before changing stored state', function (bool $missingNode): void {
    $this->instance->environmentValues()->create(['env_key' => 'DB_DATABASE', 'env_value' => 'keep-me']);
    $this->postJson('/api/v1/database-connections', [
        'slug' => 'wrong-node',
        'driver' => 'sqlite',
        'node_id' => $missingNode ? null : $this->caller->id,
        'path' => '/var/lib/app/database.sqlite',
    ])->assertCreated();

    $this->putJson("/api/v1/instances/{$this->instance->id}/database-connections/wrong-node", ['prefix' => 'DB'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'database.node_mismatch');

    expect(DatabaseConnectionTarget::query()->exists())->toBeFalse()
        ->and(stored_env($this->instance))->toBe(['DB_DATABASE' => 'keep-me']);
})->with([true, false]);

it('refuses updates that put a SQLite connection on a different Node from any attached Instance', function (string $driver, bool $clearNode): void {
    $nodeId = $driver === 'sqlite' ? $this->instance->node_id : $this->caller->id;
    $connection = DatabaseConnection::query()->create([
        'slug' => 'update-targets', 'driver' => $driver, 'node_id' => $nodeId,
        ...($driver === 'sqlite'
            ? ['path' => '/var/lib/app/database.sqlite']
            : ['host' => 'db.example.test', 'database' => 'app', 'username' => 'app', 'password' => DATABASE_ATTACHMENT_SECRET]),
    ]);

    if ($driver === 'mysql') {
        // The first target matches the proposed Node; every target must be checked.
        $matching = Instance::query()->create([
            'project_id' => $this->instance->project_id, 'node_id' => $nodeId,
            'name' => 'matching', 'checkout_path' => '/srv/orbit/matching',
        ]);
        $connection->targets()->create(['instance_id' => $matching->id, 'prefix' => 'DB']);
    }

    $this->putJson("/api/v1/instances/{$this->instance->id}/database-connections/update-targets", ['prefix' => 'DB'])
        ->assertOk();
    $original = $connection->refresh()->getRawOriginal();
    $environment = stored_env($this->instance);
    $targets = DatabaseConnectionTarget::query()->get()->toArray();

    $this->patchJson('/api/v1/database-connections/update-targets', $driver === 'sqlite'
        ? ['node_id' => $clearNode ? null : $this->caller->id]
        : ['driver' => 'sqlite', 'path' => '/var/lib/app/database.sqlite'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'database.node_mismatch');

    expect($connection->refresh()->getRawOriginal())->toBe($original)
        ->and(stored_env($this->instance))->toBe($environment)
        ->and(DatabaseConnectionTarget::query()->get()->toArray())->toBe($targets);
})->with([
    'SQLite Node change' => ['sqlite', false],
    'SQLite Node removal' => ['sqlite', true],
    'driver change to SQLite' => ['mysql', false],
]);

it('allows a SQLite update that keeps every target on the registered Node', function (string $driver): void {
    $connection = DatabaseConnection::query()->create([
        'slug' => 'same-node', 'driver' => $driver, 'node_id' => $this->instance->node_id,
        ...($driver === 'sqlite'
            ? ['path' => '/var/lib/app/database.sqlite']
            : ['host' => 'db.example.test', 'database' => 'app', 'username' => 'app', 'password' => DATABASE_ATTACHMENT_SECRET]),
    ]);
    $connection->targets()->create(['instance_id' => $this->instance->id, 'prefix' => 'DB']);

    $this->patchJson('/api/v1/database-connections/same-node', [
        'driver' => 'sqlite', 'node_id' => $this->instance->node_id, 'path' => '/var/lib/app/updated.sqlite',
    ])->assertOk()->assertJsonPath('data.path', '/var/lib/app/updated.sqlite');
})->with(['sqlite', 'mysql']);

it('refuses an unsupported attach key and an invalid prefix', function (): void {
    $this->postJson('/api/v1/database-connections', [
        'slug' => 'app',
        'driver' => 'sqlite',
        'path' => '/tmp/app.sqlite',
    ])->assertCreated();

    $this->putJson("/api/v1/instances/{$this->instance->id}/database-connections/app", [
        'workspace_id' => 1,
    ])->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');

    $this->putJson("/api/v1/instances/{$this->instance->id}/database-connections/app", [
        'prefix' => 'db',
    ])->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');
});

/**
 * @return array{0: Node, 1: Instance, 2: Route}
 */
function database_attachment_fixture(): array
{
    $caller = Node::query()->create([
        'name' => 'gateway-peer',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.200',
        'wireguard_ip' => '10.44.0.200',
        'user' => 'orbit',
    ]);
    $caller->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $node = Node::query()->create([
        'name' => 'environment-owner',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.201',
        'wireguard_ip' => '10.44.0.201',
        'user' => 'orbit',
    ]);
    orbit_test_set_app_placement_role($node, false);
    $project = Project::query()->create([
        'name' => 'Environment API',
        'slug' => 'environment-api',
        'repository_url' => 'https://example.test/environment-api.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'default',
        'environment' => 'development',
        'checkout_path' => '/srv/orbit/environment-api/default',
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
    ]);
    $route = Route::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'domain' => 'environment-api.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
    $instance->update(['status' => 'active']);

    return [$caller, $instance->fresh(['node']), $route];
}

/** @return array<string, string> */
function stored_env(Instance $instance): array
{
    return InstanceEnvironmentValue::query()
        ->where('instance_id', $instance->id)
        ->orderBy('env_key')
        ->get()
        ->mapWithKeys(static fn (InstanceEnvironmentValue $row): array => [$row->env_key => $row->env_value])
        ->all();
}

final class DatabaseAttachmentAccess implements InstanceEnvironmentReader, InstanceEnvironmentWriter, InstanceOperationPreflight
{
    public function assertEnvironmentReadable(InstanceEnvironmentContext $context): void
    {
        throw new RuntimeException('Attachment contacted the workload Node.');
    }

    public function assertEnvironmentWritable(
        InstanceEnvironmentContext $context,
        int $requiredCapacityBytes,
    ): void {
        throw new RuntimeException('Attachment contacted the workload Node.');
    }

    public function read(InstanceEnvironmentContext $context): string
    {
        throw new RuntimeException('Attachment contacted the workload Node.');
    }

    public function write(
        InstanceEnvironmentContext $context,
        #[SensitiveParameter]
        string $contents,
    ): InstanceEnvironmentWriteResult {
        throw new RuntimeException('Attachment contacted the workload Node.');
    }
}

it('persists explicit LAN selection between active LAN Nodes and keeps WireGuard as the default', function (): void {
    $node = $this->instance->node;
    $node->update(['lan_ip' => '10.0.0.4']);
    $server = DatabaseServer::query()->create(['slug' => 'managed-pg', 'driver' => 'pgsql', 'node_id' => $this->caller->id, 'tag' => '18', 'port' => 5434, 'root_password' => 'admin-fixture', 'status' => LifecycleStatus::Active]);
    $this->caller->update(['lan_ip' => '10.0.0.6']);
    $process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $this->caller->id, 'name' => 'managed-pg', 'runtime' => 'docker', 'runtime_config' => ['ports' => ['10.0.0.6:5434:5432']], 'status' => 'active', 'desired_state' => 'running']);
    $server->update(['private_endpoints_managed' => true, 'process_id' => $process->id]);
    $connection = DatabaseConnection::query()->create(['slug' => 'catalog', 'driver' => 'pgsql', 'database_server_id' => $server->id, 'node_id' => $server->node_id, 'host' => $this->caller->wireguard_ip, 'port' => 5434, 'database' => 'catalog', 'username' => 'catalog', 'password' => DATABASE_ATTACHMENT_SECRET]);
    $url = "/api/v1/instances/{$this->instance->id}/database-connections/catalog";
    $this->putJson($url, ['network' => 'lan'])->assertOk()->assertJsonPath('data.host', '10.0.0.6');
    expect(DatabaseConnectionTarget::query()->sole()->network)->toBe('lan')->and(stored_env($this->instance)['DB_HOST'])->toBe('10.0.0.6');
    $this->putJson($url, ['network' => 'wireguard', 'replace' => true])->assertOk()->assertJsonPath('data.host', $connection->host);
    expect(DatabaseConnectionTarget::query()->sole()->network)->toBe('wireguard');
});

it('refuses LAN selection without a server Node before writing environment values', function (): void {
    DatabaseConnection::query()->create(['slug' => 'external', 'driver' => 'pgsql', 'host' => 'db.example.test', 'port' => 5432, 'database' => 'app', 'username' => 'app', 'password' => DATABASE_ATTACHMENT_SECRET]);
    $this->putJson("/api/v1/instances/{$this->instance->id}/database-connections/external", ['network' => 'lan'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'database.lan_unavailable');
    expect(DatabaseConnectionTarget::query()->count())->toBe(0)->and(stored_env($this->instance))->toBe([]);
});

it('keeps external LAN aliases unchanged in attachments and Doctor', function (): void {
    $this->caller->update(['lan_ip' => '10.0.0.6']);
    DatabaseServer::query()->create(['slug' => 'managed-pg', 'driver' => 'pgsql', 'node_id' => $this->caller->id, 'tag' => '18', 'port' => 5434, 'root_password' => 'fixture', 'status' => 'active', 'private_endpoints_managed' => true]);
    DatabaseConnection::query()->create(['slug' => 'lan-alias', 'driver' => 'pgsql', 'node_id' => $this->caller->id, 'host' => '10.0.0.6', 'port' => 5434, 'database' => 'catalog', 'username' => 'catalog', 'password' => DATABASE_ATTACHMENT_SECRET]);
    $this->putJson("/api/v1/instances/{$this->instance->id}/database-connections/lan-alias", ['prefix' => 'DB'])
        ->assertOk()->assertJsonPath('data.host', '10.0.0.6');
    expect(app(DatabaseConnectionDoctorInspection::class)->attachment(DatabaseConnectionTarget::query()->sole()))->toBe([]);
});

it('refuses LAN attachments when either Node is inactive or lacks an IPv4 LAN address', function (string $side, string $field, ?string $value): void {
    $this->caller->update(['lan_ip' => '10.0.0.6']);
    $this->instance->node->update(['lan_ip' => '10.0.0.4']);
    ($side === 'server' ? $this->caller : $this->instance->node)->update([$field => $value]);
    $server = DatabaseServer::query()->create(['slug' => 'managed-pg', 'driver' => 'pgsql', 'node_id' => $this->caller->id, 'tag' => '18', 'port' => 5434, 'root_password' => 'fixture', 'status' => 'active']);
    DatabaseConnection::query()->create(['slug' => 'catalog', 'driver' => 'pgsql', 'database_server_id' => $server->id, 'host' => '10.44.0.3', 'port' => 5434, 'database' => 'catalog', 'username' => 'catalog', 'password' => DATABASE_ATTACHMENT_SECRET]);
    expect(fn () => app(DatabaseConnectionEnvProjection::class)->project(DatabaseConnection::query()->sole(), 'DB', 'lan', $this->instance->node))
        ->toThrow(ResourceOperationException::class);
    expect(DatabaseConnectionTarget::query()->count())->toBe(0);
})->with(['server', 'consumer'])->with([['status', 'failed'], ['lan_ip', null], ['lan_ip', '::1']]);

it('refuses to change stored connection keys without explicit replace and preserves the mapping', function (bool $mapped, ?bool $replace): void {
    $old = DatabaseConnection::query()->create(['slug' => 'current', 'driver' => 'mysql', 'host' => 'old.example.test', 'port' => 3306, 'database' => 'current', 'username' => 'current', 'password' => DATABASE_ATTACHMENT_SECRET]);
    DatabaseConnection::query()->create(['slug' => 'empty', 'driver' => 'pgsql', 'host' => 'next.example.test', 'port' => 5432, 'database' => 'empty', 'username' => 'next', 'password' => 'next-secret']);
    $values = ['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'current', 'DB_HOST' => 'old.example.test', 'DB_PASSWORD' => DATABASE_ATTACHMENT_SECRET, 'DB_PORT' => '3306', 'DB_USERNAME' => 'current'];
    foreach ($values as $key => $value) {
        InstanceEnvironmentValue::query()->create(['instance_id' => $this->instance->id, 'env_key' => $key, 'env_value' => $value]);
    }
    if ($mapped) {
        $old->targets()->create(['instance_id' => $this->instance->id, 'prefix' => 'DB']);
    }
    $body = $replace === null ? ['prefix' => 'DB'] : ['replace' => $replace];

    $response = $this->putJson("/api/v1/instances/{$this->instance->id}/database-connections/empty", $body)
        ->assertConflict()->assertJsonPath('error.code', 'database.environment_conflict');

    expect(stored_env($this->instance))->toBe($values);
    expect(DatabaseConnectionTarget::query()->pluck('database_connection_id')->all())->toBe($mapped ? [$old->id] : []);
    expect($response->getContent())->not->toContain(DATABASE_ATTACHMENT_SECRET, 'next-secret');
})->with([true, false])->with([null, false]);

it('requires explicit replace before clearing keys that the new driver omits', function (): void {
    DatabaseConnection::query()->create(['slug' => 'local', 'driver' => 'sqlite', 'node_id' => $this->instance->node_id, 'path' => '/tmp/app.sqlite']);
    foreach (['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => '/tmp/app.sqlite', 'DB_HOST' => 'keep.example.test'] as $key => $value) {
        InstanceEnvironmentValue::query()->create(['instance_id' => $this->instance->id, 'env_key' => $key, 'env_value' => $value]);
    }

    $this->putJson("/api/v1/instances/{$this->instance->id}/database-connections/local", ['prefix' => 'DB'])
        ->assertConflict()->assertJsonPath('error.code', 'database.environment_conflict');
    expect(stored_env($this->instance)['DB_HOST'])->toBe('keep.example.test');
    expect(DatabaseConnectionTarget::query()->count())->toBe(0);
});

it('rejects a nonboolean replacement choice before changing stored state', function (): void {
    DatabaseConnection::query()->create(['slug' => 'app', 'driver' => 'sqlite', 'node_id' => $this->instance->node_id, 'path' => '/tmp/app.sqlite']);

    $this->putJson("/api/v1/instances/{$this->instance->id}/database-connections/app", ['replace' => 'true'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    expect(stored_env($this->instance))->toBe([]);
    expect(DatabaseConnectionTarget::query()->count())->toBe(0);
});
