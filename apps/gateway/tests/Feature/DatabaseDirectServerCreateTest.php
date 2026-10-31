<?php

declare(strict_types=1);

use App\Actions\DatabaseServers\CreateDatabaseServerAction;
use App\Data\DatabaseServers\CreateDatabaseServerData;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\Process;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->gateway = $this->markAsGateway(Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1']));
    $this->node = Node::query()->create(['name' => 'database-dev', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'amd64', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9']);
    $this->node->accessibleNodes()->attach($this->gateway);
    config(['operation_executor.worker_node_ids' => [$this->node->id], 'swamp.run_token' => 'fake.token']);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->runId = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $sent): array => [['type' => 'event', 'id' => $sent['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-database-server-create', 'runId' => $this->runId]]];
    app()->instance(WebSocketClient::class, $socket);
    $this->create = fn (string $driver) => app(CreateDatabaseServerAction::class)->execute(new CreateDatabaseServerData('proof', $this->node->id, DatabaseDriver::from($driver), $driver === 'pgsql' ? '18' : '8.0', $driver === 'pgsql' ? 5432 : ($driver === 'redis' ? 6379 : 3306)));
});

it('records a direct-only server with safe container intent and protected initializer material', function (string $driver): void {
    $operation = ($this->create)($driver);
    $server = DatabaseServer::query()->sole();
    $path = '/api/v1/direct-operations/'.$operation->id;
    expect(app(OperationKinds::class)->definition('database_server.create'))->not->toHaveKeys(['runs', 'execution', 'completion', 'children']);
    expect($operation->lock_key)->toBeNull();
    expect($server->status->value)->toBe('provisioning');
    expect($server->process->runtime_config['environment'])->toBe([]);
    $safe = $this->getJson($path.'?run_id='.$this->runId)->assertJsonMissingPath('error')->assertOk()->json('data.input');
    expect($safe['driver'])->toBe($driver);
    expect($safe['initialize'])->toBe($driver !== 'redis');
    expect($safe['container_input'])->not->toHaveKey('environment');
    expect($safe)->not->toHaveKeys(['password', 'environment', 'acl', 'payload']);
    $material = $this->getJson($path.'/private-material?run_id='.$this->runId)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');
    expect($material['password'])->toBe($server->root_password);
    if ($driver !== 'redis') {
        expect($material['environment'])->toBe([$driver === 'pgsql' ? 'POSTGRES_PASSWORD' : 'MYSQL_ROOT_PASSWORD' => $server->root_password]);
    } else {
        expect($material['acl'])->toContain(hash('sha256', $server->root_password));
    }
    expect(json_encode($operation->fresh()->toArray()))->not->toContain($server->root_password);
    expect(json_encode($server->process->toArray()))->not->toContain($server->root_password);
    $this->postJson($path.'/primitive', ['run_id' => $this->runId])->assertUnprocessable();
})->with(['mysql', 'pgsql', 'redis']);

