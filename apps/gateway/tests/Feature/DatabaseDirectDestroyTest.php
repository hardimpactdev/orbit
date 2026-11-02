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
    // The plan comes from the records: the test database goes by name, with no prefix match on the server.
    expect($prepared->input['databases'])->toBe(['proof', 'proof_testing'])->and($prepared->input)->not->toHaveKey('test_prefixes');
    expect($prepared->input['owners']->proof)->toBe('proof');
    // The run reads the admin password from the server's own vault key; the intent records no secret.
    expect($intent->payload['commands'])->toBe([]);
    expect(Operation::query()->count())->toBe(0);
});

it('refuses system database prefixes before recording an intent', function (): void {
    $connection = DatabaseConnection::query()->create(['slug' => 'system', 'driver' => 'mysql', 'database_server_id' => $this->server->id,
        'database' => 'proof', 'test_database' => 'my', 'username' => 'proof']);
    expect(fn () => app(DropServerDatabaseAction::class)->prepareMany([$connection]))->toThrow(ResourceOperationException::class, 'System databases');
    expect(DatabaseExecutionIntent::query()->count())->toBe(0);
});

it('holds create admission while an Instance removal drops databases on the server', function (): void {
    $operation = Operation::query()->create(['kind' => 'instance.destroy', 'target_type' => 'instance', 'target_id' => $this->instance->id,
        'node_id' => $this->instance->node_id, 'status' => 'running', 'input' => ['direct_destroy' => ['database' => ['servers' => [['server_id' => $this->server->id]]]]]]);
    expect(fn () => app(DatabaseDestroySafety::class)->assertAcceptsCreates($this->server))->toThrow(ResourceOperationException::class, 'removal is unfinished');
    $operation->update(['status' => 'succeeded']);
    app(DatabaseDestroySafety::class)->assertAcceptsCreates($this->server);
});

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

it('starts removal with one vault value per batch, each behind the admin password line', function (string $driver): void {
    $this->server->update(['driver' => $driver]);
    $connection = DatabaseConnection::query()->create(['slug' => 'proof', 'driver' => $driver, 'database_server_id' => $this->server->id, 'database' => 'proof', 'username' => 'proof']);
    $operation = app(DropServerDatabaseAction::class)->execute($connection);
    $inputs = collect($this->socket->sent)->firstWhere('type', '!==', 'vault.put')['payload']['inputs'];
    $intent = $inputs['intent'];
    $run = $operation->input['intent_id'];
    expect($intent['driver'])->toBe($driver);
    expect(array_slice($intent['admin_client'], 0, 6))->toBe(['docker', 'exec', '-i', 'orbit-process-'.$this->server->process_id.'-beast-mysql', 'sh', '-c']);
    expect($inputs['execution_secrets'])->toBe(array_combine(['fence', 'drop', 'gone', 'restore'], array_map(
        static fn (string $batch): array => ['vault' => 'orbit-control-inputs', 'key' => "database-destroy-{$run}-{$batch}"], ['fence', 'drop', 'gone', 'restore'])));
    $puts = collect($this->socket->sent)->where('type', 'vault.put')->pluck('payload.value', 'payload.key');
    $drop = $puts["database-destroy-{$run}-drop"];
    expect($drop)->toStartWith($this->server->root_password."\n");
    if ($driver === 'redis') {
        expect($drop)->toBe($this->server->root_password."\nACL DELUSER proof\nACL SAVE\n");
    } else {
        expect($drop)->toContain('database.in_use')->toContain('DROP DATABASE IF EXISTS');
        expect($puts["database-destroy-{$run}-gone"])->toContain($driver === 'pgsql' ? "RAISE EXCEPTION 'database.destroy_incomplete'" : 'orbit_destroy_incomplete.refused');
    }
    expect($puts["database-destroy-{$run}-fence"])->toContain($driver === 'pgsql' ? 'ALLOW_CONNECTIONS false' : '');
    expect(json_encode($inputs, JSON_THROW_ON_ERROR))->not->toContain($this->server->root_password);
    expect(app(DirectOperation::class)->definition('database.destroy'))->not->toHaveKey('private_material');
    $this->assertDatabaseHas('database_connections', ['id' => $connection->id]);
})->with(['mysql', 'pgsql', 'redis']);

