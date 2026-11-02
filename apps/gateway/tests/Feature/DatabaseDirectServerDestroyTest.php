<?php

declare(strict_types=1);

use App\Actions\DatabaseServers\CreateDatabaseServerAction;
use App\Actions\DatabaseServers\DestroyDatabaseServerAction;
use App\Data\DatabaseServers\CreateDatabaseServerData;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->gateway = $this->markAsGateway(Node::query()->create(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAINqVuK+N4gL/sDvGNKnGrYhEtEFnZx9hvH+134PsQgND', 'name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1']));
    $this->node = Node::query()->create(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAINqVuK+N4gL/sDvGNKnGrYhEtEFnZx9hvH+134PsQgND', 'name' => 'database-dev', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'amd64', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9']);
    $this->node->accessibleNodes()->attach($this->gateway);
    config(['swamp.run_token' => 'fake.token']);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $this->node->id, 'name' => 'proof', 'runtime' => 'docker', 'runtime_config' => ['image' => 'postgres:18'], 'restart_policy' => 'unless-stopped', 'desired_state' => 'running', 'status' => 'active']);
    $this->server = DatabaseServer::query()->create(['slug' => 'proof', 'driver' => 'pgsql', 'node_id' => $this->node->id, 'process_id' => $process->id, 'tag' => '18', 'port' => 5434, 'root_password' => 'private-root-sentinel', 'status' => 'active']);
    $this->runId = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $socket = $this->socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $sent): array => [['type' => 'event', 'id' => $sent['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-database-server-destroy', 'runId' => $this->runId]]];
    app()->instance(WebSocketClient::class, $socket);
    $this->operation = app(DestroyDatabaseServerAction::class)->execute($this->server);
    $this->path = '/api/v1/direct-operations/'.$this->operation->id;
    $this->finished = ['run_id' => $this->runId, 'status' => 'succeeded', 'started_at' => '2026-10-30T19:00:00.000Z', 'finished_at' => '2026-10-30T19:00:01.000Z', 'result' => ['backend' => 'absent']];
});

it('starts removal without a relay or target hold and deletes only after native success', function (): void {
    expect($this->operation->state)->toMatchArray(['swamp_run_id' => $this->runId]);
    expect($this->operation->lock_key)->toBeNull();
    $this->assertDatabaseCount('database_execution_intents', 0);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertOk()->assertJsonPath('data.input.intent.container', 'orbit-process-'.$this->server->process_id.'-proof')->assertJsonMissingPath('data.input.agent_sha256');
    $this->postJson($this->path.'/primitive', ['run_id' => $this->runId])->assertUnprocessable();
    $this->assertModelExists($this->server);
    $processId = $this->server->process_id;
    $this->postJson($this->path.'/result', $this->finished)->assertOk();
    $this->assertModelMissing($this->server);
    $this->assertDatabaseMissing('processes', ['id' => $processId]);
    $this->postJson($this->path.'/result', $this->finished)->assertOk();
    $this->getJson($this->path.'?run_id='.$this->runId)->assertOk();
});

it('projects admitted Process deletion without rechecking mutable server state', function (): void {
    $processId = $this->server->process_id;
    $this->server->update(['process_id' => null]);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertOk()->assertJsonPath('data.input.intent.container', 'orbit-process-'.$processId.'-proof');
    $this->postJson($this->path.'/result', $this->finished)->assertOk();
    $this->assertModelMissing($this->server);
    $this->assertDatabaseMissing('processes', ['id' => $processId]);
    expect($this->operation->fresh()->state)->not->toHaveKey('steps');
});

it('keeps records on a bounded native failure', function (): void {
    $this->postJson($this->path.'/result', [...$this->finished, 'status' => 'failed', 'error_code' => 'process.remove_failed', 'result' => ['error_code' => 'process.remove_failed']])->assertOk();
    $this->assertModelExists($this->server);
    expect($this->server->fresh()->status->value)->toBe('failed');
    expect($this->server->fresh()->error_code)->toBe('process.remove_failed');
    $this->server->refresh()->update(['status' => 'active', 'error_code' => null]);
    $this->postJson($this->path.'/result', [...$this->finished, 'status' => 'failed', 'error_code' => 'process.remove_failed', 'result' => ['error_code' => 'process.remove_failed']])->assertOk();
    expect($this->server->fresh()->status->value)->toBe('active');
});

it('removes a server without a Process at once with no run (R1)', function (): void {
    $server = DatabaseServer::query()->create(['slug' => 'empty', 'driver' => 'mysql', 'node_id' => $this->node->id, 'tag' => '8.4', 'port' => 3333, 'root_password' => 'private-empty', 'status' => 'failed']);
    $sent = count($this->socket->sent);
    $operation = app(DestroyDatabaseServerAction::class)->execute($server);
    expect([$operation->status->value, $operation->result, $operation->lock_key])->toBe(['succeeded', ['backend' => 'absent'], null]);
    expect(count($this->socket->sent))->toBe($sent);
    $this->assertModelMissing($server);
});

it('marks cancelled removal failed without deleting the Server or Process', function (): void {
    $this->postJson($this->path.'/result', [...$this->finished, 'status' => 'cancelled', 'result' => []])->assertOk();
    expect($this->server->fresh()->status->value)->toBe('failed');
    expect($this->server->fresh()->failed_step)->toBeNull();
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

it('maps the failed step of the native result to the server error code', function (string $step, string $code): void {
    $this->postJson($this->path.'/result', [...$this->finished, 'status' => 'failed', 'error_code' => 'swamp.step_failed',
        'result' => ['steps' => [$step => ['status' => 'failed']], 'summary' => ['status' => 'failed', 'succeeded' => 0, 'failed' => 1, 'skipped' => 0, 'failed_steps' => [$step]]]])->assertOk();
    expect([$this->server->fresh()->status->value, $this->server->fresh()->failed_step, $this->server->fresh()->error_code])->toBe(['failed', $step, $code]);
    $this->assertDatabaseHas('processes', ['id' => $this->server->process_id]);
})->with([['Remove the container', 'database.server_remove_failed'], ['Check it is gone', 'database.server_remove_incomplete']]);

it('passes full public removal inputs and projects only bounded fields', function (): void {
    $sent = array_last($this->socket->sent);
    $input = $sent['payload']['inputs'];
    expect($input['intent']['container'])->toBe('orbit-process-'.$this->server->process_id.'-proof');
    expect($input['intent']['ssh_execution']['target'])->toMatchArray(['host' => '10.44.0.9', 'user' => 'orbit']);
    expect((array) $input['execution_secrets'])->toBe([]);
    expect(json_encode($input))->not->toContain('private-root-sentinel');
    $response = $this->postJson($this->path.'/result', [...$this->finished, 'result' => ['backend' => 'absent', 'private' => 'private-sentinel']])->assertOk();
    expect($response->json('data.result'))->toBe(['backend' => 'absent']);
});
