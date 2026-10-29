<?php

declare(strict_types=1);

use App\Actions\DatabaseConnections\DropServerDatabaseAction;
use App\Domain\DatabaseServers\DatabaseOperationExecution;
use App\Domain\DatabaseServers\DatabaseOwnership;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Activity;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\DatabaseExecutionIntent;
use App\Models\Operation;

require_once __DIR__.'/../Support/ServerDatabaseFixtures.php';
require_once __DIR__.'/../Support/DatabaseCreationProofs.php';
uses(ServerDatabaseFixtures::class);
beforeEach(function (): void {
    $this->prepareServerDatabaseFixtures();
    $this->connection = DatabaseConnection::query()->create(['slug' => 'legacy', 'driver' => 'mysql', 'database_server_id' => $this->server->id,
        'database' => 'legacy_app', 'test_database' => 'legacy_app_test', 'username' => 'legacy_owner', 'password' => 'synthetic-owner-secret',
        'owner_instance_id' => $this->instance->id]);
    $this->admin->rows = ["SELECT 'owned'" => ['owned'], 'SCHEMATA' => ['legacy_app', 'legacy_app_test_1']];
});

it('adopts an existing MySQL connection from live grants and attributes the proof', function (): void {
    $this->artisan('database:ownership', ['action' => 'adopt', 'connection' => 'legacy', '--actor' => 'operator-nick', '--reason' => 'Verified existing deployment'])
        ->expectsOutputToContain('Database ownership adopted [legacy].')->assertSuccessful();

    $connection = $this->connection->fresh();
    expect($connection->ownership_adoption)->toMatchArray(['actor' => 'operator-nick', 'reason' => 'Verified existing deployment',
        'username' => 'legacy_owner', 'prefix' => 'legacy_app_test', 'databases' => ['legacy_app', 'legacy_app_test_1']]);
    expect($connection->toArray())->not->toHaveKeys(['ownership_adoption', 'password']);
    $activity = Activity::query()->where('command', 'database:ownership:adopt')->sole();
    expect($activity->properties->get('actor'))->toBe('operator-nick');
    expect(json_encode($activity->toArray()))->not->toContain('synthetic-owner-secret', SERVER_DATABASE_ROOT_SECRET);
    expect($this->admin->sql())->toContain("BINARY User = BINARY 'legacy_owner'", "Host = '%'", "BINARY Db = BINARY 'legacy_app'", "BINARY Db = BINARY 'legacy\\\\_app\\\\_test%'", "Drop_priv = 'Y'", "Select_priv = 'Y'", "Trigger_priv = 'Y'")
        ->not->toContain('CREATE ', 'ALTER ', 'DROP ', 'GRANT ');
    $this->admin->rows['SCHEMATA'] = ['legacy_app_test_1', 'legacy_app_test_23'];
    $prepared = app(DropServerDatabaseAction::class)->prepareMany([$connection])[0];
    $sql = DatabaseExecutionIntent::recorded($prepared->input['intent_id'])->payload['commands']['database-apply']['input']['sql'];
    expect($sql)->toContain('DROP DATABASE IF EXISTS `legacy_app`', 'DROP DATABASE IF EXISTS `legacy_app_test_23`');
    expect(fn () => app(DatabaseOwnership::class)->assertMysqlNames($connection, ['legacyXapp_test_1']))
        ->toThrow(ResourceOperationException::class);
});

it('refuses adoption when live account, database or full grants are unproven', function (): void {
    $this->admin->rows["SELECT 'owned'"] = [];
    $this->artisan('database:ownership', ['action' => 'adopt', 'connection' => 'legacy', '--actor' => 'nick', '--reason' => 'Recover'])
        ->expectsOutputToContain('database.ownership_unproven')->assertFailed();

    expect($this->connection->fresh()->ownership_adoption)->toBeNull();
    expect(Activity::query()->count())->toBe(0);
    expect(fn () => app(DropServerDatabaseAction::class)->prepareMany([$this->connection]))
        ->toThrow(ResourceOperationException::class);
});

it('forgets an uncertain reservation and its attachments without any remote command', function (): void {
    DatabaseConnectionTarget::query()->create(['database_connection_id' => $this->connection->id, 'instance_id' => $this->instance->id, 'prefix' => 'DB']);
    Operation::query()->create(['kind' => 'database.create', 'target_type' => 'database_server', 'target_id' => $this->server->id,
        'node_id' => $this->dbNode->id, 'status' => 'failed', 'input' => ['connection_id' => $this->connection->id]]);

    $this->artisan('database:ownership', ['action' => 'forget', 'connection' => 'legacy', '--actor' => 'nick', '--reason' => 'Preserve restored data'])
        ->expectsOutputToContain('database and users unchanged')->assertSuccessful();

    $this->assertModelMissing($this->connection);
    expect(DatabaseConnectionTarget::query()->count())->toBe(0);
    expect($this->admin->statements)->toBe([]);
    expect(Activity::query()->where('command', 'database:ownership:forget')->sole()->properties->get('reason'))->toBe('Preserve restored data');
});

it('requires explicit attribution and refuses recovery while a lifecycle operation is active', function (string $action, bool $busy): void {
    if ($busy) {
        Operation::query()->create(['kind' => 'instance.destroy', 'target_type' => 'instance', 'target_id' => $this->instance->id,
            'node_id' => $this->dbNode->id, 'status' => 'waiting', 'input' => []]);
    }
    $this->artisan('database:ownership', ['action' => $action, 'connection' => 'legacy', '--actor' => $busy ? 'nick' : '', '--reason' => 'Recover'])
        ->expectsOutputToContain($busy ? 'database.connection_busy' : 'validation.failed')->assertFailed();

    $this->assertModelExists($this->connection);
    expect($this->connection->fresh()->ownership_adoption)->toBeNull();
    expect($this->admin->statements)->toBe([]);
})->with([['adopt', true], ['forget', true], ['adopt', false], ['forget', false]]);

it('covers future prefix test databases only after the grant has a successful durable receipt', function (): void {
    $operation = Operation::query()->create(['kind' => 'database.create', 'target_type' => 'database_server', 'target_id' => $this->server->id,
        'node_id' => $this->dbNode->id, 'status' => 'running', 'input' => ['connection_id' => $this->connection->id]]);
    $intent = DatabaseExecutionIntent::query()->create(['target_type' => 'database_server', 'target_id' => $this->server->id, 'node_id' => $this->dbNode->id,
        'payload' => ['commands' => ['grant-tests' => ['name' => 'database.server-admin', 'input' => [], 'grant_prefix' => 'legacy_app_test']], 'projection' => []]]);
    $operation->update(['input' => [...$operation->input, 'intent_id' => $intent->id]]);
    $run = ['key' => 'grant-tests', 'name' => 'database.server-admin', 'transport' => 'agent'];
    $execution = app(DatabaseOperationExecution::class);
    $result = $execution->received($operation, $run, ['output' => '']);
    $ownership = app(DatabaseOwnership::class);
    expect($ownership->mysqlNames($this->connection->fresh(), ['legacy_app_test_8']))->toBe([]);
    $operation->update(['state' => ['executor_runs' => ['grant-tests' => [...$run, 'result' => $result]]]]);

    expect($ownership->mysqlNames($this->connection->fresh(), ['legacy_app_test_8', 'foreign']))->toBe(['legacy_app_test_8']);
    $this->connection->update(['username' => 'other_user']);
    expect($ownership->mysqlNames($this->connection->fresh(), ['legacy_app_test_8']))->toBe([]);
});