it('maps the native result to the removal error code', function (array $steps, array $failed, string $code): void {
    $connection = DatabaseConnection::query()->create(['slug' => 'proof', 'driver' => 'mysql', 'database_server_id' => $this->server->id, 'database' => 'proof', 'username' => 'proof']);
    $operation = app(DropServerDatabaseAction::class)->execute($connection);
    [$data, $result] = DatabaseDestroyProjection::outcome('mysql', ['run_id' => $this->runId, 'status' => $failed === [] ? 'succeeded' : 'failed',
        'result' => ['steps' => $steps, 'summary' => ['status' => 'failed', 'succeeded' => 0, 'failed' => count($failed), 'skipped' => 0, 'failed_steps' => $failed]]]);
    expect([$data['status'], $result])->toBe(['failed', ['error_code' => $code]]);
})->with([
    'blocker' => [['Drop databases and users' => ['status' => 'succeeded', 'stdout' => "database.in_use\n", 'exit_code' => 1]], ['Check they are gone'], 'database.in_use'],
    'batch failed' => [['Drop databases and users' => ['status' => 'succeeded', 'stdout' => '', 'exit_code' => 1]], ['Check they are gone'], 'database.server_admin_failed'],
    'still present' => [['Drop databases and users' => ['status' => 'succeeded', 'stdout' => '', 'exit_code' => 0]], ['Check they are gone'], 'database.destroy_incomplete'],
]);

it('refuses a database name that is not a plain identifier before recording anything', function (): void {
    $connection = DatabaseConnection::query()->create(['slug' => 'odd', 'driver' => 'mysql', 'database_server_id' => $this->server->id, 'database' => 'odd`name', 'username' => 'proof']);
    expect(fn () => app(DropServerDatabaseAction::class)->execute($connection))->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('database.server_admin_invalid'));
    expect(Operation::query()->count())->toBe(0);
});

it('deletes records once from successful native terminal history', function (): void {
    $connection = DatabaseConnection::query()->create(['slug' => 'proof', 'driver' => 'mysql', 'database_server_id' => $this->server->id, 'database' => 'proof', 'username' => 'proof']);
    $operation = app(DropServerDatabaseAction::class)->execute($connection);
    $inputs = collect($this->socket->sent)->firstWhere('type', '!==', 'vault.put')['payload']['inputs'];
    $history = ['id' => $this->runId, 'workflowName' => 'operation-database-destroy', 'status' => 'succeeded', 'inputs' => $inputs,
        'startedAt' => '2026-11-01T00:30:00Z', 'completedAt' => '2026-11-01T00:30:01Z',
        'jobs' => [['name' => 'destroy', 'steps' => [['name' => 'Drop databases and users', 'status' => 'succeeded', 'outputs' => ['stdout' => '', 'exitCode' => 0]],
            ['name' => 'Check they are gone', 'status' => 'succeeded', 'outputs' => ['stdout' => '', 'exitCode' => 0]]]]],
    ];
    $this->socket->reply = static fn (array $sent): array => [['type' => 'workflow.history.get', 'id' => $sent['id'], 'payload' => ['data' => $history]]];
    config(['swamp.read_token' => 'fake.token']);
    $selector = app(DirectOperation::class)->definition('database.destroy')['terminal_result'];
    $projected = app(SwampCommandStep::class)->projectTerminal($operation, ['id' => $this->runId, 'status' => 'succeeded'], $selector);
    app(SwampCommandStep::class)->projectTerminal($projected, ['id' => $this->runId, 'status' => 'succeeded'], $selector);
    expect($projected->status->value)->toBe('succeeded');
    expect($projected->result)->toBe(['backend' => 'absent']);
    $this->assertDatabaseMissing('database_connections', ['id' => $connection->id]);
    expect(array_column($this->socket->sent, 'type'))->toBe(['vault.put', 'vault.put', 'vault.put', 'vault.put', 'workflow.run', 'workflow.history.get']);
});
