<?php

declare(strict_types=1);

use App\Domain\DatabaseServers\DatabaseServerAdmin;
use App\Domain\DatabaseServers\DatabaseServerCreateProjection;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\DatabaseConnection;
use App\Models\DatabaseExecutionIntent;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Process;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeDatabaseServerAdmin;
use Tests\Support\FakeRoutePublicationSocket;

function databaseAdmissionNode($test): Node
{
    $node = Node::query()->create(['name' => 'dev-db', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'amd64',
        'public_ssh_host' => '192.0.2.10', 'wireguard_ip' => '10.44.0.1', 'user' => 'orbit']);
    NodeRole::query()->create(['node_id' => $node->id, 'role' => 'gateway', 'status' => 'active']);
    $node->accessibleNodes()->attach($node);
    orbit_test_set_app_placement_role($node, false);
    $test->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);

    return $node;
}

function databaseAdmissionServer(Node $node, string $driver = 'mysql'): DatabaseServer
{
    $process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $node->id, 'name' => 'dev-sql',
        'runtime' => 'docker', 'runtime_config' => ['image' => 'mysql:8.4', 'command' => ['mysqld'], 'environment' => [], 'ports' => [], 'volumes' => []],
        'restart_policy' => 'unless-stopped', 'desired_state' => 'running', 'status' => 'active']);

    return DatabaseServer::query()->create(['slug' => 'dev-sql', 'driver' => $driver, 'node_id' => $node->id, 'process_id' => $process->id,
        'tag' => $driver === 'pgsql' ? '18' : '8.4', 'port' => 5434, 'root_password' => 'test-admin-password', 'status' => 'active']);
}

beforeEach(function (): void {
    config(['swamp.run_token' => 'fake.token']);
    app()->instance(WebSocketClient::class, new FakeRoutePublicationSocket);
    if (! OperationKinds::supports('children') || ! OperationKinds::supports('receipts')) {
        $this->markTestSkipped('Database execution awaits Kit B and I enforcement.');
    }
});

it('accepts server creation without running PHP infrastructure', function (): void {
    Queue::fake();
    $node = databaseAdmissionNode($this);

    $response = $this->withHeader('Idempotency-Key', 'database-server-proof')
        ->postJson('/api/v1/database-servers', ['slug' => 'throwaway', 'node_id' => $node->id, 'driver' => 'pgsql', 'port' => 15432])
        ->assertAccepted()->assertJsonPath('data.kind', 'database_server.create')->assertJsonPath('data.status', 'queued');

    expect(Operation::query()->sole()->node_id)->toBe($node->id);
    expect($response->getContent())->not->toContain('root_password', 'environment', 'PGPASSWORD');
    $intent = DatabaseExecutionIntent::query()->sole();
    expect($intent->payload['commands'])->toHaveKeys(['database-apply']);
    expect($intent->payload['commands']['database-apply']['name'])->toBe('database.server-create');
    expect(DB::table('database_execution_intents')->where('id', $intent->id)->value('payload'))->not->toContain(DatabaseServer::query()->sole()->root_password);
    Queue::assertNothingPushed();
});

it('records PostgreSQL intent with a protected owner and locale', function (): void {
    $node = databaseAdmissionNode($this);
    $server = databaseAdmissionServer($node, 'pgsql');
    app()->instance(DatabaseServerAdmin::class, new FakeDatabaseServerAdmin);

    $response = $this->postJson('/api/v1/database-connections', ['slug' => 'throwaway', 'server' => $server->slug, 'locale' => 'C.UTF-8'])
        ->assertAccepted()->assertJsonPath('data.kind', 'database.create');

    $connection = DatabaseConnection::query()->sole();
    expect($connection->database_server_id)->toBe($server->id);
    expect($response->getContent())->not->toContain($connection->password, 'SCRAM-SHA-256', $server->root_password);
    expect(DatabaseExecutionIntent::query()->sole()->payload['commands']['database-apply']['input']['sql'])
        ->toContain('LOCALE_PROVIDER builtin', 'NOCREATEDB', 'OWNER', 'REVOKE ALL ON DATABASE');
});

it('admits reader grants without executing SQL or exposing passwords', function (): void {
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $sent): array => [['type' => 'event', 'id' => $sent['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-database-user-create', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'fake.token']);
    $node = databaseAdmissionNode($this);
    $server = databaseAdmissionServer($node);
    DatabaseConnection::query()->create(['slug' => 'throwaway', 'driver' => 'mysql', 'node_id' => $node->id,
        'database_server_id' => $server->id, 'host' => '10.44.0.1', 'database' => 'throwaway', 'username' => 'owner', 'password' => 'test-owner-password']);
    $admin = new FakeDatabaseServerAdmin;
    app()->instance(DatabaseServerAdmin::class, $admin);

    $response = $this->postJson('/api/v1/database-connections/throwaway/users', ['username' => 'reader', 'password' => 'test-reader-password', 'read_only' => true])
        ->assertAccepted()->assertJsonPath('data.kind', 'database_user.create');

    expect($admin->statements)->toBe([]);
    expect($response->getContent())->not->toContain('test-reader-password', $server->root_password);
    expect(DatabaseExecutionIntent::query()->sole()->payload['commands']['database-apply']['input']['sql'])
        ->toContain("GRANT SELECT ON `throwaway`.* TO 'reader'@'%';");
});

