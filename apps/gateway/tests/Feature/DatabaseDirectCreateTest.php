<?php

declare(strict_types=1);

use App\Domain\DatabaseServers\DatabaseCreateProjection;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\DatabaseExecutionIntent;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeRoutePublicationSocket;

require_once __DIR__.'/../Support/ServerDatabaseFixtures.php';
uses(ServerDatabaseFixtures::class);

beforeEach(function (): void {
    $this->prepareServerDatabaseFixtures();
    $this->dbNode->update(['architecture' => 'amd64']);
    $this->dbNode->accessibleNodes()->attach($this->gateway);
    $this->runId = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    config(['swamp.run_token' => 'fake.token']);
    $socket = $this->socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $sent): array => $sent['type'] === 'vault.put' ? [['type' => 'vault.put', 'id' => $sent['id'], 'payload' => ['data' => ['vaultName' => $sent['payload']['vaultName'], 'secretKey' => $sent['payload']['key'], 'vaultType' => 'local_encryption']]]] : [['type' => 'event', 'id' => $sent['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-database-create', 'runId' => $this->runId]]];
    app()->instance(WebSocketClient::class, $socket);
    $this->finish = ['run_id' => $this->runId, 'status' => 'succeeded', 'started_at' => now()->subSecond()->toIso8601ZuluString('millisecond'), 'finished_at' => now()->toIso8601ZuluString('millisecond'), 'result' => ['backend' => 'active']];
});

/** The native result of a create run: the batch and the owner check, as Swamp's run data gives them. */
function create_native(array $finish, string $driver, string $user, string $batch = '', int $exit = 0): array
{
    $check = ['mysql' => $user.'@%', 'pgsql' => $user, 'redis' => 'PONG'][$driver];

    return [...$finish, 'result' => ['steps' => ['Create owner and databases' => ['status' => 'succeeded', 'stdout' => $batch, 'exit_code' => $exit],
        'Check the owner login' => ['status' => 'succeeded', 'stdout' => $check."\n", 'exit_code' => 0]]]];
}

it('starts one direct managed create with safe typed intent and no Gateway probes', function (string $driver): void {
    $this->server->update(['driver' => $driver]);
    $request = ['slug' => 'proof', 'server' => $this->server->slug, ...($driver === 'redis' ? ['patterns' => ['proof:*'], 'allow' => ['@list'], 'database' => 1] : [])];
    $response = $this->postJson('/api/v1/database-connections', $request)->assertAccepted()->assertJsonPath('data.kind', 'database.create');
    $operation = Operation::query()->sole();
    expect(app(OperationKinds::class)->definition('database.create'))->not->toHaveKeys(['runs', 'execution', 'completion', 'children']);
    expect($operation->state)->toMatchArray(['swamp_run_id' => $this->runId]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->dbNode->wireguard_ip]);
    $path = '/api/v1/direct-operations/'.$operation->id;
    $input = $this->getJson($path.'?run_id='.$this->runId)->assertOk()->json('data.input.intent');
    expect($input)->toMatchArray(['driver' => $driver, 'username' => 'proof'])->not->toHaveKeys(['sql', 'password', 'payload']);
    $this->getJson($path.'/private-material?run_id='.$this->runId)->assertUnprocessable();
    $puts = collect($this->socket->sent)->where('type', 'vault.put')->pluck('payload.value', 'payload.key');
    $start = collect($this->socket->sent)->firstWhere('type', '!==', 'vault.put');
    $inputs = $start['payload']['inputs'];
    $id = $operation->input['intent_id'];
    $statement = "database-create-intent-{$id}";
    $owner = "database-create-owner-{$id}";
    expect($inputs['execution_secrets'])->toBe(['create' => ['vault' => 'orbit-control-inputs', 'key' => $statement], 'check' => ['vault' => 'orbit-control-inputs', 'key' => $owner]]);
    expect(array_slice($inputs['intent']['admin_client'], 0, 6))->toBe(['docker', 'exec', '-i', 'orbit-process-'.$this->server->process_id.'-beast-mysql', 'sh', '-c']);
    $connection = DatabaseConnection::query()->sole();
    // Each login reads its password from the first stdin line; the batch with the owner's password follows (Q-DBAUTH).
    expect($puts[$statement])->toStartWith(SERVER_DATABASE_ROOT_SECRET."\n")
        ->toContain($driver === 'pgsql' ? 'SCRAM-SHA-256' : ($driver === 'redis' ? 'ACL SETUSER proof' : $connection->password));
    expect($puts[$statement])->toContain($driver === 'redis' ? 'CONFIG GET databases' : 'database.name_conflict');
    expect($puts[$owner])->toStartWith($connection->password."\n");
    expect(json_encode($inputs))->not->toContain($connection->password)->not->toContain(SERVER_DATABASE_ROOT_SECRET);
    expect(json_encode($operation->fresh()->toArray()).$response->getContent())->not->toContain(SERVER_DATABASE_ROOT_SECRET, $connection->password);
    expect(DB::table('database_execution_intents')->sole()->payload)->not->toContain($connection->password);
    $this->postJson($path.'/primitive', ['run_id' => $this->runId])->assertUnprocessable();
    $this->postJson($path.'/secret-output', ['run_id' => $this->runId, 'outputs' => ['password' => 'unadmitted']])->assertUnprocessable();
})->with(['mysql', 'pgsql', 'redis']);

