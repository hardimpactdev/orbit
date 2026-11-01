<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WebSocket\DirectWebSocketProjection;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Node;
use App\Models\Operation;
use App\Models\WebSocketApp;
use App\Models\WebSocketRuntime;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->gateway = $this->markAsGateway(Node::query()->create(['name' => 'native-registry-gateway', 'platform' => 'linux', 'status' => 'active', 'public_ssh_host' => '192.0.2.14', 'wireguard_ip' => '10.44.0.14', 'user' => 'orbit']));
    $this->server = Node::query()->create(['name' => 'native-registry-server', 'platform' => 'linux', 'status' => 'active', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9', 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-pin']);
    $this->runtime = WebSocketRuntime::query()->create(['id' => (string) Str::uuid(), 'node_id' => $this->server->id, 'registry_token' => 'private-registry-fixture']);
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    config(['swamp.run_token' => 'fixture.shared']);
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = static fn (array $request): array => $request['type'] === 'vault.put'
        ? [['type' => 'vault.put', 'id' => $request['id'], 'payload' => ['data' => ['vaultName' => $request['payload']['vaultName'], 'secretKey' => $request['payload']['key'], 'vaultType' => 'local_encryption']]]]
        : [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-websocket-registry', 'runId' => (string) Str::uuid()]]];
    app()->instance(WebSocketClient::class, $this->socket);
});

it('declares full-input native terminal ownership for each registry kind', function (string $kind): void {
    $direct = app(DirectOperation::class);
    expect($direct->usesNativeTerminal(new Operation(['kind' => $kind, 'node_id' => $this->gateway->id])))->toBeTrue()
        ->and($direct->definition($kind)['workflow_inputs'])->toBeTrue()
        ->and($direct->definition($kind)['terminal_result'])->toBe(['job' => 'result', 'step' => 'result']);
})->with(['websocket.sync', 'websocket.app_create', 'websocket.app_destroy', 'websocket.app_rotate']);

it('freezes public intent and sends only names-only credentials to the workflow', function (): void {
    $response = $this->withHeader('Idempotency-Key', 'native-create')->postJson('/api/v1/websocket-apps', ['name' => 'native-disposable'])->assertAccepted()->assertDontSee('private-registry-fixture');
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    $app = WebSocketApp::query()->where('name', 'native-disposable')->sole();
    $sent = collect($this->socket->sent);
    $inputs = $sent->firstWhere('type', 'workflow.run')['payload']['inputs'];
    $field = 'runtime_'.str_replace('-', '_', $this->runtime->id);
    expect($inputs['intent']['app_id'])->toBe($app->app_id)->and($inputs['intent']['change'])->toBe('create')
        ->and($inputs['intent']['runtimes'][0]['ssh']['target']['host_keys'])->toBe(['ssh-ed25519 fixture-pin'])
        ->and($inputs['execution_secrets'])->toBe([$field => ['vault' => 'orbit-control-inputs', 'key' => 'websocket-registry-'.$this->runtime->id]])
        ->and(json_encode($inputs))->not->toContain('private-registry-fixture', $app->secret)
        ->and($sent->where('type', 'vault.put'))->toHaveCount(1)->and($operation->lock_key)->toBeNull();
    $this->server->update(['wireguard_ip' => '10.44.0.99', 'ssh_host_key' => 'changed']);
    $this->runtime->update(['registry_token' => 'changed-private-fixture']);
    expect(app(DirectWebSocketProjection::class)->input($operation)['intent'])->toBe($inputs['intent']);
    Http::assertNothingSent();
});

it('replays admission without another secret copy or root after target state changes', function (): void {
    $first = $this->withHeader('Idempotency-Key', 'native-replay')->postJson('/api/v1/websocket-apps', ['name' => 'native-replay'])->assertAccepted();
    $this->server->update(['ssh_host_key' => null]);
    $this->postJson('/api/v1/websocket-apps', ['name' => 'native-replay'])->assertAccepted()->assertJsonPath('data.id', $first->json('data.id'));
    expect(collect($this->socket->sent)->where('type', 'workflow.run'))->toHaveCount(1)
        ->and(collect($this->socket->sent)->where('type', 'vault.put'))->toHaveCount(1)
        ->and(WebSocketApp::query()->where('name', 'native-replay')->count())->toBe(1);
});