it('projects native server success once and refuses later private reads', function (): void {
    $operation = ($this->create)('mysql');
    $server = DatabaseServer::query()->sole();
    $path = '/api/v1/direct-operations/'.$operation->id;
    $result = ['run_id' => $this->runId, 'status' => 'succeeded', 'started_at' => '2026-10-30T19:00:00.000Z', 'finished_at' => '2026-10-30T19:00:01.000Z', 'result' => ['backend' => 'active']];
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

it('refuses a changed server Node or foreign caller before releasing material', function (): void {
    $operation = ($this->create)('mysql');
    $path = '/api/v1/direct-operations/'.$operation->id;
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $this->getJson($path.'/private-material?run_id='.$this->runId)->assertForbidden();
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    DatabaseServer::query()->sole()->update(['node_id' => $this->gateway->id]);
    $this->getJson($path.'?run_id='.$this->runId)->assertUnprocessable();
    $this->getJson($path.'/private-material?run_id='.$this->runId)->assertUnprocessable();
});

it('refuses create and private reads while the server has a removal intent', function (): void {
    $operation = ($this->create)('mysql');
    DatabaseServer::query()->sole()->update(['status' => 'removing']);
    expect(fn () => ($this->create)('mysql'))->toThrow(ResourceOperationException::class, 'unfinished removal intent');
    $this->getJson('/api/v1/direct-operations/'.$operation->id.'?run_id='.$this->runId)->assertUnprocessable();
    $this->getJson('/api/v1/direct-operations/'.$operation->id.'/private-material?run_id='.$this->runId)->assertUnprocessable();
});

it('refuses stale open Valkey policy but records its safe failure after closure', function (): void {
    $initial = ($this->create)('redis');
    $initial->update(['status' => 'succeeded', 'finished_at' => now()]);
    $server = DatabaseServer::query()->sole();
    $server->update(['default_open' => true, 'status' => 'active']);
    $operation = ($this->create)('redis');
    $server->update(['default_open' => false]);
    $path = '/api/v1/direct-operations/'.$operation->id;
    $this->getJson($path.'?run_id='.$this->runId)->assertUnprocessable();
    $this->getJson($path.'/private-material?run_id='.$this->runId)->assertUnprocessable();
    $this->postJson($path.'/result', ['run_id' => $this->runId, 'started_at' => '2026-10-31T04:00:00.000Z', 'finished_at' => '2026-10-31T04:00:01.000Z', 'status' => 'succeeded', 'result' => ['backend' => 'active']])->assertUnprocessable();
    $this->postJson($path.'/result', ['run_id' => $this->runId, 'started_at' => '2026-10-31T04:00:00.000Z', 'finished_at' => '2026-10-31T04:00:01.000Z', 'status' => 'failed', 'error_code' => 'database.server_policy_changed',
        'result' => ['error_code' => 'database.server_policy_changed']])->assertOk();
    expect($server->fresh()->default_open)->toBeFalse();
    expect($server->fresh()->status->value)->toBe('active');
});

it('releases only a fresh refused adoption reservation and preserves its operator Process', function (bool $uncertain): void {
    $process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $this->node->id,
        'name' => 'operator-valkey', 'runtime' => 'docker', 'runtime_config' => ['image' => 'valkey/valkey:8.0',
            'command' => ['valkey-server'], 'environment' => [], 'ports' => [$this->node->wireguard_ip.':6379:6379'],
            'volumes' => [['source' => 'operator-valkey-data', 'target' => '/data', 'read_only' => false]]],
        'restart_policy' => 'unless-stopped', 'desired_state' => 'running', 'status' => 'active']);
    $original = $process->refresh()->getAttributes();
    $connection = DatabaseConnection::query()->create(['slug' => 'operator-valkey', 'driver' => 'redis',
        'node_id' => $this->node->id, 'host' => $this->node->wireguard_ip, 'port' => 6379, 'password' => 'wrong-first-password']);
    $data = new CreateDatabaseServerData('adopt-proof', $this->node->id, DatabaseDriver::Redis, '8.0', null, $process->id, $connection->slug);
    $operation = app(CreateDatabaseServerAction::class)->execute($data);
    $reserved = DatabaseServer::query()->sole();
    $failed = ['run_id' => $this->runId, 'status' => 'failed', 'started_at' => '2026-10-31T04:00:00.000Z',
        'finished_at' => '2026-10-31T04:00:01.000Z', 'error_code' => 'database.server_adoption_refused',
        'result' => ['error_code' => 'database.server_adoption_refused']];
    $path = '/api/v1/direct-operations/'.$operation->id;
    if ($uncertain) {
        $previous = [...$failed, 'error_code' => 'process.converge_failed', 'result' => ['error_code' => 'process.converge_failed']];
        $this->postJson($path.'/result', $previous)->assertOk();
        $operation = app(CreateDatabaseServerAction::class)->execute($data);
        $path = '/api/v1/direct-operations/'.$operation->id;
        $this->postJson($path.'/result', $failed)->assertOk();
        expect($reserved->fresh()->status->value)->toBe('failed');
        expect($process->fresh()->getAttributes())->toBe($original);
        expect($connection->fresh()->database_server_id)->toBeNull();

        return;
    }
    $this->postJson($path.'/result', $failed)->assertOk();
    $this->assertModelMissing($reserved);
    expect($process->fresh()->getAttributes())->toBe($original);
    expect($connection->fresh()->database_server_id)->toBeNull();
    $this->postJson($path.'/result', $failed)->assertOk();
    $this->getJson($path.'?run_id='.$this->runId)->assertOk();
    $connection->update(['password' => 'corrected-operator-password']);
    $retry = app(CreateDatabaseServerAction::class)->execute($data);
    expect($retry->id)->not->toBe($operation->id);
    expect(DatabaseServer::query()->sole()->root_password)->toBe('corrected-operator-password');
    expect(DatabaseServer::query()->sole()->process_id)->toBe($process->id);
    expect($process->fresh()->getAttributes())->toBe($original);
})->with(['first refusal' => false, 'refusal after uncertain mutation' => true]);
