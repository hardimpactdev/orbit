<?php

declare(strict_types=1);

use App\Actions\DatabaseConnections\QueryDatabaseConnectionAction;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\DatabaseConnection;
use App\Models\DatabaseExecutionIntent;
use App\Models\Node;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->gateway = $this->markAsGateway(Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'amd64', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1']));
    $this->node = Node::query()->create(['name' => 'database-dev', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'amd64', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9']);
    $this->node->accessibleNodes()->attach($this->gateway);
    config(['operation_executor.worker_node_ids' => [$this->node->id], 'swamp.run_token' => 'fake.token']);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->connection = DatabaseConnection::query()->create(['slug' => 'write-proof', 'driver' => 'sqlite', 'node_id' => $this->node->id, 'path' => '/tmp/write-proof.sqlite']);
    $this->runId = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $sent): array => [['type' => 'event', 'id' => $sent['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-database-write', 'runId' => $this->runId]]];
    app()->instance(WebSocketClient::class, $socket);
    $this->operation = app(QueryDatabaseConnectionAction::class)->execute($this->connection, 'CREATE TABLE private_sql_sentinel (id integer)', true);
    $this->path = '/api/v1/direct-operations/'.$this->operation->id;
    $this->finished = ['run_id' => $this->runId, 'status' => 'succeeded', 'started_at' => '2026-10-30T19:00:00.000Z', 'finished_at' => '2026-10-30T19:00:01.000Z', 'result' => ['backend' => 'active', 'row_count' => 0, 'truncated' => false]];
});

it('starts direct SQLite writes with only fixed private references and the safe file identity', function (): void {
    expect($this->operation->lock_key)->toBeNull();
    $this->getJson($this->path.'?run_id='.$this->runId)->assertOk()->assertJsonPath('data.input.path', '/tmp/write-proof.sqlite')
        ->assertJsonMissingPath('data.input.sql')->assertJsonMissingPath('data.input.agent_sha256')->assertDontSee('private_sql_sentinel');
    $this->getJson($this->path.'/private-material?run_id='.$this->runId)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.sql', 'CREATE TABLE private_sql_sentinel (id integer)')->assertJsonPath('data.password', '')->assertJsonPath('data.username', null);
    $intent = DatabaseExecutionIntent::recorded($this->operation->input['intent_id']);
    $payload = $intent->payload;
    unset($payload['commands']['database-apply']['protected']);
    $intent->update(['payload' => $payload]);
    $this->postJson($this->path.'/result', [...$this->finished, 'result' => [...$this->finished['result'], 'rows' => [['private' => 'discarded']]]])->assertOk();
    expect($this->operation->fresh()->result)->toBe($this->finished['result']);
    $this->getJson($this->path.'/private-material?run_id='.$this->runId)->assertConflict();
});

it('keeps the admitted SQLite path and records the bounded native refusal', function (): void {
    $this->connection->update(['path' => '/tmp/foreign.sqlite']);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertOk()->assertJsonPath('data.input.path', '/tmp/write-proof.sqlite');
    $this->postJson($this->path.'/result', [...$this->finished, 'status' => 'failed', 'error_code' => 'database.write_outcome_unknown', 'result' => ['error_code' => 'database.write_outcome_unknown']])->assertOk();
});

it('keeps PDO connection material private on the exact admitted Gateway worker', function (string $driver): void {
    $connection = DatabaseConnection::query()->create(['slug' => 'network', 'driver' => $driver, 'host' => '192.0.2.8', 'database' => 'proof', 'username' => 'private-user', 'password' => 'private-password']);
    $operation = app(QueryDatabaseConnectionAction::class)->execute($connection, 'CREATE TABLE private_write (id integer)', true);
    expect($operation->node_id)->toBe($this->gateway->id);
    config(['operation_executor.worker_node_ids' => [$this->gateway->id, $this->node->id]]);
    $path = '/api/v1/direct-operations/'.$operation->id;
    $this->getJson($path.'/private-material?run_id='.$this->runId)->assertForbidden();
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $public = $this->getJson($path.'?run_id='.$this->runId)->assertOk()->json('data.input');
    expect($public)->toBe(['driver' => $driver]);
    expect($operation->input)->not->toHaveKey('agent_sha256');
    $this->getJson($path.'/private-material?run_id='.$this->runId)->assertOk()->assertJsonPath('data.password', 'private-password')->assertJsonPath('data.username', 'private-user');
})->with(['mysql', 'pgsql']);

it('keeps admitted connection material when the connection record changes', function (string $field): void {
    $connection = DatabaseConnection::query()->create(['slug' => 'network', 'driver' => 'pgsql', 'host' => '192.0.2.8', 'database' => 'proof', 'username' => 'owner', 'password' => 'private-password']);
    $operation = app(QueryDatabaseConnectionAction::class)->execute($connection, 'UPDATE proof SET value = 17', true);
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $connection->update([$field => $field === 'node_id' ? $this->node->id : '192.0.2.99']);
    $path = '/api/v1/direct-operations/'.$operation->id;
    $this->getJson($path.'?run_id='.$this->runId)->assertOk()->assertJsonPath('data.input', ['driver' => 'pgsql']);
    $this->getJson($path.'/private-material?run_id='.$this->runId)->assertOk()->assertJsonPath('data.sql', 'UPDATE proof SET value = 17')->assertJsonPath('data.password', 'private-password');
})->with(['host', 'node_id']);

it('uses the bounded domain result to omit nested values under declared scalar fields', function (string $field): void {
    $result = [...$this->finished['result'], $field => ['private' => 'nested-private-sentinel']];
    $this->postJson($this->path.'/result', [...$this->finished, 'result' => $result])->assertOk()->assertDontSee('nested-private-sentinel');
    unset($result[$field]);
    expect($this->operation->fresh()->result)->toBe($result);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertOk()->assertDontSee('nested-private-sentinel');
})->with(['backend', 'error_code', 'row_count', 'truncated']);
