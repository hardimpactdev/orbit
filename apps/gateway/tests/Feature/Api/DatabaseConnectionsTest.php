<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\Activity;
use App\Models\DatabaseConnection;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

const DATABASE_CONNECTION_SECRET = 'db-registry-secret-9f3a';

beforeEach(function (): void {
    $node = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.10',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'tld' => 'orbit',
        'wireguard_ip' => '10.44.0.1',
    ]);
    $this->node = $this->markAsGateway($node);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
});

it('selects a production home only on the registered Node and at a path boundary', function (string $path, bool $sameNode, bool $sudo): void {
    $worker = Node::query()->create([
        'name' => 'production-sqlite', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.82', 'wireguard_ip' => '10.44.0.82', 'user' => 'orbit',
    ]);
    $worker->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $project = Project::query()->create([
        'name' => 'SQLite production', 'slug' => 'sqlite-production',
        'repository_url' => 'https://example.test/sqlite.git', 'default_branch' => 'main',
    ]);
    Instance::query()->create([
        'project_id' => $project->id, 'node_id' => $worker->id, 'name' => 'production',
        'checkout_path' => '/home/orbit-app-82/current', 'production_user' => 'orbit-app-82',
        'production_home' => '/home/orbit-app-82',
    ]);
    $this->postJson('/api/v1/database-connections', [
        'slug' => 'production-file', 'driver' => 'sqlite',
        'node_id' => $sameNode ? $worker->id : $this->node->id, 'path' => $path,
    ])->assertCreated();
    $ssh = Mockery::mock(SshExecutor::class);
    $ssh->shouldReceive('execute')->once()->withArgs(function (SshConnection $connection, RemoteCommand $command) use ($sudo): bool {
        expect($connection->user)->toBe('orbit')
            ->and($command->arguments)->toBe($sudo
                ? ['sudo', '-n', '-u', 'orbit-app-82', '-H', 'orbit', 'internal:database-local']
                : ['orbit', 'internal:database-local']);

        return true;
    })->andReturn(new CommandResult(0, '{"columns":["name"],"rows":[{"name":"users"}],"row_count":1,"truncated":false}', '', 1, false));
    app()->instance(SshExecutor::class, $ssh);

    $this->getJson('/api/v1/database-connections/production-file/tables')
        ->assertOk()->assertJsonPath('data.tables', ['users']);
})->with([
    'production home' => ['/home/orbit-app-82/shared/database.sqlite', true, true],
    'same path on another Node' => ['/home/orbit-app-82/shared/database.sqlite', false, false],
    'similar home name' => ['/home/orbit-app-82-other/database.sqlite', true, false],
    'path traversal' => ['/home/orbit-app-82/../other/database.sqlite', true, false],
]);

it('returns a distinct safe API error when SQLite inspection cannot read the file', function (): void {
    $this->postJson('/api/v1/database-connections', [
        'slug' => 'unreadable', 'driver' => 'sqlite', 'node_id' => $this->node->id,
        'path' => '/private/database.sqlite',
    ])->assertCreated();
    $ssh = Mockery::mock(SshExecutor::class);
    $ssh->shouldReceive('execute')->andReturn(new CommandResult(1,
        '{"error":{"code":"database.sqlite_unreadable","message":"remote-private-secret"}}', '', 1, false));
    app()->instance(SshExecutor::class, $ssh);

    $response = $this->getJson('/api/v1/database-connections/unreadable/tables')
        ->assertUnprocessable()->assertJsonPath('error.code', 'database.sqlite_unreadable');
    expect($response->getContent())->not->toContain('remote-private-secret');
});

