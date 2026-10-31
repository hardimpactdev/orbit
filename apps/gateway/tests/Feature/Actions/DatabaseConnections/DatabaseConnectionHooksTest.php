<?php

declare(strict_types=1);

use App\Actions\DatabaseConnections\DestroyDatabaseConnectionAction;
use App\Actions\DatabaseConnections\UpdateDatabaseConnectionAction;
use App\Data\DatabaseConnections\UpdateDatabaseConnectionData;
use App\Domain\Analytics\AnalyticsDatabaseConnectionGuard;
use App\Domain\Analytics\AnalyticsRoleSettings;
use App\Domain\Analytics\AnalyticsRoleSettingsRepository;
use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\DatabaseConnections\DatabaseConnectionRemovalGuard;
use App\Domain\DatabaseConnections\DatabaseConnectionUpdateHook;
use App\Domain\DatabaseServers\DatabaseConnectionUpdateProjection;
use App\Domain\DatabaseServers\PostgresConnectionUpdateHook;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use App\Models\Node;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\FakeRoutePublicationSocket;

final class TestDatabaseConnectionUpdateHook implements DatabaseConnectionUpdateHook
{
    /** @var list<array{string, ?string, ?string, int}> */
    public array $calls = [];

    public bool $refuse = false;

    public bool $failConvergence = false;

    public function validate(DatabaseConnection $proposed): void
    {
        $this->calls[] = ['validate', $proposed->host, $proposed->fresh()?->host, DB::transactionLevel()];
        if ($this->refuse) {
            throw new ResourceOperationException('database_connection.test_refused', 'Connection is in use.', 409);
        }
    }

    public function afterCommit(DatabaseConnection $connection): void
    {
        $this->calls[] = ['afterCommit', $connection->host, $connection->fresh()?->host, DB::transactionLevel()];
        if ($this->failConvergence) {
            throw new RuntimeException('Collector reconvergence failed.');
        }
    }
}

function connection_hook_update(): UpdateDatabaseConnectionData
{
    return new UpdateDatabaseConnectionData(
        driverProvided: false, driver: null, nodeIdProvided: false, nodeId: null,
        hostProvided: true, host: 'new.example.test', portProvided: false, port: null,
        databaseProvided: false, database: null, pathProvided: false, path: null,
        usernameProvided: false, username: null, passwordProvided: false, password: null,
    );
}

function connection_hook(): TestDatabaseConnectionUpdateHook
{
    $hook = new TestDatabaseConnectionUpdateHook;
    app()->instance(TestDatabaseConnectionUpdateHook::class, $hook);
    app()->tag(TestDatabaseConnectionUpdateHook::class, DatabaseConnectionUpdateHook::class);

    return $hook;
}

