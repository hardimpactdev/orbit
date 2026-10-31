<?php

declare(strict_types=1);

use App\Actions\DatabaseConnections\DropServerDatabaseAction;
use App\Domain\DatabaseServers\DatabaseDestroyProjection;
use App\Domain\DatabaseServers\DatabaseDestroySafety;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\DatabaseExecutionIntent;
use App\Models\Operation;

require_once __DIR__.'/../Support/ServerDatabaseFixtures.php';
uses(ServerDatabaseFixtures::class);

beforeEach(function (): void {
    $this->prepareServerDatabaseFixtures();
    $this->dbNode->update(['architecture' => 'amd64']);
});

it('admits recorded MySQL identity without an execution receipt or PHP probe', function (): void {
    $connection = DatabaseConnection::query()->create(['slug' => 'proof', 'driver' => 'mysql', 'database_server_id' => $this->server->id,
        'database' => 'proof', 'test_database' => 'proof_testing', 'username' => 'proof', 'owner_instance_id' => $this->instance->id]);
    $prepared = app(DropServerDatabaseAction::class)->prepareMany([$connection], $this->instance->id)[0];
    $intent = DatabaseExecutionIntent::recorded($prepared->input['intent_id']);
    expect($prepared->kind)->toBe('database.destroy');
    expect($prepared->input['test_prefixes'])->toBe(['proof_testing']);
    expect($prepared->input['owners']->proof)->toBe('proof');
    expect($intent->payload['commands']['database-apply']['protected'])->toBe(['password']);
    expect(array_keys($intent->payload['commands']['database-apply']['input']))->toBe(['password']);
    expect(Operation::query()->count())->toBe(0);
});

it('refuses system database prefixes before recording an intent', function (): void {
    $connection = DatabaseConnection::query()->create(['slug' => 'system', 'driver' => 'mysql', 'database_server_id' => $this->server->id,
        'database' => 'proof', 'test_database' => 'my', 'username' => 'proof']);
    expect(fn () => app(DropServerDatabaseAction::class)->prepareMany([$connection]))->toThrow(ResourceOperationException::class, 'System databases');
    expect(DatabaseExecutionIntent::query()->count())->toBe(0);
});

it('holds create admission while a native owned removal includes the server', function (bool $lifecycle): void {
    $servers = [['server_id' => $this->server->id]];
    $input = $lifecycle ? ['direct_destroy' => ['database' => ['servers' => $servers]]] : ['servers' => $servers];
    $operation = Operation::query()->create(['kind' => $lifecycle ? 'instance.destroy' : 'database.destroy_owned', 'target_type' => 'instance', 'target_id' => $this->instance->id,
        'node_id' => $this->instance->node_id, 'status' => 'running', 'input' => $input]);
    expect(fn () => app(DatabaseDestroySafety::class)->assertAcceptsCreates($this->server))->toThrow(ResourceOperationException::class, 'removal is unfinished');
    $operation->update(['status' => 'succeeded']);
    app(DatabaseDestroySafety::class)->assertAcceptsCreates($this->server);
})->with([false, true]);

it('refuses destruction while creation on the same server is unfinished', function (): void {
    $connection = DatabaseConnection::query()->create(['slug' => 'proof', 'driver' => 'mysql', 'database_server_id' => $this->server->id, 'database' => 'proof', 'username' => 'proof']);
    Operation::query()->create(['kind' => 'database.create', 'target_type' => 'database_server', 'target_id' => $this->server->id, 'node_id' => $this->dbNode->id, 'status' => 'queued', 'input' => []]);
    expect(fn () => app(DropServerDatabaseAction::class)->prepareMany([$connection]))->toThrow(ResourceOperationException::class, 'creation is unfinished');
    $this->assertDatabaseCount('database_execution_intents', 0);
});

it('refuses system database names before recording a destroy', function (string $name): void {
    $connection = DatabaseConnection::query()->create(['slug' => 'proof', 'driver' => 'mysql', 'database_server_id' => $this->server->id, 'database' => $name, 'username' => 'proof']);
    expect(fn () => app(DropServerDatabaseAction::class)->prepareMany([$connection]))->toThrow(ResourceOperationException::class, 'System databases');
    $this->assertDatabaseCount('database_execution_intents', 0);
})->with(['mysql', 'sys', 'performance_schema', 'information_schema', 'postgres', 'template1']);

it('refuses an attachment or a different recorded owner before destruction', function (): void {
    $connection = DatabaseConnection::query()->create(['slug' => 'proof', 'driver' => 'mysql', 'database_server_id' => $this->server->id,
        'database' => 'proof', 'username' => 'proof', 'owner_instance_id' => $this->instance->id]);
    DatabaseConnectionTarget::query()->create(['database_connection_id' => $connection->id, 'instance_id' => $this->instance->id, 'prefix' => 'DB']);
    expect(fn () => app(DropServerDatabaseAction::class)->prepareMany([$connection]))->toThrow(ResourceOperationException::class, 'Detach');
    expect(fn () => app(DropServerDatabaseAction::class)->prepareMany([$connection], $this->instance->id + 1))->toThrow(ResourceOperationException::class, 'another Instance');
    $this->assertDatabaseCount('database_execution_intents', 0);
});

it('refuses a recorded system name or prefix when rendering native destroy intent', function (array $names, array $prefixes): void {
    $operation = Operation::query()->create(['kind' => 'database.destroy', 'target_type' => 'database_server', 'target_id' => $this->server->id,
        'node_id' => $this->dbNode->id, 'status' => 'running', 'input' => ['databases' => $names, 'test_prefixes' => $prefixes]]);
    expect(fn () => app(DatabaseDestroyProjection::class)->input($operation))
        ->toThrow(ResourceOperationException::class, 'System databases');
})->with([[['mysql'], []], [[], ['s']]]);
