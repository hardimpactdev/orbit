<?php

declare(strict_types=1);

use App\Actions\DatabaseServers\RecordDatabaseOperationAction;
use App\Domain\DatabaseServers\DatabaseCommands;
use App\Domain\DatabaseServers\DatabaseIntentSource;
use App\Domain\DatabaseServers\DatabaseServerAdmin;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Activity;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use App\Models\DatabaseUser;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeDatabaseServerAdmin;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->gateway = $this->markAsGateway(Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1']));
    $this->node = Node::query()->create(['name' => 'database-dev', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'amd64', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9']);
    $this->node->accessibleNodes()->attach($this->gateway);
    config(['operation_executor.worker_node_ids' => [$this->node->id], 'swamp.run_token' => 'fake.token']);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $this->node->id, 'name' => 'proof', 'runtime' => 'docker', 'runtime_config' => [], 'restart_policy' => 'unless-stopped', 'desired_state' => 'running', 'status' => 'active']);
    $this->server = DatabaseServer::query()->create(['slug' => 'proof', 'driver' => 'pgsql', 'node_id' => $this->node->id, 'process_id' => $process->id, 'tag' => '18', 'port' => 5434, 'root_password' => 'private-root-sentinel', 'status' => 'active']);
    $this->connection = DatabaseConnection::query()->create(['slug' => 'proof', 'driver' => 'pgsql', 'node_id' => $this->node->id, 'database_server_id' => $this->server->id, 'host' => '192.0.2.9', 'database' => 'proof', 'username' => 'owner', 'password' => 'private-owner-sentinel']);
    $this->runId = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $sent): array => [['type' => 'event', 'id' => $sent['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-database-user-create', 'runId' => $this->runId]]];
    app()->instance(WebSocketClient::class, $socket);
    $this->sql = "CREATE USER reader PASSWORD 'private-login-sentinel';";
    $this->operation = app(RecordDatabaseOperationAction::class)->execute('database_user.create', 'database_server', $this->server->id, $this->node->id,
        ['connection_id' => $this->connection->id, 'driver' => 'pgsql', 'username' => 'reader', 'replication' => true, 'known_user' => false],
        ['database-apply' => DatabaseCommands::sql($this->server, $this->sql)],
        ['upsert_user' => ['database_connection_id' => $this->connection->id, 'username' => 'reader', 'password' => 'private-login-sentinel', 'privileges' => 'SELECT', 'replication' => true, 'created_by' => 'operator']]);
    $this->path = '/api/v1/direct-operations/'.$this->operation->id;
    $this->finished = ['run_id' => $this->runId, 'status' => 'succeeded', 'started_at' => '2026-10-30T19:00:00.000Z', 'finished_at' => '2026-10-30T19:00:01.000Z', 'result' => ['backend' => 'active']];
});

it('directly starts SQL user intent without old execution declarations or secret values', function (): void {
    expect(app(OperationKinds::class)->definition('database_user.create'))->not->toHaveKeys(['runs', 'execution', 'completion', 'completion_contract', 'children']);
    expect($this->operation->state)->toMatchArray(['database_user_writer' => 'direct', 'swamp_run_id' => $this->runId]);
    expect($this->operation->attempts)->toBe(0);
    expect($this->operation->worker)->toBeNull();
    $public = $this->getJson($this->path.'?run_id='.$this->runId)->assertOk()->json('data.input');
    expect($public)->toHaveKeys(['driver', 'container', 'agent_sha256', 'username', 'replication', 'known_user'])->not->toHaveKeys(['password', 'sql']);
    expect(json_encode($this->operation->fresh()->toArray()))->not->toContain('private-root-sentinel', $this->sql, 'private-login-sentinel');
    $this->postJson($this->path.'/primitive', ['run_id' => $this->runId])->assertUnprocessable();
});