it('round-trips mysql connection CRUD, encrypts the password, and redacts it from activity', function (): void {
    $create = $this->postJson('/api/v1/database-connections', [
        'slug' => 'app',
        'driver' => 'mysql',
        'host' => 'db.example.test',
        'database' => 'app',
        'username' => 'app',
        'password' => DATABASE_CONNECTION_SECRET,
    ]);

    $create
        ->assertCreated()
        ->assertJsonPath('data.slug', 'app')
        ->assertJsonPath('data.driver', 'mysql')
        ->assertJsonPath('data.host', 'db.example.test')
        ->assertJsonPath('data.port', 3306)
        ->assertJsonPath('data.database', 'app')
        ->assertJsonPath('data.username', 'app')
        ->assertJsonPath('data.path', null)
        ->assertJsonPath('data.node_id', null)
        ->assertJsonPath('data.has_password', true)
        ->assertJsonMissingPath('data.password');

    expect($create->getContent())->not->toContain(DATABASE_CONNECTION_SECRET);
    expect(array_keys($create->json('data')))->toBe([
        'id',
        'slug',
        'driver',
        'node_id',
        'host',
        'port',
        'database',
        'path',
        'username',
        'has_password',
        'server',
        'owner_instance_id',
        'test_database',
    ]);

    $raw = DB::table('database_connections')->where('slug', 'app')->sole();
    $connection = DatabaseConnection::query()->where('slug', 'app')->sole();

    expect($raw->password)
        ->not->toBe(DATABASE_CONNECTION_SECRET)
        ->and($connection->password)
        ->toBe(DATABASE_CONNECTION_SECRET)
        ->and($connection->toArray())
        ->not->toHaveKey('password')
        ->and(print_r($connection, true))
        ->not->toContain(DATABASE_CONNECTION_SECRET);

    $requestId = $create->json('meta.request_id');
    $activity = Activity::query()->where('request_id', $requestId)->sole();
    $encoded = json_encode($activity->properties?->toArray() ?? [], JSON_THROW_ON_ERROR);

    expect($activity->command)
        ->toBe('database:create')
        ->and($encoded)
        ->not->toContain(DATABASE_CONNECTION_SECRET)
        ->and($activity->properties?->get('input'))
        ->toMatchArray(['password' => '[REDACTED]']);

    $this->getJson('/api/v1/database-connections/app')
        ->assertOk()
        ->assertJsonPath('data.slug', 'app')
        ->assertJsonPath('data.has_password', true)
        ->assertJsonMissingPath('data.password');

    $update = $this->patchJson('/api/v1/database-connections/app', [
        'host' => 'db-internal.example.test',
    ]);

    $update
        ->assertOk()
        ->assertJsonPath('data.host', 'db-internal.example.test')
        ->assertJsonPath('data.has_password', true);

    expect(DatabaseConnection::query()->where('slug', 'app')->sole()->password)
        ->toBe(DATABASE_CONNECTION_SECRET);

    $remove = $this->deleteJson('/api/v1/database-connections/app');
    $remove
        ->assertOk()
        ->assertJsonPath('data.slug', 'app')
        ->assertJsonMissingPath('data.password');

    expect($remove->getContent())->not->toContain(DATABASE_CONNECTION_SECRET);
    expect(Activity::query()->where('request_id', $remove->json('meta.request_id'))->sole()->command)
        ->toBe('database:destroy');
    expect(DatabaseConnection::query()->where('slug', 'app')->exists())->toBeFalse();

    $this->getJson('/api/v1/database-connections/app')->assertNotFound()->assertJsonPath('error.code', 'http.404');
});

it('registers a remote sqlite path without a database role on the optional node', function (): void {
    $worker = Node::query()->create([
        'name' => 'worker',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.11',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'tld' => null,
        'wireguard_ip' => '10.44.0.8',
    ]);

    expect(
        NodeRole::query()->where('node_id', $worker->id)->where('role', RoleName::Database)->exists(),
    )->toBeFalse();

    $this->postJson('/api/v1/database-connections', [
        'slug' => 'local',
        'driver' => 'sqlite',
        'node_id' => $worker->id,
        'path' => '/var/lib/app/database.sqlite',
    ])
        ->assertCreated()
        ->assertJsonPath('data.slug', 'local')
        ->assertJsonPath('data.driver', 'sqlite')
        ->assertJsonPath('data.node_id', $worker->id)
        ->assertJsonPath('data.path', '/var/lib/app/database.sqlite')
        ->assertJsonPath('data.host', null)
        ->assertJsonPath('data.port', null)
        ->assertJsonPath('data.database', null)
        ->assertJsonPath('data.has_password', false);
});