it('projects the stored attachment once without Node calls or execution receipts', function (): void {
    $this->postJson('/api/v1/database-connections', ['slug' => 'owned', 'server' => $this->server->slug, 'instance_id' => $this->instance->id])->assertAccepted();
    $operation = Operation::query()->sole();
    $path = '/api/v1/direct-operations/'.$operation->id;
    $this->withServerVariables(['REMOTE_ADDR' => $this->dbNode->wireguard_ip]);
    $finish = create_native($this->finish, 'mysql', DatabaseConnection::query()->sole()->username);
    $this->postJson($path.'/result', $finish)->assertOk();
    expect(DatabaseConnectionTarget::query()->sole()->database_connection_id)->toBe(DatabaseConnection::query()->sole()->id);
    $this->postJson($path.'/result', $finish)->assertOk();
    $this->assertDatabaseCount('database_connection_targets', 1);
    $this->getJson($path.'/private-material?run_id='.$this->runId)->assertConflict();
});

it('fails with name_conflict when the batch refuses a name another owner holds', function (): void {
    $this->postJson('/api/v1/database-connections', ['slug' => 'taken', 'server' => $this->server->slug])->assertAccepted();
    $operation = Operation::query()->sole();
    $this->withServerVariables(['REMOTE_ADDR' => $this->dbNode->wireguard_ip]);
    $this->postJson('/api/v1/direct-operations/'.$operation->id.'/result', create_native($this->finish, 'mysql', 'taken', "database.name_conflict\n", 1))->assertOk();
    expect([$operation->fresh()->status->value, $operation->fresh()->error_code, $operation->fresh()->failed_step])->toBe(['failed', 'database.name_conflict', 'Create owner and databases']);
});

it('gives an Instance database on MySQL the parallel test pool, as on PostgreSQL', function (): void {
    $this->postJson('/api/v1/database-connections', ['slug' => 'pooled', 'server' => $this->server->slug, 'instance_id' => $this->instance->id, 'test_processes' => 3])->assertAccepted();
    $connection = DatabaseConnection::query()->sole();
    $operation = Operation::query()->sole();
    $databases = DatabaseExecutionIntent::recorded($operation->input['intent_id'])->payload['commands']['database-apply']['input']['databases'];
    expect($connection->test_processes)->toBe(3)
        ->and($databases)->toBe([$connection->database, $connection->test_database, ...array_map(static fn (int $n): string => $connection->test_database.'_test_'.$n, [1, 2, 3])]);
    $puts = collect($this->socket->sent)->where('type', 'vault.put')->pluck('payload.value', 'payload.key');
    expect($puts['database-create-intent-'.$operation->input['intent_id']])->toContain('CREATE DATABASE IF NOT EXISTS `'.$connection->test_database.'_test_3`;');
});

it('preserves reserved credentials after failure and admits only an identical retry', function (): void {
    $request = ['slug' => 'retry', 'server' => $this->server->slug];
    $this->postJson('/api/v1/database-connections', $request)->assertAccepted();
    $operation = Operation::query()->sole();
    $password = DatabaseConnection::query()->sole()->password;
    $this->withServerVariables(['REMOTE_ADDR' => $this->dbNode->wireguard_ip]);
    $this->postJson('/api/v1/direct-operations/'.$operation->id.'/result', [...$this->finish, 'status' => 'failed', 'error_code' => 'database.name_conflict', 'result' => ['error_code' => 'database.name_conflict']])->assertOk();
    expect(DatabaseConnection::query()->sole()->password)->toBe($password);
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $this->postJson('/api/v1/database-connections', $request)->assertAccepted();
    expect(DatabaseConnection::query()->sole()->password)->toBe($password);
    expect(Operation::query()->latest('id')->first()->input['intent_id'])->toBe($operation->input['intent_id']);
});

it('refuses foreign Node private reads and mismatched admitted connection projection', function (): void {
    $this->postJson('/api/v1/database-connections', ['slug' => 'owned', 'server' => $this->server->slug])->assertAccepted();
    $operation = Operation::query()->sole();
    $this->getJson('/api/v1/direct-operations/'.$operation->id.'/private-material?run_id='.$this->runId)->assertForbidden();
    $operation->node_id = $this->gateway->id;
    expect(fn () => app(DatabaseCreateProjection::class)->input($operation))->toThrow(ResourceOperationException::class);
});

