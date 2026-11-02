<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Activity;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use Tests\Support\FakeRoutePublicationSocket;

const DATABASE_INSPECTION_SECRET = 'db-query-secret-6c2e';

beforeEach(function (): void {
    $this->node = $this->markAsGateway(Node::query()->forceCreate(['id' => 14, 'name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'amd64', 'public_ssh_host' => '192.0.2.1', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.1']));
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $keys = ['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAINqVuK+N4gL/sDvGNKnGrYhEtEFnZx9hvH+134PsQgND'];
    $this->keys = $keys;
    // External databases are read from 209, the Swamp control Node.
    $this->control = Node::query()->forceCreate(['id' => 209, 'name' => 'swamp', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'amd64', 'public_ssh_host' => '192.0.2.209', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.209', ...$keys]);
    $this->control->accessibleNodes()->attach($this->node);
    config(['swamp.run_token' => 'fake.token', 'swamp.ca_path' => '/public/ca.pem']);
    $this->runId = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $socket = $this->socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $sent): array => $sent['type'] === 'vault.put'
        ? [['type' => 'vault.put', 'id' => $sent['id'], 'payload' => ['data' => [
            'vaultName' => $sent['payload']['vaultName'], 'secretKey' => $sent['payload']['key'], 'vaultType' => 'local_encryption',
        ]]]]
        : [['type' => 'event', 'id' => $sent['id'], 'event' => ['kind' => 'started', 'workflowName' => $sent['payload']['workflowIdOrName'], 'runId' => $this->runId]]];
    app()->instance(WebSocketClient::class, $socket);
});

it('reads an external database on 209 itself with the password and statement in one vault value, never in the run input', function (string $driver, string $client): void {
    $connection = DatabaseConnection::query()->create(['slug' => 'app', 'driver' => $driver, 'host' => 'db.example.invalid', 'port' => 6543, 'database' => 'app', 'username' => 'private-user', 'password' => DATABASE_INSPECTION_SECRET]);
    $sql = "SELECT email FROM users WHERE token = '".DATABASE_INSPECTION_SECRET."'";
    $response = $this->postJson('/api/v1/database-connections/app/query', ['sql' => $sql])->assertAccepted()->assertJsonPath('data.kind', 'database.read')->assertDontSee(DATABASE_INSPECTION_SECRET);
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    expect($operation->node_id)->toBe(209);
    $copy = $this->socket->sent[0]['payload']['value'];
    expect($copy)->toStartWith(DATABASE_INSPECTION_SECRET."\n")->toContain($sql.";\n", 'READ ONLY');
    $inputs = $this->socket->sent[1]['payload']['inputs'];
    expect($inputs['execution_secrets'])->toBe(['read' => ['vault' => 'orbit-control-inputs', 'key' => 'database-read-intent-'.$operation->input['intent_id']]])
        ->and($inputs['intent']['argv'])->toBe([])
        ->and($inputs['intent']['local'])->toMatchArray(['user' => 'private-user', 'database' => 'app', 'host' => 'db.example.invalid', 'port' => '6543'])
        ->and($inputs['intent']['local']['client'])->toContain($client)->not->toContain('docker');
    expect(json_encode($inputs, JSON_THROW_ON_ERROR))->not->toContain(DATABASE_INSPECTION_SECRET);
    $activity = Activity::query()->where('request_id', $response->json('meta.request_id'))->sole();
    expect($activity->command)->toBe('database:query')
        ->and(json_encode($activity->properties?->toArray() ?? [], JSON_THROW_ON_ERROR))->not->toContain(DATABASE_INSPECTION_SECRET);
    $connection->update(['host' => 'changed.invalid', 'password' => 'changed-password']);
    app(DirectOperation::class)->workflowInputs($operation);
    expect($this->socket->sent[0]['payload']['value'])->toBe($copy);
    expect(array_column($this->socket->sent, 'type'))->toBe(['vault.put', 'workflow.run']);
})->with([['mysql', 'mysql --protocol=TCP'], ['pgsql', 'psql --no-psqlrc']]);

it('reads a database on a Database server inside its container as the connection user', function (): void {
    $server = Node::query()->create(['name' => 'database', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'amd64', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.12', 'wireguard_ip' => '10.44.0.12', ...$this->keys]);
    $server->accessibleNodes()->attach($this->node);
    $process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $server->id, 'name' => 'proof', 'runtime' => 'docker', 'runtime_config' => [], 'restart_policy' => 'unless-stopped', 'desired_state' => 'running', 'status' => 'active']);
    $databaseServer = DatabaseServer::query()->create(['slug' => 'proof', 'driver' => 'mysql', 'node_id' => $server->id, 'process_id' => $process->id, 'tag' => '8.4', 'port' => 3306, 'root_password' => 'private-root-sentinel', 'status' => 'active']);
    DatabaseConnection::query()->create(['slug' => 'app', 'driver' => 'mysql', 'node_id' => $server->id, 'database_server_id' => $databaseServer->id, 'host' => '10.44.0.12', 'database' => 'app', 'username' => 'owner', 'password' => 'private-owner-sentinel']);
    $operation = Operation::query()->findOrFail($this->getJson('/api/v1/database-connections/app/tables')->assertAccepted()->json('data.id'));
    expect($operation->node_id)->toBe($server->id);
    $argv = $this->socket->sent[1]['payload']['inputs']['intent']['argv'];
    expect(array_slice($argv, 0, 8))->toBe(['timeout', '30', 'sudo', '-n', 'docker', 'exec', '-i', 'orbit-process-'.$process->id.'-proof'])
        ->and($argv)->toContain('owner', 'app', '127.0.0.1', '3306');
    expect($this->socket->sent[0]['payload']['value'])->toStartWith("private-owner-sentinel\n")->toContain('information_schema.TABLES')
        ->and(json_encode($this->socket->sent[1]['payload']['inputs'], JSON_THROW_ON_ERROR))->not->toContain('private-owner-sentinel', 'private-root-sentinel');
});

it('refuses a read that calls a function or verb outside the read policy before any run', function (string $driver, string $sql): void {
    DatabaseConnection::query()->create(['slug' => 'app', 'driver' => $driver, 'host' => 'db.invalid', 'database' => 'app', 'username' => 'app']);
    $this->postJson('/api/v1/database-connections/app/query', ['sql' => $sql])->assertUnprocessable()->assertJsonPath('error.code', 'database.query_refused');
    expect(Operation::query()->count())->toBe(0);
})->with([['pgsql', 'SELECT pg_sleep(10)'], ['pgsql', 'SELECT pg_terminate_backend(1)'], ['mysql', 'SELECT SLEEP(10)'], ['mysql', 'SELECT 1 # comment']]);

it('keeps write gating and stacked SQL refusal before native admission', function (): void {
    DatabaseConnection::query()->create(['slug' => 'app', 'driver' => 'mysql', 'host' => 'db.invalid', 'database' => 'app', 'username' => 'app']);
    $this->postJson('/api/v1/database-connections/app/query', ['sql' => 'DELETE FROM users'])->assertUnprocessable()->assertJsonPath('error.code', 'database.write_required');
    $this->postJson('/api/v1/database-connections/app/query', ['sql' => 'SELECT 1; DELETE FROM users'])->assertUnprocessable()->assertJsonPath('error.code', 'database.sql_multiple_statements');
    expect(Operation::query()->count())->toBe(0);
    $this->postJson('/api/v1/database-connections/app/query', ['sql' => 'DELETE FROM users', 'write' => true])->assertAccepted()->assertJsonPath('data.kind', 'database.write');
});

it('starts table schema and describe reads as native Operations and records their shaped rows', function (string $suffix, string $mode, string $output, array $result): void {
    DatabaseConnection::query()->create(['slug' => 'app', 'driver' => 'pgsql', 'host' => 'db.invalid', 'database' => 'app', 'username' => 'app']);
    $response = $this->getJson('/api/v1/database-connections/app/'.$suffix)->assertAccepted()->assertJsonPath('data.kind', 'database.read');
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    $path = '/api/v1/direct-operations/'.$operation->id;
    $this->withServerVariables(['REMOTE_ADDR' => $this->control->wireguard_ip]);
    $this->postJson($path.'/result', ['run_id' => $this->runId, 'status' => 'succeeded', 'started_at' => '2026-10-31T08:00:00.000Z', 'finished_at' => '2026-10-31T08:00:01.000Z',
        'result' => ['steps' => ['Run the read' => ['status' => 'succeeded', 'exit_code' => 0]], 'output' => $output]])->assertOk();
    expect($operation->fresh()->result)->toBe($result);
})->with([
    ['tables', 'tables', "name\nusers\n", ['tables' => ['users']]],
    ['schema', 'schema', "table_name,name,type,nullable,default_value,is_primary\nusers,id,integer,f,,t\n", ['tables' => [['name' => 'users', 'columns' => [['name' => 'id', 'type' => 'integer', 'nullable' => false, 'default' => '', 'primary' => true]]]]]],
    ['describe/users', 'describe', "table_name,name,type,nullable,default_value,is_primary\n", ['error_code' => 'database.table_missing']],
]);

it('places SQLite reads on the owning Node as the file owner with a read-only, safe sqlite3', function (): void {
    $worker = Node::query()->create(['name' => 'worker', 'public_ssh_host' => '192.0.2.9', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'amd64', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.9', ...$this->keys]);
    $worker->accessibleNodes()->attach($this->node);
    DatabaseConnection::query()->create(['slug' => 'local', 'driver' => 'sqlite', 'node_id' => $worker->id, 'path' => '/tmp/native-read.sqlite']);
    $operation = Operation::query()->findOrFail($this->getJson('/api/v1/database-connections/local/tables')->assertAccepted()->json('data.id'));
    expect($operation->node_id)->toBe($worker->id);
    $argv = $this->socket->sent[1]['payload']['inputs']['intent']['argv'];
    expect(array_slice($argv, 0, 4))->toBe(['sudo', '-n', '-u', 'orbit'])
        ->and(implode(' ', $argv))->toContain('sqlite3 -readonly -safe', '/tmp/native-read.sqlite');
    expect($this->socket->sent[0]['payload']['value'])->toStartWith(".timeout 10000\n")->toContain('sqlite_master');
    $this->withServerVariables(['REMOTE_ADDR' => $worker->wireguard_ip]);
    $this->postJson('/api/v1/direct-operations/'.$operation->id.'/result', ['run_id' => $this->runId, 'status' => 'failed', 'error_code' => 'swamp.step_failed', 'started_at' => '2026-10-31T08:00:00.000Z', 'finished_at' => '2026-10-31T08:00:01.000Z',
        'result' => ['steps' => ['Run the read' => ['status' => 'failed', 'exit_code' => 1]]]])->assertOk();
    expect($operation->fresh()->error_code)->toBe('database.query_failed');
});

it('refuses sqlite inspection without an associated node and unknown slugs', function (): void {
    $this->postJson('/api/v1/database-connections', [
        'slug' => 'local',
        'driver' => 'sqlite',
        'path' => '/var/lib/app/database.sqlite',
    ])->assertCreated();

    $this->postJson('/api/v1/database-connections/local/query', [
        'sql' => 'SELECT 1',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'database.sqlite_node_required');

    $this->postJson('/api/v1/database-connections/missing/query', [
        'sql' => 'SELECT 1',
    ])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'http.404');

    expect(DatabaseConnection::query()->where('slug', 'missing')->exists())->toBeFalse();
});

it('refuses query, tables, schema, and describe against a redis connection', function (): void {
    $this->postJson('/api/v1/database-connections', [
        'slug' => 'cache',
        'driver' => 'redis',
        'host' => 'redis.example.test',
    ])->assertCreated();

    $this->postJson('/api/v1/database-connections/cache/query', [
        'sql' => 'SELECT 1',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'database.driver_unsupported');

    $this->getJson('/api/v1/database-connections/cache/tables')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'database.driver_unsupported');

    $this->getJson('/api/v1/database-connections/cache/schema')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'database.driver_unsupported');

    $this->getJson('/api/v1/database-connections/cache/describe/users')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'database.driver_unsupported');
});

it('replays keyed reads and writes using their declared request kind', function (): void {
    DatabaseConnection::query()->create(['slug' => 'app', 'driver' => 'pgsql', 'host' => 'db.invalid', 'database' => 'app', 'username' => 'app']);
    foreach ([false, true] as $write) {
        $body = ['sql' => 'SELECT 1', 'write' => $write];
        $headers = ['Idempotency-Key' => 'same-key-separate-kinds'];
        $first = $this->postJson('/api/v1/database-connections/app/query', $body, $headers)->assertAccepted()->assertJsonPath('data.kind', $write ? 'database.write' : 'database.read');
        $again = $this->postJson('/api/v1/database-connections/app/query', $body, $headers)->assertAccepted();
        expect($again->json('data.id'))->toBe($first->json('data.id'));
    }
    expect(Operation::query()->count())->toBe(2);
});

it('projects the declared terminal read output once without repeating a query', function (string $status, array $result): void {
    DatabaseConnection::query()->create(['slug' => 'app', 'driver' => 'pgsql', 'host' => 'db.invalid', 'database' => 'app', 'username' => 'app']);
    $response = $this->getJson('/api/v1/database-connections/app/tables')->assertAccepted();
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    $inputs = $this->socket->sent[1]['payload']['inputs'];
    $history = ['id' => $this->runId, 'workflowName' => 'operation-database-read', 'status' => $status,
        'inputs' => $inputs, 'startedAt' => '2026-10-31T12:00:00Z', 'completedAt' => '2026-10-31T12:00:01Z',
        'jobs' => [['name' => 'read', 'steps' => [
            ['name' => 'Run the read', 'status' => 'skipped', 'outputs' => []],
            ['name' => 'Run the read on 209', 'status' => $status, 'outputs' => ['stdout' => "name\nproof\n", 'exitCode' => $status === 'succeeded' ? 0 : 1]],
        ]]],
    ];
    $this->socket->reply = static fn (array $sent): array => [['type' => 'workflow.history.get', 'id' => $sent['id'], 'payload' => ['data' => $history]]];
    config(['swamp.read_token' => 'fake.token']);
    $terminal = ['id' => $this->runId, 'status' => $status];
    $selector = app(DirectOperation::class)->definition('database.read')['terminal_result'];
    $projected = app(SwampCommandStep::class)->projectTerminal($operation, $terminal, $selector);
    app(SwampCommandStep::class)->projectTerminal($projected, $terminal, $selector);
    expect($projected->status->value)->toBe($status);
    expect($projected->result)->toBe($result);
    expect(array_column($this->socket->sent, 'type'))->toBe(['vault.put', 'workflow.run', 'workflow.history.get']);
})->with([
    ['succeeded', ['tables' => ['proof']]],
    ['failed', ['error_code' => 'database.query_failed']],
]);
