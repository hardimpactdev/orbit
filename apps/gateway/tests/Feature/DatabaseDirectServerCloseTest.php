<?php

declare(strict_types=1);

use App\Actions\DatabaseServers\CloseDatabaseServerAction;
use App\Domain\DatabaseServers\DatabaseServerCloseProjection;
use App\Domain\Operations\DirectOperation;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\DatabaseExecutionIntent;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\Process;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\Schedules\NativeScheduleReplies;

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
    $socket->reply = fn (array $sent): array => NativeScheduleReplies::for($sent)
        ?? [['type' => 'event', 'id' => $sent['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-database-server-close', 'runId' => $this->runId]]];
    app()->instance(WebSocketClient::class, $socket);
    $this->operation = app(CloseDatabaseServerAction::class)->execute($this->server);
    $this->path = '/api/v1/direct-operations/'.$this->operation->id;
    $this->finished = ['run_id' => $this->runId, 'status' => 'succeeded', 'started_at' => '2026-10-30T19:00:00.000Z', 'finished_at' => '2026-10-30T19:00:01.000Z', 'result' => ['steps' => ['Prove default is closed' => ['status' => 'succeeded']]]];
});

it('starts closure directly and exposes only its admitted endpoint', function (): void {
    expect($this->operation->lock_key)->toBeNull();
    $response = $this->getJson($this->path.'?run_id='.$this->runId)->assertOk()
        ->assertJsonPath('data.input.intent.default_open', true)->assertJsonPath('data.input.intent.port', 5434)->assertJsonMissingPath('data.input.agent_sha256');
    expect($response->getContent())->not->toContain('private-root-sentinel', 'payload');
    $this->postJson($this->path.'/primitive', ['run_id' => $this->runId])->assertUnprocessable();
    expect(array_column($this->socket->sent, 'type'))->toBe(['vault.put', 'vault.put', 'workflow.run']);
    $inputs = $this->socket->sent[2]['payload']['inputs'];
    $key = 'database-server-close-intent-'.$this->operation->input['intent_id'];
    expect($inputs['execution_secrets'])->toBe(['check' => ['vault' => 'orbit-control-inputs', 'key' => $key.'-check'], 'close' => ['vault' => 'orbit-control-inputs', 'key' => $key.'-close']])
        ->and($this->socket->sent[0]['payload']['value'])->toBe("AUTH default private-root-sentinel\nCLIENT LIST USER default\n")
        ->and($this->socket->sent[1]['payload']['value'])->toBe("AUTH default private-root-sentinel\nACL SETUSER default resetpass #".hash('sha256', 'private-root-sentinel')."\nACL SAVE\nCLIENT KILL USER default SKIPME yes\n");
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
    app(DatabaseServerCloseProjection::class)->apply($this->operation, [...$this->finished, 'status' => 'failed', 'error_code' => 'operation.native_failed', 'result' => [],
        'failure' => ['failed_step' => 'Prove no other client', 'detail' => 'assert', 'stderr_recorded' => false]]);
    expect($this->server->fresh()->default_open)->toBeTrue()
        ->and($this->operation->fresh()->error_code)->toBe('database.server_in_use');
});

it('reports any other failed step as a command failure and deletes both vault inputs', function (): void {
    app(DatabaseServerCloseProjection::class)->apply($this->operation, [...$this->finished, 'status' => 'failed', 'error_code' => 'operation.native_failed', 'result' => [],
        'failure' => ['failed_step' => 'Prove the ACL is saved', 'detail' => 'assert', 'stderr_recorded' => false]]);
    $key = 'database-server-close-intent-'.$this->operation->input['intent_id'];
    expect($this->operation->fresh()->error_code)->toBe('database.server_command_failed')
        ->and($this->server->fresh()->default_open)->toBeTrue()
        ->and(array_map(static fn (array $sent): string => $sent['payload']['key'], array_values(array_filter($this->socket->sent, static fn (array $sent): bool => $sent['type'] === 'vault.delete'))))
        ->toBe([$key.'-check', $key.'-close']);
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

it('uses admitted endpoint and password after server metadata changes', function (): void {
    $before = $this->socket->sent[2]['payload']['inputs'];
    $this->server->update(['port' => 6500, 'root_password' => 'different-private-root', 'default_open' => false]);
    expect(app(DirectOperation::class)->workflowInputs($this->operation))->toEqual($before);
});

it('projects shared terminal history once without a family callback', function (): void {
    $inputs = $this->socket->sent[2]['payload']['inputs'];
    $history = ['id' => $this->runId, 'workflowName' => 'operation-database-server-close', 'status' => 'succeeded', 'inputs' => $inputs,
        'startedAt' => '2026-11-01T02:00:00Z', 'completedAt' => '2026-11-01T02:00:01Z',
        'jobs' => [['name' => 'closure', 'steps' => [['name' => 'Prove default is closed', 'status' => 'succeeded']]]],
    ];
    $this->socket->reply = static fn (array $sent): array => NativeScheduleReplies::for($sent) ?? [['type' => 'workflow.history.get', 'id' => $sent['id'], 'payload' => ['data' => $history]]];
    config(['swamp.read_token' => 'fake.token']);
    $selector = app(DirectOperation::class)->definition('database_server.close')['terminal_result'];
    $result = app(SwampCommandStep::class)->projectTerminal($this->operation, ['id' => $this->runId, 'status' => 'succeeded'], $selector);
    app(SwampCommandStep::class)->projectTerminal($result, ['id' => $this->runId, 'status' => 'succeeded'], $selector);
    expect($result->status->value)->toBe('succeeded')->and($this->server->fresh()->default_open)->toBeFalse();
    expect(array_slice(array_column($this->socket->sent, 'type'), 0, 4))->toBe(['vault.put', 'vault.put', 'workflow.run', 'workflow.history.get']);
});

it('preserves interrupted status when native execution has no family outcome', function (): void {
    $result = app(DatabaseServerCloseProjection::class)->apply($this->operation, [...$this->finished, 'status' => 'interrupted', 'error_code' => 'operation.native_failed', 'result' => ['error_code' => 'operation.native_failed']]);
    expect($result->status->value)->toBe('interrupted');
    expect($this->server->fresh()->default_open)->toBeTrue();
});
