<?php

declare(strict_types=1);

use App\Actions\DatabaseServers\CreateDatabaseServerAction;
use App\Data\DatabaseServers\CreateDatabaseServerData;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\DatabaseExecutionIntent;
use App\Models\DatabaseServer;
use App\Models\Node;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->gateway = $this->markAsGateway(Node::query()->create(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAINqVuK+N4gL/sDvGNKnGrYhEtEFnZx9hvH+134PsQgND', 'name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1']));
    $this->node = Node::query()->create(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAINqVuK+N4gL/sDvGNKnGrYhEtEFnZx9hvH+134PsQgND', 'name' => 'database-dev', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'amd64', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9']);
    $this->node->accessibleNodes()->attach($this->gateway);
    config(['swamp.run_token' => 'fake.token']);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->runId = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $sent): array => ($sent['type'] === 'vault.put' ? [['type' => 'vault.put', 'id' => $sent['id'], 'payload' => ['data' => ['vaultName' => $sent['payload']['vaultName'], 'secretKey' => $sent['payload']['key'], 'vaultType' => 'local_encryption']]]] : [['type' => 'event', 'id' => $sent['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-database-server-create', 'runId' => $this->runId]]]);
    app()->instance(WebSocketClient::class, $socket);
    $this->create = fn (string $driver) => app(CreateDatabaseServerAction::class)->execute(new CreateDatabaseServerData('proof', $this->node->id, DatabaseDriver::from($driver), $driver === 'pgsql' ? '18' : '8.0', $driver === 'pgsql' ? 5432 : ($driver === 'redis' ? 6379 : 3306)));
});

it('records a server whose run inputs hold vault key names, never a secret', function (string $driver): void {
    $operation = ($this->create)($driver);
    $server = DatabaseServer::query()->sole();
    $path = '/api/v1/direct-operations/'.$operation->id;
    expect($operation->lock_key)->toBeNull();
    expect($server->status->value)->toBe('provisioning');
    expect($server->process->runtime_config['environment'])->toBe([]);
    $input = $this->getJson($path.'?run_id='.$this->runId)->assertJsonMissingPath('error')->assertOk()->json('data.input');
    $intent = $input['intent'];
    expect([$intent['server_id'], $intent['driver']])->toBe([$server->id, $driver]);
    expect($intent['initialize'])->toBe($driver !== 'redis');
    expect($intent['container_input'])->toBe(['image' => $driver === 'pgsql' ? 'postgres:18' : ($driver === 'redis' ? 'valkey/valkey:8.0' : 'mysql:8.0')]);
    expect($intent['ssh_execution']['target']['host'])->toBe('10.44.0.9');
    $commands = $intent['commands'];
    $start = implode(' ', $commands['start']);
    expect(str_contains($start, '--env-file /dev/stdin'))->toBe($driver !== 'redis');
    expect(implode(' ', $commands['scrub']))->not->toContain('--env-file');
    expect($start)->toContain('orbit.process.id='.$server->process_id)->toContain('--health-start-period 300s');
    expect(array_slice($commands['wait'], 0, 5))->toBe(['timeout', '60', 'docker', 'exec', $intent['container']]);
    expect($commands['acl_path'])->toBe($driver === 'redis' ? '/var/lib/docker/volumes/orbit-proof-data/_data/users.acl' : '');
    expect(array_keys($input['execution_secrets']))->toBe(['login', 'start', ...($driver === 'redis' ? ['acl'] : [])]);
    expect(json_encode($input))->not->toContain($server->root_password);
    expect(json_encode($operation->fresh()->toArray()))->not->toContain($server->root_password);
    expect(json_encode($server->process->toArray()))->not->toContain($server->root_password);
    $puts = collect(app(WebSocketClient::class)->sent)->where('type', 'vault.put')->pluck('payload.value', 'payload.key');
    expect($puts['database-server-create-login-'.$operation->id])->toBe($server->root_password."\n".($driver === 'redis' ? "PING\n" : "SELECT 1;\n"));
    expect($puts['database-server-create-start-'.$operation->id])->toBe($driver === 'redis' ? "\n" : ['mysql' => 'MYSQL_ROOT_PASSWORD', 'pgsql' => 'POSTGRES_PASSWORD'][$driver].'='.$server->root_password."\n");
    if ($driver === 'redis') {
        expect($puts['database-server-create-intent-'.$operation->id])->toStartWith('user default on #');
    }
})->with(['mysql', 'pgsql', 'redis']);

it('maps the failed step of the native result to the server error code', function (): void {
    $operation = ($this->create)('mysql');
    $this->postJson('/api/v1/direct-operations/'.$operation->id.'/result', ['run_id' => $this->runId, 'status' => 'failed', 'started_at' => '2026-10-30T19:00:00.000Z', 'finished_at' => '2026-10-30T19:00:01.000Z',
        'error_code' => 'swamp.step_failed', 'result' => ['steps' => ['Wait until healthy' => ['status' => 'failed']], 'summary' => ['status' => 'failed', 'succeeded' => 3, 'failed' => 1, 'skipped' => 1, 'failed_steps' => ['Wait until healthy']]]])->assertOk();
    $server = DatabaseServer::query()->sole();
    expect([$server->status->value, $server->failed_step, $server->error_code])->toBe(['failed', 'Wait until healthy', 'database.server_unhealthy']);
});

