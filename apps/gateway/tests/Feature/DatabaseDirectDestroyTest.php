<?php

declare(strict_types=1);

use App\Actions\DatabaseConnections\DropServerDatabaseAction;
use App\Domain\Operations\OperationKinds;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\DatabaseConnection;
use App\Models\DatabaseExecutionIntent;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\Process;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->gateway = $this->markAsGateway(Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1']));
    $this->node = Node::query()->create(['name' => 'database-dev', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'amd64', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9']);
    $this->node->accessibleNodes()->attach($this->gateway);
    config(['operation_executor.worker_node_ids' => [$this->node->id], 'swamp.run_token' => 'fake.token']);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $this->node->id, 'name' => 'proof', 'runtime' => 'docker', 'runtime_config' => [], 'restart_policy' => 'unless-stopped', 'desired_state' => 'running', 'status' => 'active']);
    $this->server = DatabaseServer::query()->create(['slug' => 'proof', 'driver' => 'mysql', 'node_id' => $this->node->id, 'process_id' => $process->id, 'tag' => '18', 'port' => 5434, 'root_password' => 'private-root-sentinel', 'status' => 'active', 'default_open' => false]);
    $this->connection = DatabaseConnection::query()->create(['slug' => 'drop-proof', 'driver' => 'mysql', 'database_server_id' => $this->server->id,
        'database' => 'proof', 'username' => 'proof', 'password' => 'private-owner-sentinel']);
    $this->connection->forceFill(['ownership_adoption' => ['databases' => ['proof'], 'prefix' => null, 'username' => 'proof', 'server_id' => $this->server->id, 'node_id' => $this->node->id]])->save();
    $this->runId = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $sent): array => [['type' => 'event', 'id' => $sent['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-database-destroy', 'runId' => $this->runId]]];
    app()->instance(WebSocketClient::class, $socket);
    $this->operation = app(DropServerDatabaseAction::class)->execute($this->connection);
    $this->path = '/api/v1/direct-operations/'.$this->operation->id;
    $this->finished = ['run_id' => $this->runId, 'status' => 'succeeded', 'started_at' => '2026-10-30T19:00:00.000Z', 'finished_at' => '2026-10-30T19:00:01.000Z', 'result' => ['backend' => 'absent']];
});

it('records removal without a Gateway probe or lock and deletes only after native success', function (): void {
    expect(app(OperationKinds::class)->definition('database.destroy'))->not->toHaveKeys(['runs', 'execution', 'completion']);
    expect($this->operation->lock_key)->toBeNull();
    $this->getJson($this->path.'?run_id='.$this->runId)->assertOk()->assertJsonPath('data.input.databases', ['proof'])->assertJsonMissingPath('data.input.sql');
    $this->getJson($this->path.'/private-material?run_id='.$this->runId)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.password', 'private-root-sentinel');
    expect(json_encode($this->operation->fresh()->toArray()))->not->toContain('private-root-sentinel', 'private-owner-sentinel');
    $this->assertModelExists($this->connection);
    $this->postJson($this->path.'/result', $this->finished)->assertOk();
    $this->assertModelMissing($this->connection);
    $this->assertModelExists($this->server);
    $this->postJson($this->path.'/result', $this->finished)->assertOk();
});

it('refuses a changed connection or newly shared user before any native action', function (): void {
    $this->connection->update(['database' => 'foreign']);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertUnprocessable();
    $this->connection->update(['database' => 'proof']);
    DatabaseConnection::query()->create(['slug' => 'other', 'driver' => 'mysql', 'database_server_id' => $this->server->id, 'database' => 'other', 'username' => 'proof']);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertConflict()->assertJsonPath('error.code', 'database.user_shared');
    $this->assertModelExists($this->connection);
});

it('keeps records on a bounded refusal and rejects a different intent owner', function (): void {
    $this->postJson($this->path.'/result', [...$this->finished, 'status' => 'failed', 'error_code' => 'database.in_use', 'result' => ['error_code' => 'database.in_use']])->assertOk();
    $this->assertModelExists($this->connection);
    $this->operation->update(['node_id' => $this->gateway->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    config(['operation_executor.worker_node_ids' => [$this->gateway->id]]);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertUnprocessable();
});

it('admits one parent legacy server batch with each ordered inventory query grouped', function (): void {
    $this->connection->update(['test_database' => 'proof_testing']);
    $other = DatabaseConnection::query()->create(['slug' => 'other', 'driver' => 'mysql', 'database_server_id' => $this->server->id,
        'database' => 'other', 'username' => 'other', 'test_database' => 'other_testing']);
    $other->forceFill(['ownership_adoption' => ['databases' => ['other'], 'prefix' => null, 'username' => 'other', 'server_id' => $this->server->id, 'node_id' => $this->node->id]])->save();
    $prepared = app(DropServerDatabaseAction::class)->prepareMany([$this->connection, $other]);
    expect($prepared)->toHaveCount(1);
    $sql = DatabaseExecutionIntent::recorded($prepared[0]->input['intent_id'])->payload['commands']['database-apply']['input']['inventory_sql'];
    expect($sql)->toContain('ORDER BY SCHEMA_NAME) UNION (SELECT')->not->toContain('ORDER BY SCHEMA_NAME UNION');
});
