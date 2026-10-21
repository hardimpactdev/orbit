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
use App\Domain\DatabaseServers\DatabaseServerAdmin;
use App\Domain\DatabaseServers\PostgresConnectionUpdateHook;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use App\Models\Node;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\FakeDatabaseServerAdmin;

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

it('validates the proposal before writing and waits for the outer commit to reconverge', function (): void {
    $connection = DatabaseConnection::query()->create(['slug' => 'cache', 'driver' => 'redis', 'host' => 'old.example.test', 'port' => 6379]);
    $hook = connection_hook();
    $level = DB::transactionLevel();

    DB::transaction(function () use ($connection, $hook, $level): void {
        app(UpdateDatabaseConnectionAction::class)->execute($connection, connection_hook_update());
        expect($hook->calls)->toBe([['validate', 'new.example.test', 'old.example.test', $level + 2]]);
    });

    expect($hook->calls)->toBe([
        ['validate', 'new.example.test', 'old.example.test', $level + 2],
        ['afterCommit', 'new.example.test', 'new.example.test', $level],
    ]);
});

it('leaves the connection and events unchanged when a tagged hook refuses', function (): void {
    $connection = DatabaseConnection::query()->create(['slug' => 'cache', 'driver' => 'redis', 'host' => 'old.example.test', 'port' => 6379]);
    $hook = connection_hook();
    $hook->refuse = true;
    Event::fake([RecordBroadcast::class]);

    expect(fn () => app(UpdateDatabaseConnectionAction::class)->execute($connection, connection_hook_update()))
        ->toThrow(ResourceOperationException::class, 'Connection is in use.');

    expect($connection->refresh()->host)->toBe('old.example.test')
        ->and($hook->calls)->toHaveCount(1);
    Event::assertNotDispatched(RecordBroadcast::class);
});

it('keeps the committed connection when reconvergence fails and retries on the same update', function (): void {
    $connection = DatabaseConnection::query()->create(['slug' => 'cache', 'driver' => 'redis', 'host' => 'old.example.test', 'port' => 6379]);
    $hook = connection_hook();
    $hook->failConvergence = true;

    expect(fn () => app(UpdateDatabaseConnectionAction::class)->execute($connection, connection_hook_update()))
        ->toThrow(RuntimeException::class, 'Collector reconvergence failed.');
    expect($connection->refresh()->host)->toBe('new.example.test');

    $hook->failConvergence = false;
    app(UpdateDatabaseConnectionAction::class)->execute($connection, connection_hook_update());
    expect(array_column($hook->calls, 0))->toBe(['validate', 'afterCommit', 'validate', 'afterCommit']);
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
    $admin = new FakeDatabaseServerAdmin;
    app()->instance(DatabaseServerAdmin::class, $admin);
    expect(fn () => app(DestroyDatabaseConnectionAction::class)->execute($connection))->toThrow(ResourceOperationException::class, 'Move or remove the analytics role');
    expect($connection->fresh())->not->toBeNull()->and($admin->sql())->toBe('');
});