it('fails a run whose admin login check did not answer', function (): void {
    $operation = ($this->create)('mysql');
    $this->postJson('/api/v1/direct-operations/'.$operation->id.'/result', ['run_id' => $this->runId, 'status' => 'succeeded', 'started_at' => '2026-10-30T19:00:00.000Z', 'finished_at' => '2026-10-30T19:00:01.000Z',
        'result' => ['steps' => ['Check the admin login' => ['status' => 'succeeded', 'stdout' => "ERROR 1045 (28000): Access denied\n"]], 'summary' => ['status' => 'succeeded', 'succeeded' => 5, 'failed' => 0, 'skipped' => 1, 'failed_steps' => []]]])->assertOk();
    $server = DatabaseServer::query()->sole();
    expect([$operation->fresh()->status->value, $server->status->value, $server->failed_step, $server->error_code])->toBe(['failed', 'failed', 'Check the admin login', 'database.server_login_failed']);
});

it('refuses a Node without a stored SSH host key before it records anything', function (): void {
    $this->node->update(['ssh_host_key' => null]);
    expect(fn () => ($this->create)('mysql'))->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('swamp.ssh_target_unavailable'));
    $this->assertDatabaseCount('database_servers', 0);
});

it('projects native server success once and refuses later private reads', function (): void {
    $operation = ($this->create)('mysql');
    $server = DatabaseServer::query()->sole();
    $path = '/api/v1/direct-operations/'.$operation->id;
    $result = ['run_id' => $this->runId, 'status' => 'succeeded', 'started_at' => '2026-10-30T19:00:00.000Z', 'finished_at' => '2026-10-30T19:00:01.000Z',
        'result' => ['steps' => ['Check the admin login' => ['status' => 'succeeded', 'stdout' => "1\n"]], 'summary' => ['status' => 'succeeded', 'succeeded' => 5, 'failed' => 0, 'skipped' => 1, 'failed_steps' => []]]];
    $intent = DatabaseExecutionIntent::recorded($operation->input['intent_id']);
    $intent->update(['payload' => array_diff_key($intent->payload, ['commands' => true])]);
    $this->postJson($path.'/result', $result)->assertOk();
    expect($server->fresh()->status->value)->toBe('active');
    expect($server->fresh()->process->runtime_config['environment'])->toBe([]);
    $server->update(['status' => 'failed']);
    $this->postJson($path.'/result', $result)->assertOk();
    expect($server->fresh()->status->value)->toBe('failed');
    $this->getJson($path.'/private-material?run_id='.$this->runId)->assertConflict();
});

it('retains encrypted credentials after a bounded refusal for an explicit retry', function (): void {
    $operation = ($this->create)('mysql');
    $server = DatabaseServer::query()->sole();
    $password = $server->root_password;
    $this->postJson('/api/v1/direct-operations/'.$operation->id.'/result', ['run_id' => $this->runId, 'status' => 'failed', 'started_at' => '2026-10-30T19:00:00.000Z', 'finished_at' => '2026-10-30T19:00:01.000Z', 'error_code' => 'database.server_not_ready', 'result' => ['error_code' => 'database.server_not_ready']])->assertOk();
    expect($server->fresh()->status->value)->toBe('failed');
    $retry = ($this->create)('mysql');
    expect($retry->id)->not->toBe($operation->id);
    expect($server->fresh()->root_password)->toBe($password);
    $this->assertDatabaseCount('database_servers', 1);
});

it('keeps admitted server identity and refuses a foreign caller', function (): void {
    $operation = ($this->create)('mysql');
    $path = '/api/v1/direct-operations/'.$operation->id;
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $this->getJson($path.'/private-material?run_id='.$this->runId)->assertForbidden();
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    DatabaseServer::query()->sole()->update(['node_id' => $this->gateway->id]);
    $this->getJson($path.'?run_id='.$this->runId)->assertOk();
    $this->getJson($path.'/private-material?run_id='.$this->runId)->assertUnprocessable();
});

it('refuses new create admission while an existing run retains its immutable input', function (): void {
    $operation = ($this->create)('mysql');
    DatabaseServer::query()->sole()->update(['status' => 'removing']);
    expect(fn () => ($this->create)('mysql'))->toThrow(ResourceOperationException::class, 'unfinished removal intent');
    $this->getJson('/api/v1/direct-operations/'.$operation->id.'?run_id='.$this->runId)->assertOk();
    $this->getJson('/api/v1/direct-operations/'.$operation->id.'/private-material?run_id='.$this->runId)->assertUnprocessable();
});
