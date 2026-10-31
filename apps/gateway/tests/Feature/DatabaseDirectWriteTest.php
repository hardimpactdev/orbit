<?php

declare(strict_types=1);

use App\Actions\DatabaseConnections\QueryDatabaseConnectionAction;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\DatabaseConnection;
use App\Models\DatabaseExecutionIntent;
use App\Models\Node;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->gateway = $this->markAsGateway(Node::query()->forceCreate(['id' => 14, 'name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'amd64', 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG', 'public_ssh_host' => '192.0.2.14', 'wireguard_ip' => '10.44.0.14']));
    $this->node = Node::query()->forceCreate(['id' => 9, 'name' => 'database-dev', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'amd64', 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9']);
    $this->ssh = fn (Node $node): array => ['host' => $node->wireguard_ip, 'user' => $node->user, 'host_keys' => [$node->ssh_host_key_type.' '.$node->ssh_host_key]];
    $this->node->accessibleNodes()->attach($this->gateway);
    config(['swamp.run_token' => 'fake.token', 'swamp.read_token' => 'fake.token', 'swamp.ca_path' => '/public/ca.pem']);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->connection = DatabaseConnection::query()->create(['slug' => 'write-proof', 'driver' => 'sqlite', 'node_id' => $this->node->id, 'path' => '/tmp/write-proof.sqlite']);
    $this->runId = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $this->copies = [];
    $socket = $this->socket = new FakeRoutePublicationSocket;
    $socket->reply = function (array $sent): array {
        if ($sent['type'] === 'vault.put') {
            $this->copies[] = json_decode($sent['payload']['value'], true, flags: JSON_THROW_ON_ERROR);

            return [['type' => 'vault.put', 'id' => $sent['id'], 'payload' => ['data' => [
                'vaultName' => $sent['payload']['vaultName'], 'secretKey' => $sent['payload']['key'], 'vaultType' => 'local_encryption',
            ]]]];
        }

        return [['type' => 'event', 'id' => $sent['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-database-write', 'runId' => $this->runId]]];
    };
    app()->instance(WebSocketClient::class, $socket);
    $this->operation = app(QueryDatabaseConnectionAction::class)->execute($this->connection, 'CREATE TABLE private_sql_sentinel (id integer)', true);
    $this->path = '/api/v1/direct-operations/'.$this->operation->id;
    $this->finished = ['run_id' => $this->runId, 'status' => 'succeeded', 'started_at' => '2026-10-30T19:00:00.000Z', 'finished_at' => '2026-10-30T19:00:01.000Z', 'result' => ['backend' => 'active', 'row_count' => 0, 'truncated' => false]];
});

it('starts direct SQLite writes with only fixed private references and the safe file identity', function (): void {
    expect($this->operation->lock_key)->toBeNull();
    $this->getJson($this->path.'?run_id='.$this->runId)->assertOk()->assertJsonPath('data.input.intent.path', '/tmp/write-proof.sqlite')
        ->assertJsonMissingPath('data.input.intent.sql')->assertJsonMissingPath('data.input.intent.agent_sha256')->assertDontSee('private_sql_sentinel');
    $this->getJson($this->path.'/private-material?run_id='.$this->runId)->assertUnprocessable();
    $inputs = $this->socket->sent[1]['payload']['inputs'];
    expect($inputs['intent'])->toBe(['connection_id' => $this->connection->id, 'driver' => 'sqlite', 'ssh' => ($this->ssh)($this->node), 'owner' => 'orbit', 'path' => '/tmp/write-proof.sqlite']);
    expect($inputs['execution_secrets'])->toBe(['database_write' => ['vault' => 'orbit-control-inputs', 'key' => 'database-write-intent-'.$this->operation->input['intent_id']]]);
    expect(json_encode($inputs))->not->toContain('private_sql_sentinel');
    expect($this->copies)->toHaveCount(1);
    expect($this->copies[0])->toMatchArray(['password' => '', 'sql' => 'CREATE TABLE private_sql_sentinel (id integer)', 'dsn' => '', 'username' => null]);
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
    $this->getJson($this->path.'?run_id='.$this->runId)->assertOk()->assertJsonPath('data.input.intent.path', '/tmp/write-proof.sqlite');
    $this->postJson($this->path.'/result', [...$this->finished, 'status' => 'failed', 'error_code' => 'database.write_outcome_unknown', 'result' => ['error_code' => 'database.write_outcome_unknown']])->assertOk();
});

it('keeps PDO connection material private on the exact admitted Gateway worker', function (string $driver): void {
    $connection = DatabaseConnection::query()->create(['slug' => 'network', 'driver' => $driver, 'host' => '192.0.2.8', 'database' => 'proof', 'username' => 'private-user', 'password' => 'private-password']);
    $operation = app(QueryDatabaseConnectionAction::class)->execute($connection, 'CREATE TABLE private_write (id integer)', true);
    expect($operation->node_id)->toBe($this->gateway->id);
    $path = '/api/v1/direct-operations/'.$operation->id;
    $this->getJson($path.'/private-material?run_id='.$this->runId)->assertForbidden();
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $public = $this->getJson($path.'?run_id='.$this->runId)->assertOk()->json('data.input');
    expect($public['intent'])->toBe(['connection_id' => $connection->id, 'driver' => $driver, 'ssh' => ($this->ssh)($this->gateway)]);
    expect($operation->input)->not->toHaveKey('agent_sha256');
    $this->getJson($path.'/private-material?run_id='.$this->runId)->assertUnprocessable();
    $inputs = app(DirectOperation::class)->workflowInputs($operation);
    expect($inputs['node_id'])->toBe($this->gateway->id);
    expect($inputs['intent'])->toBe(['connection_id' => $connection->id, 'driver' => $driver, 'ssh' => ($this->ssh)($this->gateway)]);
    expect(json_encode($inputs))->not->toContain('private-user', 'private-password', 'private_write');
    expect($this->copies[array_key_last($this->copies)])->toMatchArray(['password' => 'private-password', 'username' => 'private-user']);
})->with(['mysql', 'pgsql']);

