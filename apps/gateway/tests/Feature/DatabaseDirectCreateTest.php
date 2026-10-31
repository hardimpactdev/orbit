<?php

declare(strict_types=1);

use App\Domain\DatabaseServers\DatabaseCreateProjection;
use App\Domain\DatabaseServers\DatabaseOwnership;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Activity;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\DatabaseExecutionIntent;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Support/ServerDatabaseFixtures.php';
uses(ServerDatabaseFixtures::class);

beforeEach(function (): void {
    $this->prepareServerDatabaseFixtures();
    config(['operation_executor.worker_node_ids' => [$this->dbNode->id]]);
    $this->dbNode->accessibleNodes()->attach($this->gateway);
    $this->runId = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $this->finish = ['run_id' => $this->runId, 'status' => 'succeeded', 'started_at' => '2026-10-31T03:00:00.000Z', 'finished_at' => '2026-10-31T03:00:01.000Z', 'result' => ['backend' => 'active']];
});

it('starts one direct managed create with safe typed intent and no Gateway probes', function (string $driver): void {
    $this->server->update(['driver' => $driver]);
    $request = ['slug' => 'proof', 'server' => $this->server->slug, ...($driver === 'redis' ? ['patterns' => ['proof:*'], 'allow' => ['@list'], 'database' => 1] : [])];
    $response = $this->postJson('/api/v1/database-connections', $request)->assertAccepted()->assertJsonPath('data.kind', 'database.create');
    $operation = Operation::query()->sole();
    expect(app(OperationKinds::class)->definition('database.create'))->not->toHaveKeys(['runs', 'execution', 'completion', 'children']);
    expect($operation->state)->toMatchArray(['database_create_writer' => 'direct', 'swamp_run_id' => $this->runId]);
    expect($operation->attempts)->toBe(0);
    expect($operation->worker)->toBeNull();
    expect($this->admin->statements)->toBe([]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->dbNode->wireguard_ip]);
    $path = '/api/v1/direct-operations/'.$operation->id;
    $input = $this->getJson($path.'?run_id='.$this->runId)->assertOk()->json('data.input');
    expect($input)->toMatchArray(['driver' => $driver, 'username' => 'proof'])->not->toHaveKeys(['sql', 'password', 'payload']);
    $material = $this->getJson($path.'/private-material?run_id='.$this->runId)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');
    $connection = DatabaseConnection::query()->sole();
    expect($material['password'])->toBe(SERVER_DATABASE_ROOT_SECRET);
    expect($material['payload']['password'])->toBe($connection->password);
    expect(json_encode($operation->fresh()->toArray()).$response->getContent())->not->toContain(SERVER_DATABASE_ROOT_SECRET, $connection->password);
    expect(DB::table('database_execution_intents')->sole()->payload)->not->toContain($connection->password);
    $this->postJson($path.'/primitive', ['run_id' => $this->runId])->assertUnprocessable();
    $this->postJson($path.'/secret-output', ['run_id' => $this->runId, 'outputs' => ['password' => 'unadmitted']])->assertUnprocessable();
})->with(['mysql', 'pgsql', 'redis']);

it('projects stored attachment and durable MySQL ownership facts once without Node calls', function (): void {
    $this->postJson('/api/v1/database-connections', ['slug' => 'owned', 'server' => $this->server->slug, 'instance_id' => $this->instance->id])->assertAccepted();
    $operation = Operation::query()->sole();
    $path = '/api/v1/direct-operations/'.$operation->id;
    $this->withServerVariables(['REMOTE_ADDR' => $this->dbNode->wireguard_ip]);
    $this->postJson($path.'/result', $this->finish)->assertOk();
    $connection = DatabaseConnection::query()->sole();
    expect(DatabaseConnectionTarget::query()->sole()->database_connection_id)->toBe($connection->id);
    expect(app(DatabaseOwnership::class)->mysqlNames($connection, ['owned_test_worker']))->toBe(['owned', 'owned_test_worker']);
    expect($connection->creation_receipts)->toHaveCount(2);
    $count = Activity::query()->where('command', 'database:lifecycle:create')->count();
    $this->postJson($path.'/result', $this->finish)->assertOk();
    expect(Activity::query()->where('command', 'database:lifecycle:create')->count())->toBe($count);
    $this->getJson($path.'/private-material?run_id='.$this->runId)->assertConflict()->assertJsonPath('error.code', 'operation.finished');
    DatabaseExecutionIntent::query()->delete();
    expect(app(DatabaseOwnership::class)->mysqlNames($connection))->toBe(['owned']);
    expect($this->admin->statements)->toBe([]);
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
