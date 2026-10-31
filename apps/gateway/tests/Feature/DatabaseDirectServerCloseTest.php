<?php

declare(strict_types=1);

use App\Actions\DatabaseServers\CloseDatabaseServerAction;
use App\Data\DatabaseServers\DatabaseServerData;
use App\Domain\DatabaseServers\ValkeyAcl;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\DatabaseExecutionIntent;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->gateway = $this->markAsGateway(Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1']));
    $this->node = Node::query()->create(['name' => 'database-dev', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'amd64', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9']);
    $this->node->accessibleNodes()->attach($this->gateway);
    config(['swamp.run_token' => 'fake.token']);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $this->node->id, 'name' => 'proof', 'runtime' => 'docker', 'runtime_config' => [], 'restart_policy' => 'unless-stopped', 'desired_state' => 'running', 'status' => 'active']);
    $this->server = DatabaseServer::query()->create(['slug' => 'proof', 'driver' => 'redis', 'node_id' => $this->node->id, 'process_id' => $process->id, 'tag' => '18', 'port' => 5434, 'root_password' => 'private-root-sentinel', 'status' => 'active', 'default_open' => true]);
    $this->runId = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $sent): array => [['type' => 'event', 'id' => $sent['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-database-server-close', 'runId' => $this->runId]]];
    app()->instance(WebSocketClient::class, $socket);
    $this->operation = app(CloseDatabaseServerAction::class)->execute($this->server);
    $this->path = '/api/v1/direct-operations/'.$this->operation->id;
    $this->finished = ['run_id' => $this->runId, 'status' => 'succeeded', 'started_at' => '2026-10-30T19:00:00.000Z', 'finished_at' => '2026-10-30T19:00:01.000Z', 'result' => ['backend' => 'active']];
});

it('starts closure directly and exposes only its admitted endpoint', function (): void {
    expect($this->operation->lock_key)->toBeNull();
    $response = $this->getJson($this->path.'?run_id='.$this->runId)->assertOk()
        ->assertJsonPath('data.input.default_open', true)->assertJsonPath('data.input.port', 5434)->assertJsonMissingPath('data.input.agent_sha256');
    expect($response->getContent())->not->toContain('private-root-sentinel', 'payload');
    $this->postJson($this->path.'/primitive', ['run_id' => $this->runId])->assertUnprocessable();
    $this->getJson($this->path.'/private-material?run_id='.$this->runId)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data', ['password' => 'private-root-sentinel']);
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $this->getJson($this->path.'/private-material?run_id='.$this->runId)->assertForbidden();
});

it('projects closure once and cannot overwrite a newer server state on terminal replay', function (): void {
    expect($this->server->fresh()->default_open)->toBeTrue();
    $intent = DatabaseExecutionIntent::recorded($this->operation->input['intent_id']);
    $payload = $intent->payload;
    unset($payload['commands']['database-apply']['protected']);
    $intent->update(['payload' => $payload]);
    $this->postJson($this->path.'/result', $this->finished)->assertOk();
    expect($this->server->fresh()->default_open)->toBeFalse();
    $this->server->fresh()->update(['default_open' => true]);
    $this->postJson($this->path.'/result', $this->finished)->assertOk();
    expect($this->server->fresh()->default_open)->toBeTrue();
    $this->getJson($this->path.'/private-material?run_id='.$this->runId)->assertConflict();
});

it('keeps the admitted endpoint and default-user state on native refusal', function (): void {
    $this->server->update(['port' => 5435]);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertOk()->assertJsonPath('data.input.port', 5434);
    $this->postJson($this->path.'/result', [...$this->finished, 'status' => 'failed', 'error_code' => 'database.server_in_use', 'result' => ['error_code' => 'database.server_in_use']])->assertOk();
    expect($this->server->fresh()->default_open)->toBeTrue();
});

it('records unknown default access and alerts once when restoring nopass fails', function (): void {
    Log::spy();
    $failed = [...$this->finished, 'status' => 'failed', 'error_code' => 'database.server_restore_failed',
        'result' => ['error_code' => 'database.server_restore_failed', 'default_open' => 'unknown']];
    $this->postJson($this->path.'/result', $failed)->assertOk()->assertJsonPath('data.result.default_open', 'unknown');
    $server = $this->server->fresh();
    expect($server->default_open)->toBeNull()
        ->and($server->status->value)->toBe('failed')
        ->and($server->error_code)->toBe('database.default_access_unknown');
    expect(DatabaseServerData::fromModel($server)->toArray()['default_open'])->toBeNull();
    expect(fn () => ValkeyAcl::file($server))->toThrow(LogicException::class);
    $this->postJson($this->path.'/result', $failed)->assertOk();
    Log::shouldHaveReceived('critical')->once()->with(
        'Database default access is unknown after ACL restore failed.',
        ['operation_id' => $this->operation->id, 'database_server_id' => $server->id, 'node_id' => $server->node_id],
    );
});

it('preserves verified boolean access facts when the nullable schema is applied', function (): void {
    $closed = $this->server->replicate()->fill(['slug' => 'closed-proof', 'default_open' => false]);
    $closed->save();
    $migration = require database_path('migrations/2027_01_09_120015_allow_unknown_database_default_access.php');
    $migration->up();
    expect($this->server->fresh()->default_open)->toBeTrue();
    expect($closed->fresh()->default_open)->toBeFalse();
    $this->server->update(['default_open' => null]);
    expect($this->server->fresh()->default_open)->toBeNull();
});

it('refuses an incomplete unknown-state result without changing access', function (): void {
    $failed = [...$this->finished, 'status' => 'failed', 'error_code' => 'database.server_restore_failed',
        'result' => ['error_code' => 'database.server_restore_failed']];
    $this->postJson($this->path.'/result', $failed)->assertUnprocessable();
    expect($this->server->fresh()->default_open)->toBeTrue();
});