it('refuses removing a server with connections with 409', function (): void {
    $node = databaseAdmissionNode($this);
    $server = databaseAdmissionServer($node);
    DatabaseConnection::query()->create(['slug' => 'throwaway', 'driver' => 'mysql', 'database_server_id' => $server->id, 'host' => '10.44.0.1']);

    $this->deleteJson('/api/v1/database-servers/dev-sql')->assertConflict()->assertJsonPath('error.code', 'database.server_in_use');

    expect(Operation::query()->count())->toBe(0);
    $this->assertModelExists($server);
});

it('keeps registration and reads in Orbit', function (): void {
    databaseAdmissionNode($this);

    $this->postJson('/api/v1/database-connections', ['slug' => 'external', 'driver' => 'mysql', 'host' => 'db.example.test', 'database' => 'external', 'username' => 'external', 'password' => 'test-external-password'])->assertCreated();
    $this->getJson('/api/v1/database-connections/external')->assertOk()->assertJsonPath('data.slug', 'external');

    expect(Operation::query()->count())->toBe(0);
});

it('admits a protected SQLite write without a Gateway execution path', function (): void {
    $node = databaseAdmissionNode($this);
    DatabaseConnection::query()->create(['slug' => 'throwaway', 'driver' => 'sqlite', 'node_id' => $node->id, 'path' => '/tmp/throwaway.sqlite']);

    config(['swamp.run_token' => 'fake.token']);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $sent): array => [['type' => 'event', 'id' => $sent['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-database-write', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]];
    app()->instance(WebSocketClient::class, $socket);
    $this->postJson('/api/v1/database-connections/throwaway/query', ['sql' => 'CREATE TABLE sample (id integer)', 'write' => true])
        ->assertAccepted()->assertJsonPath('data.kind', 'database.write');

    $command = DatabaseExecutionIntent::query()->sole()->payload['commands']['database-apply'];
    expect($command['name'])->toBe('database.write-sqlite');
    expect(Operation::query()->sole()->input['sql'])->toBe(['$secret' => 'database.intent', 'id' => DatabaseExecutionIntent::query()->sole()->id]);
});

it('admits removal of a failed server with no Process through a proven Gateway read', function (): void {
    $node = databaseAdmissionNode($this);
    $server = DatabaseServer::query()->create(['slug' => 'failed-server', 'driver' => 'mysql', 'node_id' => $node->id,
        'tag' => '8.4', 'port' => 3306, 'root_password' => 'synthetic-admin-password', 'status' => 'failed']);

    $this->deleteJson('/api/v1/database-servers/failed-server')->assertAccepted()->assertJsonPath('data.kind', 'database_server.destroy');
    $intent = DatabaseExecutionIntent::query()->sole();
    expect($intent->payload['commands']['database-apply'])->toMatchArray(['transport' => 'gateway', 'name' => 'database.server-absent']);
    $this->assertModelExists($server);
});

it('retries failed database creation with its reserved credentials and immutable intent', function (): void {
    $node = databaseAdmissionNode($this);
    $server = databaseAdmissionServer($node);
    app()->instance(DatabaseServerAdmin::class, new FakeDatabaseServerAdmin);
    $request = ['slug' => 'throwaway', 'server' => $server->slug];
    $this->postJson('/api/v1/database-connections', $request)->assertAccepted();
    $first = Operation::query()->sole();
    $connection = DatabaseConnection::query()->sole();
    $password = $connection->password;
    $first->update(['status' => 'failed', 'lock_key' => null, 'error_code' => 'database.server_command_failed', 'finished_at' => now()]);

    $this->postJson('/api/v1/database-connections', $request)->assertAccepted();

    expect(DatabaseConnection::query()->sole()->password)->toBe($password);
    expect(DatabaseExecutionIntent::query()->count())->toBe(1);
    expect(Operation::query()->latest('id')->first()->input['intent_id'])->toBe($first->input['intent_id']);
});

it('refuses reusing failed create intent after its recorded credentials change', function (): void {
    $node = databaseAdmissionNode($this);
    $server = databaseAdmissionServer($node);
    app()->instance(DatabaseServerAdmin::class, new FakeDatabaseServerAdmin);
    $request = ['slug' => 'throwaway', 'server' => $server->slug];
    $this->postJson('/api/v1/database-connections', $request)->assertAccepted();
    Operation::query()->sole()->update(['status' => 'failed', 'lock_key' => null, 'finished_at' => now()]);
    DatabaseConnection::query()->sole()->update(['password' => 'changed-synthetic-password']);
    $this->postJson('/api/v1/database-connections', $request)->assertConflict()->assertJsonPath('error.code', 'database.slug_conflict');
    expect(Operation::query()->count())->toBe(1);
});

it('keeps server initializer material out of safe direct input', function (string $driver): void {
    $node = databaseAdmissionNode($this);
    $tag = match ($driver) {
        'mysql' => '8.0', 'pgsql' => '18', default => '8.1'
    };
    $this->postJson('/api/v1/database-servers', ['slug' => 'throwaway', 'node_id' => $node->id, 'driver' => $driver, 'tag' => $tag, 'port' => 15432])->assertAccepted();
    $operation = Operation::query()->sole();
    $input = app(DatabaseServerCreateProjection::class)->input($operation);
    expect($input)->not->toHaveKeys(['password', 'environment', 'acl', 'payload']);
    expect($input['container_input'])->not->toHaveKey('environment');
    expect($operation->state['database_server_create_writer'])->toBe('direct');
})->with(['mysql', 'pgsql', 'redis']);
