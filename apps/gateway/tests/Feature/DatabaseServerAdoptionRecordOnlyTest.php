<?php

declare(strict_types=1);

use App\Actions\DatabaseServers\DestroyDatabaseServerAction;
use App\Actions\DatabaseServers\PurgeDatabaseExecutionIntentsAction;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\DatabaseExecutionIntent;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->gateway = $this->markAsGateway(Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1']));
    $this->node = Node::query()->create(['name' => 'database-dev', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'amd64', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9']);
    $this->node->accessibleNodes()->attach($this->gateway);
    config(['swamp.run_token' => 'fake.token']);
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

it('preserves a never-active adopted Process while deleting only its failed server record', function (bool $wasActive, bool $succeeded, bool $validateExisting, bool $purged, bool $earlierActive): void {
    $this->operation->update(['status' => 'cancelled', 'finished_at' => now()]);
    $this->server->update(['status' => 'failed']);
    $process = $this->server->process;
    $before = $process->getAttributes();
    $intent = DatabaseExecutionIntent::query()->create(['target_type' => 'database_server', 'target_id' => $this->server->id, 'node_id' => $this->node->id,
        'payload' => ['commands' => [], 'projection' => [], 'admission' => ['kind' => 'database_server.create', 'input' => [
            'server_id' => $this->server->id, 'process_id' => $process->id, 'validate_existing' => $validateExisting, 'was_active' => $wasActive,
        ]]]]);
    if ($earlierActive) {
        Operation::query()->create(['kind' => 'database_server.create', 'target_type' => 'database_server', 'target_id' => $this->server->id,
            'node_id' => $this->node->id, 'status' => 'failed', 'input' => ['was_active' => true]]);
    }
    $create = Operation::query()->create(['kind' => 'database_server.create', 'target_type' => 'database_server', 'target_id' => $this->server->id,
        'node_id' => $this->node->id, 'status' => $succeeded ? 'succeeded' : 'failed', 'input' => [...$intent->payload['admission']['input'], 'intent_id' => $intent->id]]);
    if ($purged) {
        $intent->update(['created_at' => now()->subDays(2)]);
        $create->update(['finished_at' => now()->subDays(2)]);
        app(PurgeDatabaseExecutionIntentsAction::class)->execute();
        $this->assertModelMissing($intent);
    }
    $operation = app(DestroyDatabaseServerAction::class)->execute($this->server);
    $path = '/api/v1/direct-operations/'.$operation->id;
    $preserve = ! $wasActive && ! $succeeded && $validateExisting && ! $earlierActive;
    $this->getJson($path.'?run_id='.$this->runId)->assertOk()->assertJsonPath('data.input.process_id', $preserve ? null : $process->id);
    $this->postJson($path.'/result', $this->finished)->assertOk();
    $this->assertModelMissing($this->server);
    if ($preserve) {
        expect($process->fresh()->getAttributes())->toBe($before);
        $this->postJson($path.'/result', $this->finished)->assertOk();
        expect($process->fresh()->getAttributes())->toBe($before);
    } else {
        $this->assertModelMissing($process);
    }
})->with([[false, false, true, false, false], [true, false, true, false, false], [false, true, true, false, false], [false, false, false, false, false], [false, false, true, true, false], [false, false, true, false, true]]);
