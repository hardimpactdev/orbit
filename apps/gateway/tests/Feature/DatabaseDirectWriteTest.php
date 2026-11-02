<?php

declare(strict_types=1);

use App\Actions\DatabaseConnections\QueryDatabaseConnectionAction;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\DatabaseConnection;
use App\Models\Node;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $keys = ['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG'];
    $this->gateway = $this->markAsGateway(Node::query()->forceCreate(['id' => 14, 'name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'amd64', 'user' => 'orbit', ...$keys, 'public_ssh_host' => '192.0.2.14', 'wireguard_ip' => '10.44.0.14']));
    $this->node = Node::query()->forceCreate(['id' => 9, 'name' => 'database-dev', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'amd64', 'user' => 'orbit', ...$keys, 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9']);
    $this->control = Node::query()->forceCreate(['id' => 209, 'name' => 'swamp', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'amd64', 'user' => 'orbit', ...$keys, 'public_ssh_host' => '192.0.2.209', 'wireguard_ip' => '10.44.0.209']);
    $this->node->accessibleNodes()->attach($this->gateway);
    config(['swamp.run_token' => 'fake.token', 'swamp.read_token' => 'fake.token', 'swamp.ca_path' => '/public/ca.pem']);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->runId = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $socket = $this->socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $sent): array => $sent['type'] === 'vault.put'
        ? [['type' => 'vault.put', 'id' => $sent['id'], 'payload' => ['data' => ['vaultName' => $sent['payload']['vaultName'], 'secretKey' => $sent['payload']['key'], 'vaultType' => 'local_encryption']]]]
        : [['type' => 'event', 'id' => $sent['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-database-write', 'runId' => $this->runId]]];
    app()->instance(WebSocketClient::class, $socket);
    $this->connection = DatabaseConnection::query()->create(['slug' => 'write-proof', 'driver' => 'sqlite', 'node_id' => $this->node->id, 'path' => '/tmp/write-proof.sqlite']);
    $this->operation = app(QueryDatabaseConnectionAction::class)->execute($this->connection, 'CREATE TABLE private_sql_sentinel (id integer)', true);
    $this->path = '/api/v1/direct-operations/'.$this->operation->id;
    $this->finished = ['run_id' => $this->runId, 'status' => 'succeeded', 'started_at' => '2026-10-30T19:00:00.000Z', 'finished_at' => '2026-10-30T19:00:01.000Z'];
});

it('writes SQLite as the file owner with sqlite3 -safe and the statement only in its vault copy', function (): void {
    expect($this->operation->node_id)->toBe($this->node->id);
    $copy = $this->socket->sent[0]['payload']['value'];
    $inputs = $this->socket->sent[1]['payload']['inputs'];
    expect($copy)->toBe(".timeout 10000\nCREATE TABLE private_sql_sentinel (id integer);\nSELECT changes() AS row_count;\n")
        ->and(array_slice($inputs['intent']['argv'], 0, 4))->toBe(['sudo', '-n', '-u', 'orbit'])
        ->and(implode(' ', $inputs['intent']['argv']))->toContain('sqlite3 -safe', '/tmp/write-proof.sqlite')->not->toContain('-readonly')
        ->and($inputs['execution_secrets'])->toBe(['write' => ['vault' => 'orbit-control-inputs', 'key' => 'database-write-intent-'.$this->operation->input['intent_id']]])
        ->and(json_encode($inputs))->not->toContain('private_sql_sentinel');
});

it('records the affected row count from the client output and nothing else', function (): void {
    $this->postJson($this->path.'/result', [...$this->finished, 'result' => ['steps' => ['Run the statement' => ['status' => 'succeeded', 'exit_code' => 0]],
        'output' => "private,discarded\nrow_count\n3\n"]])->assertOk();
    expect($this->operation->fresh()->result)->toBe(['backend' => 'active', 'row_count' => 3, 'truncated' => false]);
});

it('fails a write whose client failed, or whose output it cannot read', function (string $status, array $result, string $code): void {
    $this->postJson($this->path.'/result', [...$this->finished, 'status' => $status, 'error_code' => $status === 'failed' ? 'swamp.step_failed' : null, 'result' => $result])->assertOk();
    expect($this->operation->fresh()->status)->toBe(OperationStatus::Failed)->and($this->operation->fresh()->error_code)->toBe($code);
})->with([
    ['failed', ['steps' => ['Run the statement' => ['status' => 'failed']]], 'database.query_failed'],
    ['succeeded', ['steps' => ['Run the statement' => ['status' => 'succeeded']], 'output' => 'not a count'], 'database.write_outcome_unknown'],
]);

it('writes an external database on 209 with the password line and statement in one vault value', function (string $driver, string $tail): void {
    $connection = DatabaseConnection::query()->create(['slug' => 'external-'.$driver, 'driver' => $driver, 'host' => 'db.example.invalid', 'port' => 6543, 'database' => 'app', 'username' => 'writer', 'password' => 'private-write-sentinel']);
    $this->socket->sent = [];
    $operation = app(QueryDatabaseConnectionAction::class)->execute($connection, 'DELETE FROM users WHERE id = 1', true);
    $inputs = collect($this->socket->sent)->firstWhere('type', 'workflow.run')['payload']['inputs'];
    expect($operation->node_id)->toBe(209)
        ->and($this->socket->sent[0]['payload']['value'])->toStartWith("private-write-sentinel\n")->toEndWith($tail)
        ->and($inputs['intent']['argv'])->toBe([])
        ->and($inputs['intent']['local'])->toMatchArray(['user' => 'writer', 'database' => 'app', 'host' => 'db.example.invalid', 'port' => '6543'])
        ->and(json_encode($inputs))->not->toContain('private-write-sentinel');
})->with([['mysql', "DELETE FROM users WHERE id = 1;\nSELECT ROW_COUNT() AS row_count;\n"], ['pgsql', "DELETE FROM users WHERE id = 1;\n"]]);

it('projects the native terminal output once without submitting another write', function (): void {
    $inputs = $this->socket->sent[1]['payload']['inputs'];
    $history = ['id' => $this->runId, 'workflowName' => 'operation-database-write', 'status' => 'succeeded', 'inputs' => $inputs,
        'startedAt' => '2026-10-31T12:00:00Z', 'completedAt' => '2026-10-31T12:00:01Z',
        'jobs' => [['name' => 'write', 'steps' => [['name' => 'Run the statement', 'status' => 'succeeded', 'outputs' => ['stdout' => "row_count\n0\n", 'exitCode' => 0]],
            ['name' => 'Run the statement on 209', 'status' => 'skipped', 'outputs' => []]]]]];
    $this->socket->reply = static fn (array $sent): array => [['type' => 'workflow.history.get', 'id' => $sent['id'], 'payload' => ['data' => $history]]];
    $this->socket->sent = [];
    $selector = app(DirectOperation::class)->definition('database.write')['terminal_result'];
    $projected = app(SwampCommandStep::class)->projectTerminal($this->operation, ['id' => $this->runId, 'status' => 'succeeded'], $selector);
    app(SwampCommandStep::class)->projectTerminal($projected, ['id' => $this->runId, 'status' => 'succeeded'], $selector);
    expect($projected->result)->toBe(['backend' => 'active', 'row_count' => 0, 'truncated' => false])
        ->and(array_column($this->socket->sent, 'type'))->toBe(['workflow.history.get']);
});
