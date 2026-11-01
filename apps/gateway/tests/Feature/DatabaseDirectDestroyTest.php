<?php

declare(strict_types=1);

use App\Actions\DatabaseConnections\DropServerDatabaseAction;
use App\Domain\DatabaseServers\DatabaseDestroyProjection;
use App\Domain\DatabaseServers\DatabaseDestroySafety;
use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\DatabaseExecutionIntent;
use App\Models\Operation;
use Tests\Support\FakeRoutePublicationSocket;

require_once __DIR__.'/../Support/ServerDatabaseFixtures.php';
uses(ServerDatabaseFixtures::class);

beforeEach(function (): void {
    $this->prepareServerDatabaseFixtures();
    $this->dbNode->update(['architecture' => 'amd64']);
    $this->runId = 'b30960ef-d7d8-423f-bb66-26e38502e6be';
    config(['swamp.run_token' => 'fake.token']);
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = fn (array $sent): array => $sent['type'] === 'vault.put'
        ? [['type' => 'vault.put', 'id' => $sent['id'], 'payload' => ['data' => ['vaultName' => $sent['payload']['vaultName'], 'secretKey' => $sent['payload']['key'], 'vaultType' => 'local_encryption']]]]
        : [['type' => 'event', 'id' => $sent['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-database-destroy', 'runId' => $this->runId]]];
    app()->instance(WebSocketClient::class, $this->socket);
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

it('starts native removal with captured intent and a vault descriptor for each engine', function (string $driver): void {
    $this->server->update(['driver' => $driver]);
    $connection = DatabaseConnection::query()->create(['slug' => 'proof', 'driver' => $driver, 'database_server_id' => $this->server->id, 'database' => 'proof', 'username' => 'proof']);
    $operation = app(DropServerDatabaseAction::class)->execute($connection);
    $inputs = $this->socket->sent[1]['payload']['inputs'];
    expect(array_column($this->socket->sent, 'type'))->toBe(['vault.put', 'workflow.run']);
    expect($inputs['intent'])->toMatchArray(['driver' => $driver, 'users' => ['proof']])->not->toHaveKeys(['password', 'payload', 'sql']);
    expect($inputs['execution_secrets']['database_destroy'])->toBe(['vault' => 'orbit-control-inputs', 'key' => 'database-destroy-intent-'.$operation->input['intent_id']]);
    expect(json_decode($this->socket->sent[0]['payload']['value'], true, flags: JSON_THROW_ON_ERROR))->toBe(['password' => $this->server->root_password]);
    expect(json_encode($inputs, JSON_THROW_ON_ERROR))->not->toContain($this->server->root_password);
    expect(app(DirectOperation::class)->definition('database.destroy'))->not->toHaveKey('private_material');
    $this->server->update(['port' => 3333, 'root_password' => 'changed-private-root']);
    expect(app(DirectOperation::class)->workflowInputs($operation))->toEqual($inputs);
    $this->assertDatabaseHas('database_connections', ['id' => $connection->id]);
})->with(['mysql', 'pgsql', 'redis']);

it('deletes records once from successful native terminal history', function (): void {
    $connection = DatabaseConnection::query()->create(['slug' => 'proof', 'driver' => 'mysql', 'database_server_id' => $this->server->id, 'database' => 'proof', 'username' => 'proof']);
    $operation = app(DropServerDatabaseAction::class)->execute($connection);
    $inputs = $this->socket->sent[1]['payload']['inputs'];
    $history = ['id' => $this->runId, 'workflowName' => 'operation-database-destroy', 'status' => 'succeeded', 'inputs' => $inputs,
        'startedAt' => '2026-11-01T00:30:00Z', 'completedAt' => '2026-11-01T00:30:01Z',
        'jobs' => [['name' => 'result', 'steps' => [['name' => 'result', 'outputs' => ['stdout' => '{"backend":"absent"}']]]]],
    ];
    $this->socket->reply = static fn (array $sent): array => [['type' => 'workflow.history.get', 'id' => $sent['id'], 'payload' => ['data' => $history]]];
    config(['swamp.read_token' => 'fake.token']);
    $selector = app(DirectOperation::class)->definition('database.destroy')['terminal_result'];
    $projected = app(SwampCommandStep::class)->projectTerminal($operation, ['id' => $this->runId, 'status' => 'succeeded'], $selector);
    app(SwampCommandStep::class)->projectTerminal($projected, ['id' => $this->runId, 'status' => 'succeeded'], $selector);
    expect($projected->status->value)->toBe('succeeded');
    expect($projected->result)->toBe(['backend' => 'absent']);
    $this->assertDatabaseMissing('database_connections', ['id' => $connection->id]);
    expect(array_column($this->socket->sent, 'type'))->toBe(['vault.put', 'workflow.run', 'workflow.history.get']);
});
