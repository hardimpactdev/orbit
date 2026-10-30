<?php

declare(strict_types=1);

use App\Actions\DatabaseConnections\CreateDatabaseUserAction;
use App\Actions\DatabaseConnections\CreateServerDatabaseAction;
use App\Actions\DatabaseConnections\DropServerDatabaseAction;
use App\Data\DatabaseConnections\CreateDatabaseUserData;
use App\Data\DatabaseConnections\CreateServerDatabaseData;
use App\Domain\DatabaseServers\DatabaseDestroySafety;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\DatabaseConnection;
use App\Models\DatabaseExecutionIntent;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
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

it('refuses system names and sweeping test prefixes at removal admission', function (string $name, ?string $prefix): void {
    $this->connection->update(['database' => $name, 'test_database' => $prefix]);
    expect(fn () => app(DropServerDatabaseAction::class)->prepareMany([$this->connection]))
        ->toThrow(ResourceOperationException::class, 'System databases and prefixes cannot be removed.');
    $this->assertModelExists($this->connection);
})->with([
    ['mysql', null], ['sys', null], ['performance_schema', null], ['information_schema', null],
    ['postgres', null], ['template0', null], ['template_custom', null], ['MySQL', null],
    ['proof', 'm'], ['proof', 's'], ['proof', 'performance_'], ['proof', 'information_'],
    ['proof', 'p'], ['proof', 'tem'], ['proof', 'template_other'],
]);

it('refuses previously admitted unsafe names and prefixes at the direct read boundary', function (string $field, array $values): void {
    $intent = DatabaseExecutionIntent::recorded($this->operation->input['intent_id']);
    $payload = $intent->payload;
    $payload['admission']['input'][$field] = $values;
    $intent->update(['payload' => $payload]);
    $this->operation->update(['input' => [...$this->operation->input, $field => $values]]);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertUnprocessable()->assertJsonPath('error.code', 'database.system_database');
    $this->getJson($this->path.'/private-material?run_id='.$this->runId)->assertUnprocessable()->assertJsonPath('error.code', 'database.system_database');
})->with([
    ['databases', ['mysql']], ['databases', ['sys']], ['databases', ['performance_schema']],
    ['databases', ['information_schema']], ['databases', ['postgres']], ['databases', ['template0']],
    ['owned_prefixes', ['m']], ['owned_prefixes', ['s']], ['owned_prefixes', ['template']],
]);

it('refuses attached connections and a mismatched Instance owner before removal', function (): void {
    $project = Project::query()->create(['name' => 'Removal proof', 'slug' => 'removal-proof', 'repository_url' => 'https://example.test/proof.git', 'default_branch' => 'main']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id, 'name' => 'dev', 'environment' => 'development', 'checkout_path' => '/srv/proof/dev', 'status' => 'active']);
    $this->connection->targets()->create(['instance_id' => $instance->id, 'prefix' => 'DB', 'network' => 'private']);
    expect(fn () => app(DropServerDatabaseAction::class)->prepareMany([$this->connection]))
        ->toThrow(ResourceOperationException::class, 'Detach the connection before removal.');
    $this->getJson($this->path.'?run_id='.$this->runId)->assertUnprocessable();
    $this->connection->targets()->delete();
    expect(fn () => app(DropServerDatabaseAction::class)->prepareMany([$this->connection], $instance->id))
        ->toThrow(ResourceOperationException::class, 'The connection belongs to a different Instance.');
    $intent = DatabaseExecutionIntent::recorded($this->operation->input['intent_id']);
    $payload = $intent->payload;
    $payload['admission']['input']['owner_instance_id'] = $instance->id;
    $intent->update(['payload' => $payload]);
    $this->operation->update(['input' => [...$this->operation->input, 'owner_instance_id' => $instance->id]]);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertUnprocessable();
});

