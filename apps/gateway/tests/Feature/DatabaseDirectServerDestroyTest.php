<?php

declare(strict_types=1);

use App\Actions\DatabaseConnections\CreateServerDatabaseAction;
use App\Actions\DatabaseServers\CreateDatabaseServerAction;
use App\Actions\DatabaseServers\DestroyDatabaseServerAction;
use App\Data\DatabaseServers\CreateDatabaseServerData;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Project;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->gateway = $this->markAsGateway(Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1']));
    $this->node = Node::query()->create(['name' => 'database-dev', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'amd64', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9']);
    $this->node->accessibleNodes()->attach($this->gateway);
    config(['operation_executor.worker_node_ids' => [$this->node->id], 'swamp.run_token' => 'fake.token']);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $this->node->id, 'name' => 'proof', 'runtime' => 'docker', 'runtime_config' => [], 'restart_policy' => 'unless-stopped', 'desired_state' => 'running', 'status' => 'active']);
    $this->server = DatabaseServer::query()->create(['slug' => 'proof', 'driver' => 'pgsql', 'node_id' => $this->node->id, 'process_id' => $process->id, 'tag' => '18', 'port' => 5434, 'root_password' => 'private-root-sentinel', 'status' => 'active']);
    $this->runId = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $sent): array => [['type' => 'event', 'id' => $sent['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-database-server-destroy', 'runId' => $this->runId]]];
    app()->instance(WebSocketClient::class, $socket);
    $this->operation = app(DestroyDatabaseServerAction::class)->execute($this->server);
    $this->path = '/api/v1/direct-operations/'.$this->operation->id;
    $this->finished = ['run_id' => $this->runId, 'status' => 'succeeded', 'started_at' => '2026-10-30T19:00:00.000Z', 'finished_at' => '2026-10-30T19:00:01.000Z', 'result' => ['backend' => 'absent']];
});

it('starts removal without a relay or target hold and deletes only after native success', function (): void {
    expect(app(OperationKinds::class)->definition('database_server.destroy'))->not->toHaveKeys(['runs', 'execution', 'completion', 'children']);
    expect($this->operation->state)->toMatchArray(['database_server_destroy_writer' => 'direct', 'swamp_run_id' => $this->runId]);
    expect($this->operation->lock_key)->toBeNull();
    $this->assertDatabaseCount('database_execution_intents', 0);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertOk()->assertJsonPath('data.input.process_id', $this->server->process_id);
    $this->postJson($this->path.'/primitive', ['run_id' => $this->runId])->assertUnprocessable();
    $this->assertModelExists($this->server);
    $processId = $this->server->process_id;
    $this->postJson($this->path.'/result', $this->finished)->assertOk();
    $this->assertModelMissing($this->server);
    $this->assertDatabaseMissing('processes', ['id' => $processId]);
    $this->postJson($this->path.'/result', $this->finished)->assertOk();
    $this->getJson($this->path.'?run_id='.$this->runId)->assertOk();
});

it('refuses new connections and changed Process ownership before execution', function (): void {
    $this->server->process->update(['owner_id' => $this->gateway->id]);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertUnprocessable();
    $this->server->process->update(['owner_id' => $this->node->id]);
    DatabaseConnection::query()->create(['slug' => 'late', 'driver' => 'pgsql', 'database_server_id' => $this->server->id, 'host' => '192.0.2.9']);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertConflict()->assertJsonPath('error.code', 'database.server_in_use');
    $this->postJson($this->path.'/result', $this->finished)->assertConflict();
    $this->assertModelExists($this->server);
});

