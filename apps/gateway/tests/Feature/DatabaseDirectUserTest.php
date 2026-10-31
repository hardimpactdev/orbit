<?php

declare(strict_types=1);

use App\Actions\DatabaseServers\RecordDatabaseOperationAction;
use App\Domain\DatabaseServers\DatabaseCommands;
use App\Domain\DatabaseServers\DatabaseIntentSource;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Activity;
use App\Models\DatabaseConnection;
use App\Models\DatabaseExecutionIntent;
use App\Models\DatabaseServer;
use App\Models\DatabaseUser;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->gateway = $this->markAsGateway(Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1']));
    $this->node = Node::query()->create(['name' => 'database-dev', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'amd64', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9']);
    $this->node->accessibleNodes()->attach($this->gateway);
    config(['swamp.run_token' => 'fake.token']);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $this->node->id, 'name' => 'proof', 'runtime' => 'docker', 'runtime_config' => [], 'restart_policy' => 'unless-stopped', 'desired_state' => 'running', 'status' => 'active']);
    $this->server = DatabaseServer::query()->create(['slug' => 'proof', 'driver' => 'pgsql', 'node_id' => $this->node->id, 'process_id' => $process->id, 'tag' => '18', 'port' => 5434, 'root_password' => 'private-root-sentinel', 'status' => 'active']);
    $this->connection = DatabaseConnection::query()->create(['slug' => 'proof', 'driver' => 'pgsql', 'node_id' => $this->node->id, 'database_server_id' => $this->server->id, 'host' => '192.0.2.9', 'database' => 'proof', 'username' => 'owner', 'password' => 'private-owner-sentinel']);
    $this->runId = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $socket = $this->socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $sent): array => ($sent['type'] === 'vault.put' ? [['type' => 'vault.put', 'id' => $sent['id'], 'payload' => ['data' => ['vaultName' => $sent['payload']['vaultName'], 'secretKey' => $sent['payload']['key'], 'vaultType' => 'local_encryption']]]] : [['type' => 'event', 'id' => $sent['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-database-user-create', 'runId' => $this->runId]]]);
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
    expect($this->operation->state)->toMatchArray(['swamp_run_id' => $this->runId]);
    expect($this->operation->attempts)->toBe(0);
    expect($this->operation->worker)->toBeNull();
    $public = $this->getJson($this->path.'?run_id='.$this->runId)->assertOk()->json('data.input.intent');
    expect($public)->toHaveKeys(['driver', 'container', 'username', 'replication', 'known_user'])->not->toHaveKeys(['password', 'sql', 'agent_sha256']);
    expect(json_encode($this->operation->fresh()->toArray()))->not->toContain('private-root-sentinel', $this->sql, 'private-login-sentinel');
    $this->postJson($this->path.'/primitive', ['run_id' => $this->runId])->assertUnprocessable();
});

it('copies only the existing recorded material and refuses private step reads', function (): void {
    $copy = collect($this->socket->sent)->firstWhere('type', 'vault.put');
    expect($copy['payload']['key'])->toBe('database-user-create-intent-'.$this->operation->input['intent_id']);
    expect(json_decode($copy['payload']['value'], true))->toBe(['password' => 'private-root-sentinel', 'sql' => $this->sql]);
    $start = collect($this->socket->sent)->firstWhere('type', 'workflow.run');
    expect($start['payload']['inputs']['intent']['ssh'])->toMatchArray(['host' => '10.44.0.9', 'user' => 'orbit']);
    expect($start['payload']['inputs']['execution_secrets']['user_create']['key'])->toBe($copy['payload']['key']);
    expect(json_encode($start))->not->toContain('private-root-sentinel', $this->sql, 'private-login-sentinel');
    $this->getJson($this->path.'/private-material?run_id='.$this->runId)->assertUnprocessable();
    expect(fn () => app(DatabaseIntentSource::class)->resolve($this->operation, $this->operation->input['intent_id'], '', 'password'))->toThrow(ResourceOperationException::class);
    expect(json_encode(Activity::query()->get()->toArray()))->not->toContain('private-root-sentinel', $this->sql);
});

it('projects the encrypted recorded user once and refuses later private reads', function (): void {
    $this->assertDatabaseCount('database_users', 0);
    $intent = DatabaseExecutionIntent::recorded($this->operation->input['intent_id']);
    $payload = $intent->payload;
    unset($payload['commands']['database-apply']['protected']);
    $intent->update(['payload' => $payload]);
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
    $this->postJson($this->path.'/result', [...$failed, 'result' => [...$failed['result'], 'password' => 'private-root-sentinel', 'output' => $this->sql]])->assertOk();
    expect($this->operation->fresh()->result)->toBe(['error_code' => 'database.wal_level_not_logical']);
    $this->assertDatabaseCount('database_users', 0);
});

it('admits PostgreSQL replication without any Gateway SQL and leaves WAL checks to the Node', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $response = $this->postJson('/api/v1/database-connections/proof/users', ['username' => 'second_reader', 'password' => 'another-private-login', 'replication' => true])
        ->assertAccepted()->assertJsonPath('data.kind', 'database_user.create');
    expect($response->getContent())->not->toContain('another-private-login', 'private-root-sentinel');
    $this->assertDatabaseCount('database_users', 0);
});

it('refuses unbound private access and an undeclared private output', function (): void {
    $this->postJson($this->path.'/secret-output', ['run_id' => $this->runId, 'outputs' => ['credential' => 'invented']])->assertUnprocessable();
    $this->operation->update(['input' => [...$this->operation->input, 'password' => ['$secret' => 'database.intent', 'id' => $this->operation->input['intent_id'] + 100]]]);
    $response = $this->getJson($this->path.'/private-material?run_id='.$this->runId)->assertUnprocessable()->assertHeader('Cache-Control', 'no-store, private');
    expect($response->getContent())->not->toContain('private-root-sentinel', $this->sql);
    $this->operation->update(['state' => ['database_user_writer' => 'direct']]);
    $this->getJson($this->path.'/private-material?run_id='.$this->runId)->assertConflict()->assertJsonPath('error.code', 'operation.run_unbound');
});