function connection_hook_worker(): void
{
    $node = Node::query()->create(['name' => 'update-worker', 'user' => 'orbit', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.8', 'wireguard_ip' => '10.44.0.8']);
    test()->markAsGateway($node);
    config(['swamp.run_token' => 'fake.token']);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $sent): array => [['type' => 'event', 'id' => $sent['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-database-connection-update', 'runId' => 'e1589ed9-996b-4c9c-aa75-7749ef519210']]];
    app()->instance(WebSocketClient::class, $socket);
}

it('validates the proposal before admission and writes only after the native result', function (): void {
    connection_hook_worker();
    $connection = DatabaseConnection::query()->create(['slug' => 'cache', 'driver' => 'redis', 'host' => 'old.example.test', 'port' => 6379]);
    $hook = connection_hook();
    $level = DB::transactionLevel();
    $operation = app(UpdateDatabaseConnectionAction::class)->execute($connection, connection_hook_update());
    expect($hook->calls)->toBe([['validate', 'new.example.test', 'old.example.test', $level + 1]])
        ->and($connection->fresh()->host)->toBe('old.example.test');

    app(DatabaseConnectionUpdateProjection::class)->apply($operation, [
        'run_id' => $operation->state['swamp_run_id'], 'status' => 'succeeded',
        'started_at' => '2026-10-31T09:00:00.000Z', 'finished_at' => '2026-10-31T09:00:01.000Z', 'result' => ['backend' => 'active'],
    ]);
    expect($connection->fresh()->host)->toBe('new.example.test')
        ->and(array_column($hook->calls, 0))->toBe(['validate']);
});

it('leaves the connection and events unchanged when a tagged hook refuses', function (): void {
    $connection = DatabaseConnection::query()->create(['slug' => 'cache', 'driver' => 'redis', 'host' => 'old.example.test', 'port' => 6379]);
    $hook = connection_hook();
    $hook->refuse = true;
    Event::fake([RecordBroadcast::class]);

    expect(fn () => app(UpdateDatabaseConnectionAction::class)->execute($connection, connection_hook_update()))
        ->toThrow(ResourceOperationException::class, 'Connection is in use.');
    expect($connection->refresh()->host)->toBe('old.example.test')->and($hook->calls)->toHaveCount(1);
    Event::assertNotDispatched(RecordBroadcast::class);
});

it('does not change the connection or run a Gateway execution hook after native failure', function (): void {
    connection_hook_worker();
    $connection = DatabaseConnection::query()->create(['slug' => 'cache', 'driver' => 'redis', 'host' => 'old.example.test', 'port' => 6379]);
    $hook = connection_hook();
    $operation = app(UpdateDatabaseConnectionAction::class)->execute($connection, connection_hook_update());
    app(DatabaseConnectionUpdateProjection::class)->apply($operation, [
        'run_id' => $operation->state['swamp_run_id'], 'status' => 'failed', 'error_code' => 'database.server_command_failed',
        'started_at' => '2026-10-31T09:00:00.000Z', 'finished_at' => '2026-10-31T09:00:01.000Z', 'result' => ['error_code' => 'database.server_command_failed'],
    ]);
    expect($connection->fresh()->host)->toBe('old.example.test')->and(array_column($hook->calls, 0))->toBe(['validate']);
});

it('calls a tagged removal guard before deleting or broadcasting', function (): void {
    $connection = DatabaseConnection::query()->create(['slug' => 'cache', 'driver' => 'redis', 'host' => 'cache.example.test', 'port' => 6379]);
    $guard = new class implements DatabaseConnectionRemovalGuard
    {
        public function assertRemovable(DatabaseConnection $connection): void
        {
            throw new ResourceOperationException('database_connection.test_refused', 'Connection is in use.', 409);
        }
    };
    app()->instance($guard::class, $guard);
    app()->tag($guard::class, DatabaseConnectionRemovalGuard::class);
    Event::fake([RecordBroadcast::class]);

    expect(fn () => app(DestroyDatabaseConnectionAction::class)->execute($connection))
        ->toThrow(ResourceOperationException::class, 'Connection is in use.');

    $this->assertModelExists($connection);
    Event::assertNotDispatched(RecordBroadcast::class);
});

it('wires the core PostgreSQL update hook and refuses changing managed credentials or endpoints', function (): void {
    expect(collect(app()->tagged(DatabaseConnectionUpdateHook::class))->contains(fn ($hook): bool => $hook instanceof PostgresConnectionUpdateHook))->toBeTrue();
    $node = Node::query()->create(['name' => 'services', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.17', 'status' => 'active', 'platform' => 'linux']);
    $server = DatabaseServer::query()->create(['slug' => 'pg', 'driver' => 'pgsql', 'node_id' => $node->id, 'tag' => '18', 'port' => 5434, 'root_password' => 'admin', 'status' => 'active']);
    $connection = DatabaseConnection::query()->create(['slug' => 'app', 'driver' => 'pgsql', 'database_server_id' => $server->id, 'host' => '10.44.0.3', 'port' => 5434, 'database' => 'app', 'username' => 'app', 'password' => 'secret']);
    expect(fn () => app(UpdateDatabaseConnectionAction::class)->execute($connection, connection_hook_update()))
        ->toThrow(ResourceOperationException::class, 'Create a new managed PostgreSQL connection');
    expect($connection->fresh()->host)->toBe('10.44.0.3');
});

it('wires the core analytics database removal guard before database destruction', function (): void {
    expect(collect(app()->tagged(DatabaseConnectionRemovalGuard::class))->contains(fn ($guard): bool => $guard instanceof AnalyticsDatabaseConnectionGuard))->toBeTrue();
});

it('refuses dropping the managed analytics owner database while the role refers to its Process', function (): void {
    $storage = analytics_storage_processes();
    $node = $storage['node'];
    $node->roles()->create(['role' => RoleName::Analytics, 'status' => 'active']);
    app(AnalyticsRoleSettingsRepository::class)->store($node, new AnalyticsRoleSettings($storage['postgres']->id, $storage['clickhouse']->id));
    $server = DatabaseServer::query()->create(['slug' => 'managed-pg', 'driver' => 'pgsql', 'node_id' => $node->id, 'process_id' => $storage['postgres']->id, 'tag' => '18', 'port' => 5434, 'root_password' => 'admin-fixture', 'status' => 'active']);
    $connection = DatabaseConnection::query()->create(['slug' => 'plausible-db', 'driver' => 'pgsql', 'database_server_id' => $server->id, 'host' => $node->wireguard_ip, 'port' => 5434, 'database' => 'plausible_db', 'username' => 'plausible_db', 'password' => 'owner-fixture']);
    expect(fn () => app(DestroyDatabaseConnectionAction::class)->execute($connection))->toThrow(ResourceOperationException::class, 'Move or remove the analytics role');
    expect($connection->fresh())->not->toBeNull();
});