it('lists connections ordered by slug and defaults pgsql ports', function (): void {
    $this->postJson('/api/v1/database-connections', [
        'slug' => 'zeta',
        'driver' => 'pgsql',
        'host' => 'pg.example.test',
        'database' => 'analytics',
        'username' => 'analytics',
        'password' => 'pg-secret',
    ])->assertCreated()->assertJsonPath('data.port', 5432);

    $this->postJson('/api/v1/database-connections', [
        'slug' => 'alpha',
        'driver' => 'sqlite',
        'path' => '/tmp/alpha.sqlite',
    ])->assertCreated();

    $this->getJson('/api/v1/database-connections')
        ->assertOk()
        ->assertJsonPath('data.0.slug', 'alpha')
        ->assertJsonPath('data.1.slug', 'zeta')
        ->assertJsonMissingPath('data.0.password')
        ->assertJsonMissingPath('data.1.password');
});

it('refuses a duplicate slug and incompatible driver fields', function (): void {
    $this->postJson('/api/v1/database-connections', [
        'slug' => 'app',
        'driver' => 'mysql',
        'host' => 'db.example.test',
        'database' => 'app',
        'username' => 'app',
        'password' => 'secret',
    ])->assertCreated();

    $this->postJson('/api/v1/database-connections', [
        'slug' => 'app',
        'driver' => 'mysql',
        'host' => 'other.example.test',
        'database' => 'app',
        'username' => 'app',
        'password' => 'secret',
    ])->assertStatus(409)->assertJsonPath('error.code', 'database.slug_conflict');

    $this->postJson('/api/v1/database-connections', [
        'slug' => 'broken',
        'driver' => 'sqlite',
        'host' => 'db.example.test',
        'path' => '/tmp/app.sqlite',
    ])->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');

    $this->postJson('/api/v1/database-connections', [
        'slug' => 'broken-mysql',
        'driver' => 'mysql',
        'path' => '/tmp/app.sqlite',
    ])->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');

    $this->postJson('/api/v1/database-connections', [
        'slug' => 'unknown-key',
        'driver' => 'sqlite',
        'path' => '/tmp/app.sqlite',
        'attach' => true,
    ])->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');
});

it('updates a password independently and clears an optional node association', function (): void {
    $this->postJson('/api/v1/database-connections', [
        'slug' => 'app',
        'driver' => 'mysql',
        'node_id' => $this->node->id,
        'host' => 'db.example.test',
        'database' => 'app',
        'username' => 'app',
        'password' => DATABASE_CONNECTION_SECRET,
    ])->assertCreated()->assertJsonPath('data.node_id', $this->node->id);

    $nextSecret = 'db-registry-rotated-2c81';
    $update = $this->patchJson('/api/v1/database-connections/app', [
        'node_id' => null,
        'password' => $nextSecret,
    ]);

    $update
        ->assertOk()
        ->assertJsonPath('data.node_id', null)
        ->assertJsonPath('data.has_password', true);

    expect($update->getContent())->not->toContain($nextSecret, DATABASE_CONNECTION_SECRET);
    expect(DatabaseConnection::query()->where('slug', 'app')->sole()->password)->toBe($nextSecret);

    $this->patchJson('/api/v1/database-connections/app', [])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation.failed');
});

it('registers a redis connection with only a host required and defaults its port', function (): void {
    $create = $this->postJson('/api/v1/database-connections', [
        'slug' => 'cache',
        'driver' => 'redis',
        'host' => 'redis.example.test',
    ]);

    $create
        ->assertCreated()
        ->assertJsonPath('data.slug', 'cache')
        ->assertJsonPath('data.driver', 'redis')
        ->assertJsonPath('data.host', 'redis.example.test')
        ->assertJsonPath('data.port', 6379)
        ->assertJsonPath('data.database', null)
        ->assertJsonPath('data.username', null)
        ->assertJsonPath('data.has_password', false);

    $this->postJson('/api/v1/database-connections', [
        'slug' => 'cache-with-index',
        'driver' => 'redis',
        'host' => 'redis.example.test',
        'port' => 6380,
        'database' => '2',
        'username' => 'app',
        'password' => 'redis-secret',
    ])
        ->assertCreated()
        ->assertJsonPath('data.port', 6380)
        ->assertJsonPath('data.database', '2')
        ->assertJsonPath('data.username', 'app')
        ->assertJsonPath('data.has_password', true);

    $this->postJson('/api/v1/database-connections', [
        'slug' => 'broken-redis',
        'driver' => 'redis',
        'path' => '/tmp/redis.sqlite',
    ])->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');

    $this->postJson('/api/v1/database-connections', [
        'slug' => 'no-host-redis',
        'driver' => 'redis',
    ])->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');
});
