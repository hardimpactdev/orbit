<?php

declare(strict_types=1);

use App\Actions\DatabaseServers\RecordDatabaseOperationAction;
use App\Domain\DatabaseServers\DatabaseIntentSource;
use App\Domain\DatabaseServers\DatabaseStatements;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\SwampCommandStep;
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
    $this->node = Node::query()->create(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAINqVuK+N4gL/sDvGNKnGrYhEtEFnZx9hvH+134PsQgND', 'name' => 'database-dev', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'amd64', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9']);
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
        ['database-apply' => ['name' => 'database_user.create', 'input' => ['driver' => 'pgsql', 'container' => 'orbit-process-'.$process->id.'-proof', 'database' => 'proof', 'owner_username' => 'owner', 'username' => 'reader', 'read_only' => true, 'replication' => true, 'known_user' => false, 'payload' => ['password' => 'private-login-sentinel', 'salt' => base64_encode(str_repeat('a', 16))]], 'protected' => ['payload']]],
        ['upsert_user' => ['database_connection_id' => $this->connection->id, 'username' => 'reader', 'password' => 'private-login-sentinel', 'privileges' => 'SELECT', 'replication' => true, 'created_by' => 'operator']]);
    $this->path = '/api/v1/direct-operations/'.$this->operation->id;
    $this->finished = ['run_id' => $this->runId, 'status' => 'succeeded', 'started_at' => '2026-10-30T19:00:00.000Z', 'finished_at' => '2026-10-30T19:00:01.000Z',
        'result' => ['steps' => ['Check logical WAL' => ['status' => 'succeeded', 'stdout' => "logical\n", 'exit_code' => 0],
            'Apply login and grants' => ['status' => 'succeeded', 'stdout' => '', 'exit_code' => 0], 'Check the user' => ['status' => 'succeeded', 'stdout' => "reader\nt\nt\nt\n", 'exit_code' => 0]]]];
});

it('directly starts SQL user intent without old execution declarations or secret values', function (): void {
    expect($this->operation->state)->toMatchArray(['swamp_run_id' => $this->runId]);
    expect($this->operation->attempts)->toBe(0);
    expect($this->operation->worker)->toBeNull();
    $public = $this->getJson($this->path.'?run_id='.$this->runId)->assertOk()->json('data.input.intent');
    expect($public)->toHaveKeys(['driver', 'username', 'replication', 'admin_client', 'user_client', 'ssh_execution'])->not->toHaveKeys(['password', 'sql', 'payload', 'statements']);
    expect(json_encode($this->operation->fresh()->toArray()))->not->toContain('private-root-sentinel', $this->sql, 'private-login-sentinel');
    $this->postJson($this->path.'/primitive', ['run_id' => $this->runId])->assertUnprocessable();
});

it('copies one whole vault value per batch, each behind its login password line', function (): void {
    $puts = collect($this->socket->sent)->where('type', 'vault.put')->pluck('payload.value', 'payload.key');
    $intentId = $this->operation->input['intent_id'];
    expect($puts['database-user-create-wal-'.$intentId])->toBe("private-root-sentinel\nSHOW wal_level;\n");
    expect($puts['database-user-create-apply-'.$intentId])->toStartWith("private-root-sentinel\n")
        ->not->toContain('wal_level')->toContain('CREATE ROLE "reader" LOGIN')
        ->toContain('GRANT CONNECT ON DATABASE "proof" TO "reader"')->not->toContain('private-login-sentinel');
    expect($puts['database-user-create-check-'.$intentId])->toStartWith("private-login-sentinel\nSELECT current_user;\n")
        ->toContain('SELECT rolreplication FROM pg_roles')->toContain("SELECT 'reader=r/owner' = ANY");
    $start = collect($this->socket->sent)->firstWhere('type', 'workflow.run');
    expect($start['payload']['inputs']['intent']['ssh_execution']['target']['host'])->toBe('10.44.0.9');
    expect(array_keys($start['payload']['inputs']['execution_secrets']))->toBe(['wal', 'apply', 'check']);
    expect(json_encode($start))->not->toContain('private-root-sentinel', 'private-login-sentinel');
    $this->getJson($this->path.'/private-material?run_id='.$this->runId)->assertUnprocessable();
    expect(fn () => app(DatabaseIntentSource::class)->resolve($this->operation, $intentId, '', 'password'))->toThrow(ResourceOperationException::class);
    expect(json_encode(Activity::query()->get()->toArray()))->not->toContain('private-root-sentinel', 'private-login-sentinel');
});

