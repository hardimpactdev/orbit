<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Activity;
use App\Models\DatabaseConnection;
use App\Models\Node;
use App\Models\Operation;
use Tests\Support\FakeRoutePublicationSocket;

const DATABASE_INSPECTION_SECRET = 'db-query-secret-6c2e';

beforeEach(function (): void {
    $this->node = $this->markAsGateway(Node::query()->forceCreate(['id' => 14, 'name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'amd64', 'public_ssh_host' => '192.0.2.1', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.1']));
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
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

it('records SQL read intent without connecting and keeps SQL credentials private and out of audit input', function (string $driver): void {
    $connection = DatabaseConnection::query()->create(['slug' => 'app', 'driver' => $driver, 'host' => 'unreachable.example.invalid', 'database' => 'app', 'username' => 'private-user', 'password' => DATABASE_INSPECTION_SECRET]);
    $sql = 'SELECT email FROM users WHERE token='.DATABASE_INSPECTION_SECRET;
    $response = $this->postJson('/api/v1/database-connections/app/query', ['sql' => $sql])->assertAccepted()->assertJsonPath('data.kind', 'database.read')->assertJsonPath('data.status', 'running')->assertDontSee(DATABASE_INSPECTION_SECRET);
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    expect($operation->node_id)->toBe($this->node->id);
    $path = '/api/v1/direct-operations/'.$operation->id;
    $this->getJson($path.'?run_id='.$this->runId)->assertOk()->assertJsonPath('data.input.intent.driver', $driver)->assertJsonPath('data.input.intent.mode', 'query')->assertJsonPath('data.input.intent.table', '')->assertDontSee(DATABASE_INSPECTION_SECRET);
    $this->getJson($path.'/private-material?run_id='.$this->runId)->assertUnprocessable();
    $copy = json_decode($this->socket->sent[0]['payload']['value'], true, flags: JSON_THROW_ON_ERROR);
    expect($copy)->toMatchArray(['sql' => $sql, 'password' => DATABASE_INSPECTION_SECRET, 'username' => 'private-user']);
    $inputs = $this->socket->sent[1]['payload']['inputs'];
    expect($inputs['execution_secrets'])->toBe(['database_read' => ['vault' => 'orbit-control-inputs', 'key' => 'database-read-intent-'.$operation->input['intent_id']]]);
    expect(json_encode($inputs, JSON_THROW_ON_ERROR))->not->toContain(DATABASE_INSPECTION_SECRET, 'private-user', $sql);
    $activity = Activity::query()->where('request_id', $response->json('meta.request_id'))->sole();
    expect($activity->command)->toBe('database:query');
    expect(json_encode($activity->properties?->toArray() ?? [], JSON_THROW_ON_ERROR))->not->toContain(DATABASE_INSPECTION_SECRET);
    $connection->update(['host' => 'changed.invalid', 'password' => 'changed-password']);
    app(DirectOperation::class)->workflowInputs($operation);
    expect(json_decode($this->socket->sent[0]['payload']['value'], true, flags: JSON_THROW_ON_ERROR)['password'])->toBe(DATABASE_INSPECTION_SECRET);
    expect(array_column($this->socket->sent, 'type'))->toBe(['vault.put', 'workflow.run']);
})->with(['mysql', 'pgsql']);

it('keeps write gating and stacked SQL refusal before native admission', function (): void {
    DatabaseConnection::query()->create(['slug' => 'app', 'driver' => 'mysql', 'host' => 'db.invalid', 'database' => 'app', 'username' => 'app']);
    $this->postJson('/api/v1/database-connections/app/query', ['sql' => 'DELETE FROM users'])->assertUnprocessable()->assertJsonPath('error.code', 'database.write_required');
    $this->postJson('/api/v1/database-connections/app/query', ['sql' => 'SELECT 1; DELETE FROM users'])->assertUnprocessable()->assertJsonPath('error.code', 'database.sql_multiple_statements');
    expect(Operation::query()->count())->toBe(0);
    $this->postJson('/api/v1/database-connections/app/query', ['sql' => 'DELETE FROM users', 'write' => true])->assertAccepted()->assertJsonPath('data.kind', 'database.write');
});

it('starts table schema and describe reads as native Operations and records their bounded results', function (string $suffix, string $mode, array $result): void {
    DatabaseConnection::query()->create(['slug' => 'app', 'driver' => 'pgsql', 'host' => 'db.invalid', 'database' => 'app', 'username' => 'app']);
    $response = $this->getJson('/api/v1/database-connections/app/'.$suffix)->assertAccepted()->assertJsonPath('data.kind', 'database.read');
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    $path = '/api/v1/direct-operations/'.$operation->id;
    $this->getJson($path.'?run_id='.$this->runId)->assertOk()->assertJsonPath('data.input.intent.mode', $mode);
    $this->postJson($path.'/result', ['run_id' => $this->runId, 'status' => 'succeeded', 'started_at' => '2026-10-31T08:00:00.000Z', 'finished_at' => '2026-10-31T08:00:01.000Z', 'result' => $result])->assertOk();
    expect($operation->fresh()->result)->toBe($result);
})->with([
    ['tables', 'tables', ['tables' => ['users']]],
    ['schema', 'schema', ['tables' => [['name' => 'users', 'columns' => [['name' => 'id', 'type' => 'integer', 'nullable' => false, 'default' => null, 'primary' => true]]]]]],
    ['describe/users', 'describe', ['table' => 'users', 'columns' => [['name' => 'id', 'type' => 'integer', 'nullable' => false, 'default' => null, 'primary' => true]]]],
]);

it('places SQLite reads on the owning worker and refuses private material on another Node', function (): void {
    $worker = Node::query()->create(['name' => 'worker', 'public_ssh_host' => '192.0.2.9', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'amd64', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.9']);
    $worker->accessibleNodes()->attach($this->node);
    DatabaseConnection::query()->create(['slug' => 'local', 'driver' => 'sqlite', 'node_id' => $worker->id, 'path' => '/tmp/native-read.sqlite']);
    $response = $this->getJson('/api/v1/database-connections/local/tables')->assertAccepted();
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    expect($operation->node_id)->toBe($worker->id);
    $path = '/api/v1/direct-operations/'.$operation->id;
    $this->getJson($path.'/private-material?run_id='.$this->runId)->assertForbidden();
    $this->withServerVariables(['REMOTE_ADDR' => $worker->wireguard_ip]);
    $this->getJson($path.'?run_id='.$this->runId)->assertOk()->assertJsonPath('data.input.intent.owner', 'orbit')->assertJsonPath('data.input.intent.path', '/tmp/native-read.sqlite');
    $this->getJson($path.'/private-material?run_id='.$this->runId)->assertUnprocessable();
    $connection = DatabaseConnection::query()->where('slug', 'local')->sole();
    $connection->update(['path' => '/tmp/changed.sqlite']);
    $this->getJson($path.'?run_id='.$this->runId)->assertOk()->assertJsonPath('data.input.intent.path', '/tmp/native-read.sqlite');
    $this->postJson($path.'/result', ['run_id' => $this->runId, 'status' => 'failed', 'error_code' => 'database.sqlite_unreadable', 'started_at' => '2026-10-31T08:00:00.000Z', 'finished_at' => '2026-10-31T08:00:01.000Z', 'result' => ['error_code' => 'database.sqlite_unreadable']])->assertOk();
    expect($operation->fresh()->error_code)->toBe('database.sqlite_unreadable');
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
            ['name' => 'sql', 'outputs' => ['stdout' => '{"tables":["wrong-step"]}']],
            ['name' => 'result', 'outputs' => ['stdout' => json_encode($result, JSON_THROW_ON_ERROR)]],
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