it('refuses reservations and user creation throughout an unfinished server removal', function (string $status): void {
    $this->operation->update(['status' => $status]);
    $project = Project::query()->create(['name' => 'Reservation proof', 'slug' => 'reservation-proof', 'repository_url' => 'https://example.test/proof.git', 'default_branch' => 'main']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id, 'name' => 'dev', 'environment' => 'development', 'checkout_path' => '/srv/proof/dev', 'status' => 'active']);
    expect(fn () => app(CreateServerDatabaseAction::class)->reserve($this->server, 'new', 'new', $instance, 'operator'))
        ->toThrow(ResourceOperationException::class, 'Database removal is unfinished on this server.');
    expect(fn () => app(CreateServerDatabaseAction::class)->prepare(new CreateServerDatabaseData('new', $this->server->slug, null), 'operator'))
        ->toThrow(ResourceOperationException::class, 'Database removal is unfinished on this server.');
    expect(fn () => app(CreateDatabaseUserAction::class)->execute($this->connection, new CreateDatabaseUserData('reader', 'private-reader', true), 'operator'))
        ->toThrow(ResourceOperationException::class, 'Database removal is unfinished on this server.');
    $this->assertDatabaseCount('database_connections', 1);
    $this->assertDatabaseCount('database_users', 0);
})->with(['queued', 'running', 'waiting']);

it('permits new intents after a terminal removal and isolates the server target', function (string $status): void {
    $this->operation->update(['status' => $status]);
    app(DatabaseDestroySafety::class)->assertAcceptsCreates($this->server);
    $this->operation->update(['status' => 'running', 'target_id' => $this->server->id + 1]);
    app(DatabaseDestroySafety::class)->assertAcceptsCreates($this->server);
    $this->operation->update(['target_id' => $this->server->id, 'target_type' => 'instance']);
    app(DatabaseDestroySafety::class)->assertAcceptsCreates($this->server);
    expect(true)->toBeTrue();
})->with(['succeeded', 'failed', 'cancelled']);

it('refuses removal admission while database or user creation is unfinished on the server', function (string $kind, string $status): void {
    $this->operation->update(['kind' => $kind, 'status' => $status]);
    $count = DatabaseExecutionIntent::query()->count();

    expect(fn () => app(DropServerDatabaseAction::class)->execute($this->connection))
        ->toThrow(ResourceOperationException::class, 'Database creation is unfinished on this server.');
    expect(DatabaseExecutionIntent::query()->count())->toBe($count);
    $this->assertModelExists($this->connection);
})->with(['database.create', 'database_user.create'])->with(['queued', 'running', 'waiting']);

it('allows removal after terminal creation and ignores another server or target type', function (string $kind): void {
    $this->operation->update(['kind' => $kind]);
    foreach (['succeeded', 'failed', 'cancelled'] as $status) {
        $this->operation->update(['status' => $status]);
        expect(app(DropServerDatabaseAction::class)->prepareMany([$this->connection]))->toHaveCount(1);
    }
    $this->operation->update(['status' => 'running', 'target_id' => $this->server->id + 1]);
    expect(app(DropServerDatabaseAction::class)->prepareMany([$this->connection]))->toHaveCount(1);
    $this->operation->update(['target_id' => $this->server->id, 'target_type' => 'instance']);
    expect(app(DropServerDatabaseAction::class)->prepareMany([$this->connection]))->toHaveCount(1);
})->with(['database.create', 'database_user.create']);

it('checks unfinished removal inside the reservation transaction before recording a connection', function (): void {
    $project = Project::query()->create(['name' => 'Atomic reservation', 'slug' => 'atomic-reservation', 'repository_url' => 'https://example.test/proof.git', 'default_branch' => 'main']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id, 'name' => 'dev', 'environment' => 'development', 'checkout_path' => '/srv/proof/dev', 'status' => 'active']);
    $baseline = DB::transactionLevel();
    $levels = [];
    DB::listen(function ($query) use (&$levels): void {
        if (str_starts_with($query->sql, 'select') && in_array('database.destroy', $query->bindings, true)) {
            $levels[] = DB::transactionLevel();
        }
    });

    expect(fn () => app(CreateServerDatabaseAction::class)->reserve($this->server, 'new', 'new', $instance, 'operator'))
        ->toThrow(ResourceOperationException::class, 'Database removal is unfinished on this server.');
    expect($levels)->not->toBeEmpty();
    expect(min($levels))->toBeGreaterThan($baseline);
    $this->assertDatabaseCount('database_connections', 1);
});