it('keeps records on a bounded failure and refuses fabricated success', function (): void {
    $this->postJson($this->path.'/result', [...$this->finished, 'result' => ['backend' => 'active']])->assertUnprocessable();
    $this->postJson($this->path.'/result', [...$this->finished, 'status' => 'failed', 'error_code' => 'process.remove_failed', 'result' => ['error_code' => 'process.remove_failed']])->assertOk();
    $this->assertModelExists($this->server);
    expect($this->server->fresh()->status->value)->toBe('failed');
    expect($this->server->fresh()->error_code)->toBe('process.remove_failed');
    $this->server->refresh()->update(['status' => 'active', 'error_code' => null]);
    $this->postJson($this->path.'/result', [...$this->finished, 'status' => 'failed', 'error_code' => 'process.remove_failed', 'result' => ['error_code' => 'process.remove_failed']])->assertOk();
    expect($this->server->fresh()->status->value)->toBe('active');
});

it('uses a native no-op for a failed server without a Process', function (): void {
    $server = DatabaseServer::query()->create(['slug' => 'empty', 'driver' => 'mysql', 'node_id' => $this->node->id, 'tag' => '8.4', 'port' => 3333, 'root_password' => 'private-empty', 'status' => 'failed']);
    $operation = app(DestroyDatabaseServerAction::class)->execute($server);
    $path = '/api/v1/direct-operations/'.$operation->id;
    $this->getJson($path.'?run_id='.$this->runId)->assertOk()->assertJsonPath('data.input.process_id', null)->assertJsonPath('data.input.container', null);
    $this->postJson($path.'/result', $this->finished)->assertOk();
    $this->assertModelMissing($server);
});

it('marks cancelled removal failed without deleting the Server or Process', function (): void {
    $this->postJson($this->path.'/result', [...$this->finished, 'status' => 'cancelled', 'result' => []])->assertOk();
    expect($this->server->fresh()->status->value)->toBe('failed');
    expect($this->server->fresh()->failed_step)->toBe('database-apply');
    $this->assertDatabaseHas('processes', ['id' => $this->server->process_id]);
});

it('refuses an unfinished server create before admitting removal', function (): void {
    $this->operation->update(['status' => 'cancelled', 'finished_at' => now()]);
    Operation::query()->create(['kind' => 'database_server.create', 'target_type' => 'database_server',
        'target_id' => $this->server->id, 'node_id' => $this->server->node_id, 'status' => 'queued', 'input' => []]);
    expect(fn () => app(DestroyDatabaseServerAction::class)->execute($this->server))
        ->toThrow(ResourceOperationException::class, 'unfinished create intent');
    expect(Operation::query()->where('kind', 'database_server.destroy')->count())->toBe(1);
});

it('refuses server create after removal admission before any remote execution', function (): void {
    expect(fn () => app(CreateDatabaseServerAction::class)->execute(
        new CreateDatabaseServerData('proof', $this->node->id, DatabaseDriver::Pgsql, '18', 5434)
    ))->toThrow(ResourceOperationException::class, 'unfinished removal intent');
    expect(Operation::query()->where('kind', 'database_server.create')->count())->toBe(0);
});

it('refuses a second removal while its first native run is unfinished', function (): void {
    expect(fn () => app(DestroyDatabaseServerAction::class)->execute($this->server))
        ->toThrow(ResourceOperationException::class, 'unfinished removal intent');
    expect(Operation::query()->where('kind', 'database_server.destroy')->count())->toBe(1);
});

it('refuses a stale active server model before an Instance reservation after removal admission', function (): void {
    $stale = clone $this->server;
    $stale->status = LifecycleStatus::Active;
    $stale->driver = DatabaseDriver::Mysql;
    $project = Project::query()->create(['name' => 'Proof', 'slug' => 'proof-reservation', 'repository_url' => 'https://example.test/proof.git', 'default_branch' => 'main']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id, 'name' => 'proof', 'environment' => 'development',
        'checkout_path' => '/srv/proof', 'source_is_laravel' => true, 'provisioning_step' => 'active', 'status' => 'active']);
    expect(fn () => app(CreateServerDatabaseAction::class)->reserve($stale, 'proof-reservation', 'proof_reservation', $instance, null))
        ->toThrow(ResourceOperationException::class, 'not active');
    $this->assertDatabaseCount('database_connections', 0);
});