it('refuses missing pins before any execution copy or root and rolls back uncommitted app intent', function (): void {
    $other = Node::query()->create(['name' => 'missing-pin', 'platform' => 'linux', 'status' => 'active', 'public_ssh_host' => '192.0.2.10', 'wireguard_ip' => '10.44.0.10', 'user' => 'orbit']);
    WebSocketRuntime::query()->create(['id' => (string) Str::uuid(), 'node_id' => $other->id, 'registry_token' => 'another-private-fixture']);
    $this->withHeader('Idempotency-Key', 'missing-pin')->postJson('/api/v1/websocket-apps', ['name' => 'missing-pin'])->assertStatus(503)->assertJsonPath('error.code', 'swamp.ssh_target_unavailable');
    expect($this->socket->sent)->toBe([])->and(WebSocketApp::query()->where('name', 'missing-pin')->exists())->toBeFalse();
});

it('projects the exact native terminal once without re-reading the current registry', function (string $native, string $expected, array $public): void {
    $response = $this->withHeader('Idempotency-Key', 'native-terminal')->postJson('/api/v1/websocket-apps', ['name' => 'native-terminal'])->assertAccepted();
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    $run = $operation->state['swamp_run_id'];
    $history = ['id' => $run, 'workflowName' => 'operation-websocket-registry', 'status' => $native,
        'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => $operation->kind],
        'startedAt' => '2026-11-01T11:00:00Z', 'completedAt' => '2026-11-01T11:00:01Z',
        'jobs' => [['name' => 'result', 'steps' => [['name' => 'result', 'outputs' => ['stdout' => json_encode((object) $public, JSON_THROW_ON_ERROR)]]]]]];
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $request): array => [['type' => 'workflow.history.get', 'id' => $request['id'], 'payload' => ['data' => $history]]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.read_token' => 'synthetic-native-reader']);
    $this->runtime->update(['boot_id' => (string) Str::uuid(), 'applied_revision' => 0]);
    $commands = app(SwampCommandStep::class);

    $projected = $commands->projectTerminal($operation, ['id' => $run, 'status' => $native], ['job' => 'result', 'step' => 'result']);
    $sequence = $projected->sequence;
    $again = $commands->projectTerminal($projected, ['id' => $run, 'status' => $native], ['job' => 'result', 'step' => 'result']);

    expect($again->status->value)->toBe($expected)->and($again->sequence)->toBe($sequence)
        ->and($again->result)->toBe($public)->and($socket->sent)->toHaveCount(1)
        ->and(WebSocketApp::query()->where('name', 'native-terminal')->exists())->toBeTrue();
})->with([
    ['succeeded', 'succeeded', ['outcome' => 'succeeded', 'data' => ['backend' => 'applied']]],
    ['failed', 'failed', ['error_code' => 'websocket.registry_pending']],
    ['interrupted', 'interrupted', ['error_code' => 'websocket.registry_unavailable']],
    ['cancelled', 'cancelled', []],
]);

it('refuses foreign native history before projecting a registry operation', function (string $field): void {
    $response = $this->postJson('/api/v1/websocket-sync')->assertAccepted();
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    $run = $operation->state['swamp_run_id'];
    $history = ['id' => $run, 'workflowName' => 'operation-websocket-registry', 'status' => 'succeeded',
        'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => $operation->kind],
        'startedAt' => '2026-11-01T11:00:00Z', 'completedAt' => '2026-11-01T11:00:01Z', 'jobs' => []];
    data_set($history, $field, match ($field) {
        'id' => (string) Str::uuid(), 'workflowName' => 'operation-other',
        'inputs.kind' => 'websocket.app_create', default => 999999,
    });
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $request): array => [['type' => 'workflow.history.get', 'id' => $request['id'], 'payload' => ['data' => $history]]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.read_token' => 'synthetic-native-reader']);

    expect(fn () => app(SwampCommandStep::class)->projectTerminal($operation, ['id' => $run, 'status' => 'succeeded'], ['job' => 'result', 'step' => 'result']))
        ->toThrow(ResourceOperationException::class);
    expect($operation->fresh()->status->value)->toBe('running')->and($operation->fresh()->result)->toBeNull();
})->with(['id', 'workflowName', 'inputs.operation_id', 'inputs.node_id', 'inputs.kind']);

it('refuses a nondefault runtime before secret copies or native admission', function (): void {
    $this->runtime->update(['slot' => 'second']);

    $this->postJson('/api/v1/websocket-apps', ['name' => 'unsupported-slot'])->assertConflict()
        ->assertJsonPath('error.code', 'websocket.runtime_slot_unsupported');

    expect($this->socket->sent)->toBe([])->and(WebSocketApp::query()->where('name', 'unsupported-slot')->exists())->toBeFalse()
        ->and(Operation::query()->count())->toBe(0);
});
