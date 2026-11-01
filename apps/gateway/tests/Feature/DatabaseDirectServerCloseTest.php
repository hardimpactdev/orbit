<?php

declare(strict_types=1);

use App\Actions\DatabaseServers\CloseDatabaseServerAction;
use App\Data\DatabaseServers\DatabaseServerData;
use App\Domain\DatabaseServers\DatabaseServerCloseProjection;
use App\Domain\DatabaseServers\ValkeyAcl;
use App\Domain\Operations\DirectOperation;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\SwampCommandStep;
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
    $socket = $this->socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $sent): array => $sent['type'] === 'vault.put'
        ? [['type' => 'vault.put', 'id' => $sent['id'], 'payload' => ['data' => ['vaultName' => $sent['payload']['vaultName'], 'secretKey' => $sent['payload']['key'], 'vaultType' => 'local_encryption']]]]
        : [['type' => 'event', 'id' => $sent['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-database-server-close', 'runId' => $this->runId]]];
    app()->instance(WebSocketClient::class, $socket);
    $this->operation = app(CloseDatabaseServerAction::class)->execute($this->server);
    $this->path = '/api/v1/direct-operations/'.$this->operation->id;
    $this->finished = ['run_id' => $this->runId, 'status' => 'succeeded', 'started_at' => '2026-10-30T19:00:00.000Z', 'finished_at' => '2026-10-30T19:00:01.000Z', 'result' => ['backend' => 'active']];
});

it('starts closure directly and exposes only its admitted endpoint', function (): void {
    expect($this->operation->lock_key)->toBeNull();
    $response = $this->getJson($this->path.'?run_id='.$this->runId)->assertOk()
        ->assertJsonPath('data.input.intent.default_open', true)->assertJsonPath('data.input.intent.port', 5434)->assertJsonMissingPath('data.input.agent_sha256');
    expect($response->getContent())->not->toContain('private-root-sentinel', 'payload');
    $this->postJson($this->path.'/primitive', ['run_id' => $this->runId])->assertUnprocessable();
    expect(array_column($this->socket->sent, 'type'))->toBe(['vault.put', 'workflow.run']);
    $inputs = $this->socket->sent[1]['payload']['inputs'];
    expect($inputs['execution_secrets']['server_close'])->toBe(['vault' => 'orbit-control-inputs', 'key' => 'database-server-close-intent-'.$this->operation->input['intent_id']]);
    expect(json_decode($this->socket->sent[0]['payload']['value'], true, flags: JSON_THROW_ON_ERROR))->toBe(['password' => 'private-root-sentinel']);
    expect(json_encode($inputs, JSON_THROW_ON_ERROR))->not->toContain('private-root-sentinel');
    expect(app(DirectOperation::class)->definition('database_server.close'))->not->toHaveKey('private_material');

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
});

it('keeps the admitted endpoint and default-user state on native refusal', function (): void {
    $this->server->update(['port' => 5435]);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertOk()->assertJsonPath('data.input.intent.port', 5434);
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

it('does not mark access unknown without the complete typed unknown result', function (): void {
    $failed = [...$this->finished, 'status' => 'failed', 'error_code' => 'database.server_restore_failed',
        'result' => ['error_code' => 'database.server_restore_failed']];
    $this->postJson($this->path.'/result', $failed)->assertOk();
    expect($this->server->fresh()->default_open)->toBeTrue();
});

it('uses admitted endpoint and password after server metadata changes', function (): void {
    $before = $this->socket->sent[1]['payload']['inputs'];
    $this->server->update(['port' => 6500, 'root_password' => 'different-private-root', 'default_open' => false]);
    expect(app(DirectOperation::class)->workflowInputs($this->operation))->toEqual($before);
});

it('projects shared terminal history once without a family callback', function (): void {
    $inputs = $this->socket->sent[1]['payload']['inputs'];
    $history = ['id' => $this->runId, 'workflowName' => 'operation-database-server-close', 'status' => 'succeeded', 'inputs' => $inputs,
        'startedAt' => '2026-11-01T02:00:00Z', 'completedAt' => '2026-11-01T02:00:01Z',
        'jobs' => [['name' => 'closure', 'steps' => [['name' => 'result', 'outputs' => ['stdout' => '{"backend":"active"}']]]]],
    ];
    $this->socket->reply = static fn (array $sent): array => [['type' => 'workflow.history.get', 'id' => $sent['id'], 'payload' => ['data' => $history]]];
    config(['swamp.read_token' => 'fake.token']);
    $selector = app(DirectOperation::class)->definition('database_server.close')['terminal_result'];
    $result = app(SwampCommandStep::class)->projectTerminal($this->operation, ['id' => $this->runId, 'status' => 'succeeded'], $selector);
    app(SwampCommandStep::class)->projectTerminal($result, ['id' => $this->runId, 'status' => 'succeeded'], $selector);
    expect($result->status->value)->toBe('succeeded')->and($this->server->fresh()->default_open)->toBeFalse();
    expect(array_column($this->socket->sent, 'type'))->toBe(['vault.put', 'workflow.run', 'workflow.history.get']);
});

it('preserves interrupted status when native execution has no family outcome', function (): void {
    $result = app(DatabaseServerCloseProjection::class)->apply($this->operation, [...$this->finished, 'status' => 'interrupted', 'error_code' => 'operation.native_failed', 'result' => ['error_code' => 'operation.native_failed']]);
    expect($result->status->value)->toBe('interrupted');
    expect($this->server->fresh()->default_open)->toBeTrue();
});

it('retains actual cancelled status and unknown access from native compensation', function (): void {
    Log::spy();
    $inputs = $this->socket->sent[1]['payload']['inputs'];
    $history = ['id' => $this->runId, 'workflowName' => 'operation-database-server-close', 'status' => 'cancelled', 'inputs' => $inputs,
        'startedAt' => '2026-11-01T02:00:00Z', 'completedAt' => '2026-11-01T02:00:01Z',
        'jobs' => [['name' => 'closure', 'steps' => [['name' => 'result', 'outputs' => ['stdout' => '{"error_code":"database.server_restore_failed","default_open":"unknown"}']]]]],
    ];
    $this->socket->reply = static fn (array $sent): array => [['type' => 'workflow.history.get', 'id' => $sent['id'], 'payload' => ['data' => $history]]];
    config(['swamp.read_token' => 'fake.token']);
    $selector = app(DirectOperation::class)->definition('database_server.close')['terminal_result'];
    $result = app(SwampCommandStep::class)->projectTerminal($this->operation, ['id' => $this->runId, 'status' => 'cancelled'], $selector);
    expect($result->status->value)->toBe('cancelled')->and($this->server->fresh()->default_open)->toBeNull();
    expect($this->server->fresh()->error_code)->toBe('database.default_access_unknown');
    Log::shouldHaveReceived('critical')->once();
});