it('keeps admitted connection material when the connection record changes', function (string $field): void {
    $connection = DatabaseConnection::query()->create(['slug' => 'network', 'driver' => 'pgsql', 'host' => '192.0.2.8', 'database' => 'proof', 'username' => 'owner', 'password' => 'private-password']);
    $operation = app(QueryDatabaseConnectionAction::class)->execute($connection, 'UPDATE proof SET value = 17', true);
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $connection->update([$field => $field === 'node_id' ? $this->node->id : '192.0.2.99']);
    $path = '/api/v1/direct-operations/'.$operation->id;
    $this->getJson($path.'?run_id='.$this->runId)->assertOk()->assertJsonPath('data.input.intent', ['connection_id' => $connection->id, 'driver' => 'pgsql', 'ssh' => ($this->ssh)($this->gateway)]);
    app(DirectOperation::class)->workflowInputs($operation);
    expect($this->copies[array_key_last($this->copies)])->toMatchArray(['sql' => 'UPDATE proof SET value = 17', 'password' => 'private-password']);
})->with(['host', 'node_id']);

it('uses the bounded domain result to omit nested values under declared scalar fields', function (string $field): void {
    $result = [...$this->finished['result'], $field => ['private' => 'nested-private-sentinel']];
    $this->postJson($this->path.'/result', [...$this->finished, 'result' => $result])->assertOk()->assertDontSee('nested-private-sentinel');
    unset($result[$field]);
    expect($this->operation->fresh()->result)->toBe($result);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertOk()->assertDontSee('nested-private-sentinel');
})->with(['backend', 'error_code', 'row_count', 'truncated']);

it('admits light Node SQLite writes through the same official SSH target descriptor', function (int $nodeId): void {
    $node = Node::query()->forceCreate(['id' => $nodeId, 'name' => 'light-'.$nodeId, 'wireguard_ip' => '10.44.0.'.$nodeId,
        'public_ssh_host' => '192.0.2.'.$nodeId, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'amd64',
        'user' => 'orbit', 'ssh_host_key_type' => $this->node->ssh_host_key_type, 'ssh_host_key' => $this->node->ssh_host_key]);
    $connection = DatabaseConnection::query()->create(['slug' => 'light-write', 'driver' => 'sqlite', 'node_id' => $nodeId, 'path' => '/tmp/light.sqlite']);
    $operation = app(QueryDatabaseConnectionAction::class)->execute($connection, 'CREATE TABLE proof (id integer)', true);
    $input = app(DirectOperation::class)->workflowInputs($operation);

    expect($operation->node_id)->toBe($nodeId);
    expect($input['intent']['ssh'])->toBe(($this->ssh)($node));
    expect($input['intent']['path'])->toBe('/tmp/light.sqlite');
})->with([1, 18]);

it('projects the declared terminal stdout once without submitting another write', function (string $status, array $result): void {
    $inputs = $this->socket->sent[1]['payload']['inputs'];
    $history = ['id' => $this->runId, 'workflowName' => 'operation-database-write', 'status' => $status,
        'inputs' => $inputs, 'startedAt' => '2026-10-31T12:00:00Z', 'completedAt' => '2026-10-31T12:00:01Z',
        'jobs' => [['name' => 'write', 'steps' => [
            ['name' => 'sql', 'outputs' => ['stdout' => '{"row_count":999}']],
            ['name' => 'result', 'outputs' => ['stdout' => json_encode([...$result, 'rows' => [['private' => 'discarded']]], JSON_THROW_ON_ERROR)]],
        ]]],
    ];
    $this->socket->reply = static fn (array $sent): array => [['type' => 'workflow.history.get', 'id' => $sent['id'], 'payload' => ['data' => $history]]];
    config(['swamp.ca_path' => '/public/ca.pem']);
    $terminal = ['id' => $this->runId, 'status' => $status];
    $selector = app(DirectOperation::class)->definition('database.write')['terminal_result'];

    $projected = app(SwampCommandStep::class)->projectTerminal($this->operation, $terminal, $selector);
    app(SwampCommandStep::class)->projectTerminal($projected, $terminal, $selector);

    expect($projected->status)->toBe(OperationStatus::from($status));
    expect($projected->result)->toBe($result);
    expect(array_column($this->socket->sent, 'type'))->toBe(['vault.put', 'workflow.run', 'workflow.history.get']);
    expect($this->copies)->toHaveCount(1);
})->with([
    ['succeeded', ['backend' => 'active', 'row_count' => 7, 'truncated' => false]],
    ['failed', ['error_code' => 'database.write_outcome_unknown']],
]);