it('refuses system names before recording or starting any create', function (string $slug): void {
    $this->postJson('/api/v1/database-connections', ['slug' => $slug, 'server' => $this->server->slug])->assertUnprocessable()->assertJsonPath('error.code', 'database.system_database');
    $this->assertDatabaseCount('operations', 0);
    $this->assertDatabaseCount('database_connections', 0);
})->with(['mysql', 'sys', 'performance-schema', 'information-schema', 'postgres', 'template0']);

it('refuses create intent while removal on the same server is nonterminal', function (): void {
    $removal = Operation::query()->create(['kind' => 'database.destroy', 'target_type' => 'database_server', 'target_id' => $this->server->id, 'node_id' => $this->dbNode->id, 'status' => 'running', 'input' => []]);
    $request = ['slug' => 'unsafe', 'server' => $this->server->slug];
    $this->postJson('/api/v1/database-connections', $request)->assertConflict()->assertJsonPath('error.code', 'database.server_in_use');
    $this->assertDatabaseCount('database_connections', 0);
    $removal->update(['status' => 'succeeded', 'finished_at' => now()]);
    $this->postJson('/api/v1/database-connections', $request)->assertAccepted();
});

it('abandons an invalid Valkey index reservation without deleting an ACL user', function (): void {
    $this->server->update(['driver' => 'redis']);
    $request = ['slug' => 'invalid-index', 'server' => $this->server->slug, 'patterns' => ['proof:*'], 'allow' => ['@list'], 'database' => 16];
    $this->postJson('/api/v1/database-connections', $request)->assertAccepted();
    $operation = Operation::query()->sole();
    $this->withServerVariables(['REMOTE_ADDR' => $this->dbNode->wireguard_ip]);
    $path = '/api/v1/direct-operations/'.$operation->id;
    // The batch reads the live count first; an index at or above it fails the run and abandons the reservation.
    $failed = create_native($this->finish, 'redis', 'unused', "databases\n4\nOK\nOK\n");
    $this->postJson($path.'/result', $failed)->assertOk();
    expect($operation->fresh()->error_code)->toBe('database.index_invalid');
    $this->assertDatabaseCount('database_connections', 0);
    $this->assertDatabaseCount('database_users', 0);
    $this->getJson($path.'?run_id='.$this->runId)->assertOk()->assertJsonPath('data.status', 'failed');
    $this->postJson($path.'/result', $failed)->assertOk();
    $this->getJson($path.'/private-material?run_id='.$this->runId)->assertConflict();
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $this->postJson('/api/v1/database-connections', [...$request, 'database' => 15])->assertAccepted();
});

it('keeps recorded create intent immutable and projects terminal history exactly once', function (): void {
    $this->postJson('/api/v1/database-connections', ['slug' => 'native-proof', 'server' => $this->server->slug])->assertAccepted();
    $operation = Operation::query()->sole();
    $inputs = collect($this->socket->sent)->firstWhere('type', '!==', 'vault.put')['payload']['inputs'];
    $this->server->update(['port' => 3333, 'root_password' => 'changed-root-secret']);
    expect(app(DirectOperation::class)->workflowInputs($operation)['intent'])->toBe($inputs['intent']);
    $history = ['id' => $this->runId, 'workflowName' => 'operation-database-create', 'status' => 'succeeded', 'inputs' => $inputs,
        'startedAt' => '2026-10-31T12:00:00Z', 'completedAt' => '2026-10-31T12:00:01Z',
        'jobs' => [['name' => 'create', 'steps' => [['name' => 'Create owner and databases', 'status' => 'succeeded', 'outputs' => ['stdout' => '', 'exitCode' => 0]],
            ['name' => 'Check the owner login', 'status' => 'succeeded', 'outputs' => ['stdout' => DatabaseConnection::query()->sole()->username."@%\n", 'exitCode' => 0]]]]],
    ];
    $this->socket->reply = static fn (array $sent): array => [['type' => 'workflow.history.get', 'id' => $sent['id'], 'payload' => ['data' => $history]]];
    config(['swamp.read_token' => 'fake.token']);
    $selector = app(DirectOperation::class)->definition('database.create')['terminal_result'];
    $projected = app(SwampCommandStep::class)->projectTerminal($operation, ['id' => $this->runId, 'status' => 'succeeded'], $selector);
    app(SwampCommandStep::class)->projectTerminal($projected, ['id' => $this->runId, 'status' => 'succeeded'], $selector);
    expect($projected->status->value)->toBe('succeeded');
    expect($projected->result)->toBe(['backend' => 'active']);
    expect(array_column($this->socket->sent, 'type'))->toBe(['vault.put', 'vault.put', 'workflow.run', 'vault.put', 'workflow.history.get']);
});