it('reads only declared private fields for the exact unfinished run and owning Node', function (): void {
    $before = $this->operation->fresh()->getAttributes();
    $this->getJson($this->path.'/private-material?run_id='.$this->runId)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.password', 'private-root-sentinel')->assertJsonPath('data.sql', $this->sql);
    expect($this->operation->fresh()->getAttributes())->toBe($before);
    $this->getJson($this->path.'/private-material?run_id=20e8138a-cf7f-4c6c-85fd-ff561b575ac8')->assertConflict();
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $this->getJson($this->path.'/private-material?run_id='.$this->runId)->assertForbidden();
    expect(json_encode(Activity::query()->get()->toArray()))->not->toContain('private-root-sentinel', $this->sql);
});

it('maps only fixed direct material paths through the existing owner-checked source', function (): void {
    $source = app(DatabaseIntentSource::class);
    $id = $this->operation->input['intent_id'];
    expect($source->resolve($this->operation, $id, '', 'password')->value)->toBe('private-root-sentinel');
    expect(fn () => $source->resolve($this->operation, $id, '', 'container'))->toThrow(ResourceOperationException::class);
    $this->operation->node_id = $this->gateway->id;
    expect(fn () => $source->resolve($this->operation, $id, '', 'sql'))->toThrow(ResourceOperationException::class);
});

it('projects the encrypted recorded user once and refuses later private reads', function (): void {
    $this->assertDatabaseCount('database_users', 0);
    $this->postJson($this->path.'/result', $this->finished)->assertOk();
    $user = DatabaseUser::query()->sole();
    expect($user->password)->toBe('private-login-sentinel');
    expect(DB::table('database_users')->where('id', $user->id)->value('password'))->not->toContain('private-login-sentinel');
    expect($this->operation->fresh()->status)->toBe(OperationStatus::Succeeded);
    $user->update(['password' => 'newer-user-password']);
    $this->postJson($this->path.'/result', $this->finished)->assertOk();
    expect($user->fresh()->password)->toBe('newer-user-password');
    $this->getJson($this->path.'/private-material?run_id='.$this->runId)->assertConflict()->assertJsonPath('error.code', 'operation.finished');
});

it('projects a bounded preflight refusal without creating a user or accepting SQL output', function (): void {
    $failed = [...$this->finished, 'status' => 'failed', 'error_code' => 'database.wal_level_not_logical', 'result' => ['error_code' => 'database.wal_level_not_logical']];
    $this->postJson($this->path.'/result', [...$failed, 'error_code' => 'invented', 'result' => ['error_code' => 'invented']])->assertUnprocessable();
    $this->postJson($this->path.'/result', [...$failed, 'result' => [...$failed['result'], 'password' => 'private-root-sentinel', 'output' => $this->sql]])->assertOk();
    expect($this->operation->fresh()->result)->toBe(['error_code' => 'database.wal_level_not_logical']);
    $this->assertDatabaseCount('database_users', 0);
});

it('admits PostgreSQL replication without any Gateway SQL and leaves WAL checks to the Node', function (): void {
    $admin = new FakeDatabaseServerAdmin;
    app()->instance(DatabaseServerAdmin::class, $admin);
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $response = $this->postJson('/api/v1/database-connections/proof/users', ['username' => 'second_reader', 'password' => 'another-private-login', 'replication' => true])
        ->assertAccepted()->assertJsonPath('data.kind', 'database_user.create');
    expect($admin->statements)->toBe([]);
    expect($response->getContent())->not->toContain('another-private-login', 'private-root-sentinel');
    $this->assertDatabaseCount('database_users', 0);
});

it('refuses unbound runs foreign material references and an undeclared private output', function (): void {
    $this->postJson($this->path.'/secret-output', ['run_id' => $this->runId, 'outputs' => ['credential' => 'invented']])->assertUnprocessable();
    $this->operation->update(['input' => [...$this->operation->input, 'password' => ['$secret' => 'database.intent', 'id' => $this->operation->input['intent_id'] + 100]]]);
    $response = $this->getJson($this->path.'/private-material?run_id='.$this->runId)->assertUnprocessable()->assertHeader('Cache-Control', 'no-store, private');
    expect($response->getContent())->not->toContain('private-root-sentinel', $this->sql);
    $this->operation->update(['state' => ['database_user_writer' => 'direct']]);
    $this->getJson($this->path.'/private-material?run_id='.$this->runId)->assertConflict()->assertJsonPath('error.code', 'operation.run_unbound');
});