it('maps the native result to the user error code', function (string $wal, string $stdout, int $exit, string $check, string $code): void {
    $this->postJson($this->path.'/result', [...$this->finished, 'result' => ['steps' => [
        'Check logical WAL' => ['status' => 'succeeded', 'stdout' => $wal, 'exit_code' => 0],
        'Apply login and grants' => ['status' => 'succeeded', 'stdout' => $stdout, 'exit_code' => $exit],
        'Check the user' => ['status' => 'succeeded', 'stdout' => $check, 'exit_code' => 0]]]])->assertOk();
    expect([$this->operation->fresh()->status->value, $this->operation->fresh()->error_code])->toBe(['failed', $code]);
    $this->assertDatabaseCount('database_users', 0);
})->with([
    'wal level' => ["replica\n", '', 0, '', 'database.wal_level_not_logical'],
    'foreign role' => ["logical\n", "database.name_conflict\n", 3, '', 'database.name_conflict'],
    'batch failed' => ["logical\n", '', 3, '', 'database.server_admin_failed'],
    'user cannot log in' => ["logical\n", '', 0, '', 'database.user_login_failed'],
    'replication attribute missing' => ["logical\n", '', 0, "reader\nf\nt\nt\n", 'database.user_login_failed'],
    'other default grants' => ["logical\n", '', 0, "reader\nt\nt\nf\n", 'database.user_login_failed'],
]);

it('reads a refused WAL level as the end check when the later steps skip', function (): void {
    config(['swamp.read_token' => 'fake.token', 'swamp.ca_path' => '/public/ca.pem']);
    $inputs = collect($this->socket->sent)->firstWhere('type', 'workflow.run')['payload']['inputs'];
    $history = ['id' => $this->runId, 'workflowName' => 'operation-database-user-create', 'status' => 'succeeded', 'inputs' => $inputs,
        'startedAt' => '2026-10-31T12:00:00Z', 'completedAt' => '2026-10-31T12:00:01Z',
        'jobs' => [['name' => 'user', 'steps' => [['name' => 'Check logical WAL', 'status' => 'succeeded', 'outputs' => ['stdout' => "replica\n", 'exitCode' => 0]],
            ['name' => 'Apply login and grants', 'status' => 'skipped', 'outputs' => []], ['name' => 'Check the user', 'status' => 'skipped', 'outputs' => []]]]]];
    $this->socket->reply = static fn (array $sent): array => [['type' => 'workflow.history.get', 'id' => $sent['id'], 'payload' => ['data' => $history]]];
    $selector = app(DirectOperation::class)->definition('database_user.create')['terminal_result'];
    $projected = app(SwampCommandStep::class)->projectTerminal($this->operation, ['id' => $this->runId, 'status' => 'succeeded'], $selector);
    expect([$projected->status, $projected->error_code])->toBe([OperationStatus::Failed, 'database.wal_level_not_logical']);
    $this->assertDatabaseCount('database_users', 0);
});

it('expects exactly the requested MySQL grant, with the batch-escaped database name', function (): void {
    expect(DatabaseStatements::userCheckLines(['driver' => 'mysql', 'username' => 'reporting', 'database' => 'shop_eu', 'read_only' => false, 'replication' => false]))
        ->toBe(['reporting@%', 'GRANT USAGE ON *.* TO `reporting`@`%`', 'GRANT ALL PRIVILEGES ON `shop\\\\_eu`.* TO `reporting`@`%`']);
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
    $admitted = DatabaseExecutionIntent::query()->latest('id')->firstOrFail();
    expect($admitted->payload['commands']['database-apply']['input'])->toMatchArray(['database' => 'proof', 'owner_username' => 'owner', 'read_only' => true, 'replication' => true])->not->toHaveKey('sql');
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

it('rejects a PostgreSQL password outside the supported alphabet before recording intent', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $before = DatabaseExecutionIntent::query()->count();
    $this->postJson('/api/v1/database-connections/proof/users', ['username' => 'invalid_reader', 'password' => "non-ascii-\u{00e9}"])
        ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    expect(DatabaseExecutionIntent::query()->count())->toBe($before);
});
