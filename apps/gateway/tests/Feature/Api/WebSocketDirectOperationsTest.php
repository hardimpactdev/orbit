<?php

declare(strict_types=1);

use App\Actions\WebSocket\ChangeWebSocketRegistryAction;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WebSocket\WebSocketRegistry;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Models\Node;
use App\Models\Operation;
use App\Models\WebSocketApp;
use App\Models\WebSocketRegistryOperation;
use App\Models\WebSocketRuntime;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->gateway = $this->markAsGateway(Node::query()->create(['name' => 'direct-registry-gateway', 'platform' => 'linux', 'status' => 'active',
        'wireguard_ip' => '10.44.0.14', 'public_ssh_host' => '192.0.2.14', 'user' => 'orbit']));
    $registry = app(ChangeWebSocketRegistryAction::class)->execute('sync', null, $this->gateway->id, 'direct-fixture');
    $this->registry = $registry;
    $this->run = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    config(['swamp.run_token' => 'fixture.shared', 'swamp.direct_writers' => [], 'operation_executor.worker_node_ids' => [$this->gateway->id]]);
    $this->operation = Operation::query()->create(['kind' => 'websocket.sync', 'target_type' => 'websocket_sync', 'target_id' => $registry->revision,
        'node_id' => $this->gateway->id, 'status' => 'queued', 'sequence' => 1,
        'input' => ['registry_operation_id' => $registry->id, 'epoch' => $registry->epoch, 'revision' => $registry->revision, 'password' => 'private-sentinel'],
        'state' => ['websocket_registry_writer' => 'direct', 'swamp_run_id' => $this->run]]);
    $this->path = '/api/v1/direct-operations/'.$this->operation->id;
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => [
        'kind' => 'started', 'workflowName' => 'operation-websocket-registry', 'runId' => $this->run]]];
    app()->instance(WebSocketClient::class, $socket);
    $this->body = ['run_id' => $this->run, 'status' => 'succeeded', 'started_at' => '2026-10-30T18:30:00.000Z', 'finished_at' => '2026-10-30T18:30:01.000Z',
        'result' => ['outcome' => 'succeeded', 'data' => ['backend' => 'applied'], 'secret' => 'private-sentinel']];
});

it('selects direct sync by default and binds root starts before exposing safe input', function (): void {
    foreach (['websocket.sync', 'websocket.app_create', 'websocket.app_destroy', 'websocket.app_rotate'] as $kind) {
        expect(app(DirectOperation::class)->writer($kind))->toBe(in_array($kind, ['websocket.sync', 'websocket.app_create', 'websocket.app_destroy'], true) ? 'direct' : null);
    }
    $this->operation->update(['state' => ['websocket_registry_writer' => 'direct']]);
    $this->getJson($this->path.'?run_id='.$this->run)->assertConflict()->assertJsonPath('error.code', 'operation.run_unbound');
    app(StartSwampRun::class)->start($this->operation);
    $this->getJson($this->path.'?run_id='.$this->run)->assertOk()->assertJsonPath('data.input', [
        'registry_operation_id' => $this->registry->id, 'epoch' => $this->registry->epoch, 'revision' => $this->registry->revision,
    ])->assertDontSee('private-sentinel');
    Http::assertNothingSent();
});

it('starts direct sync from the existing endpoint without an execution lease or target hold', function (): void {
    $this->operation->delete();

    $response = $this->withHeader('Idempotency-Key', 'direct-sync-start')->postJson('/api/v1/websocket-sync')->assertAccepted();

    $operation = Operation::query()->findOrFail($response->json('data.id'));
    expect($operation->kind)->toBe('websocket.sync')->and($operation->state['websocket_registry_writer'])->toBe('direct');
    expect($operation->state['swamp_run_id'])->toBe($this->run)->and($operation->lock_key)->toBeNull()->and($operation->attempts)->toBe(0);
    Http::assertNothingSent();
});

it('rejects forged success until every current required Reverb boot has applied the registry', function (): void {
    $this->postJson($this->path.'/result', $this->body)->assertConflict()->assertJsonPath('error.code', 'websocket.registry_pending');
    $version = app(WebSocketRegistry::class)->version();
    $runtime = WebSocketRuntime::query()->create(['id' => (string) Str::uuid(), 'node_id' => $this->gateway->id, 'registry_token' => 'test-only',
        'boot_id' => (string) Str::uuid(), 'state' => 'serving', 'reported_at' => now(), 'epoch' => $version['epoch'], 'applied_revision' => $version['revision']]);
    $this->postJson($this->path.'/result', $this->body)->assertOk()->assertJsonPath('data.result', ['outcome' => 'succeeded', 'data' => ['backend' => 'applied']])->assertDontSee('private-sentinel');
    $runtime->update(['boot_id' => (string) Str::uuid(), 'applied_revision' => 0]);
    $sequence = $this->operation->fresh()->sequence;
    $this->postJson($this->path.'/result', $this->body)->assertOk();
    expect($this->operation->fresh()->sequence)->toBe($sequence);
    expect($this->operation->fresh()->attempts)->toBe(0)->and($this->operation->fresh()->worker)->toBeNull();
});

it('refuses Gateway effects foreign workers and mismatched registry intent', function (): void {
    $this->postJson($this->path.'/primitive', ['run_id' => $this->run])->assertUnprocessable();
    $this->operation->update(['input' => [...$this->operation->input, 'revision' => 999]]);
    $this->getJson($this->path.'?run_id='.$this->run)->assertUnprocessable()->assertJsonPath('error.code', 'websocket.registry_invalid');
    $other = Node::query()->create(['name' => 'foreign-worker', 'platform' => 'linux', 'status' => 'active', 'wireguard_ip' => '10.44.0.18', 'public_ssh_host' => '192.0.2.18']);
    config(['operation_executor.worker_node_ids' => [$other->id]]);
    $this->withServerVariables(['REMOTE_ADDR' => $other->wireguard_ip])->getJson($this->path.'?run_id='.$this->run)->assertForbidden();
    expect($this->operation->fresh()->status)->toBe(OperationStatus::Queued);
});

it('projects only typed terminal registry failures', function (string $code): void {
    $this->postJson($this->path.'/result', [...$this->body, 'status' => 'failed', 'error_code' => $code, 'result' => ['error_code' => $code, 'secret' => 'private-sentinel']])
        ->assertOk()->assertJsonPath('data.result', ['error_code' => $code])->assertDontSee('private-sentinel');
})->with(['websocket.registry_pending', 'websocket.registry_superseded']);

it('refuses the deleted sync writer without recording intent and offers no Gateway execution', function (): void {
    $operations = Operation::query()->count();
    $registry = WebSocketRegistryOperation::query()->count();
    config(['swamp.direct_writers' => ['websocket.sync' => 'polled']]);
    $this->postJson('/api/v1/websocket-sync')->assertStatus(503)->assertJsonPath('error.code', 'operation.writer_invalid');
    expect(Operation::query()->count())->toBe($operations)->and(WebSocketRegistryOperation::query()->count())->toBe($registry);
    expect(fn () => app(OperationExecutionContract::class)->authorizeRun($this->operation, [
        'worker' => 'obsolete', 'key' => 'probe', 'transport' => 'remote_program', 'name' => 'websocket.registry-applied',
        'input' => ['registry_operation_id' => $this->registry->id],
    ]))->toThrow(ResourceOperationException::class);
});

it('starts app creation directly and exposes only recorded confirmation input', function (): void {
    $response = $this->withHeader('Idempotency-Key', 'direct-create')->postJson('/api/v1/websocket-apps', ['name' => 'direct-disposable'])->assertAccepted();
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    expect($operation->kind)->toBe('websocket.app_create')->and($operation->state['websocket_registry_writer'])->toBe('direct');
    expect($operation->state['swamp_run_id'])->toBe($this->run)->and($operation->lock_key)->toBeNull()->and($operation->attempts)->toBe(0);
    $registry = WebSocketRegistryOperation::query()->findOrFail($operation->input['registry_operation_id']);
    $path = '/api/v1/direct-operations/'.$operation->id;
    $secret = WebSocketApp::query()->where('name', 'direct-disposable')->sole()->secret;

    $this->getJson($path.'?run_id='.$this->run)->assertOk()->assertJsonPath('data.input', [
        'registry_operation_id' => $registry->id, 'epoch' => $registry->epoch, 'revision' => $registry->revision,
    ])->assertDontSee($secret);
    $this->postJson($path.'/result', $this->body)->assertConflict()->assertJsonPath('error.code', 'websocket.registry_pending');
    WebSocketRuntime::query()->create(['id' => (string) Str::uuid(), 'node_id' => $this->gateway->id, 'registry_token' => 'test-create',
        'boot_id' => (string) Str::uuid(), 'state' => 'serving', 'reported_at' => now(), 'epoch' => $registry->epoch, 'applied_revision' => $registry->revision]);
    $this->postJson($path.'/result', $this->body)->assertOk()->assertJsonPath('data.status', 'succeeded')->assertDontSee($secret);
    expect(WebSocketApp::query()->where('name', 'direct-disposable')->count())->toBe(1);
    Http::assertNothingSent();
});

it('refuses deleted app-create polling before recording the registry mutation', function (): void {
    $registry = WebSocketRegistryOperation::query()->count();
    config(['swamp.direct_writers' => ['websocket.app_create' => 'polled']]);
    $this->withHeader('Idempotency-Key', 'refused-create')->postJson('/api/v1/websocket-apps', ['name' => 'refused-disposable'])
        ->assertStatus(503)->assertJsonPath('error.code', 'operation.writer_invalid');
    expect(WebSocketRegistryOperation::query()->count())->toBe($registry);
    expect(WebSocketApp::query()->where('name', 'refused-disposable')->exists())->toBeFalse();
});

it('replays direct app creation with the same key without another credential revision or native start', function (): void {
    $socket = app(WebSocketClient::class);
    $first = $this->withHeader('Idempotency-Key', 'direct-create-replay')
        ->postJson('/api/v1/websocket-apps', ['name' => 'replay-disposable'])->assertAccepted();
    $app = WebSocketApp::query()->where('name', 'replay-disposable')->sole();
    $secret = $app->secret;
    $version = app(WebSocketRegistry::class)->version();
    $registryCount = WebSocketRegistryOperation::query()->count();

    $this->postJson('/api/v1/websocket-apps', ['name' => 'replay-disposable'])
        ->assertAccepted()->assertJsonPath('data.id', $first->json('data.id'))->assertDontSee($secret);

    expect($app->refresh()->secret)->toBe($secret);
    expect(app(WebSocketRegistry::class)->version())->toBe($version);
    expect(WebSocketRegistryOperation::query()->count())->toBe($registryCount);
    expect(Operation::query()->where('kind', 'websocket.app_create')->count())->toBe(1);
    expect($socket->sent)->toHaveCount(1);
});

it('refuses reserved or taken direct app names with 422 before a registry write or native start', function (string $name): void {
    WebSocketApp::query()->create(['name' => 'taken', 'app_id' => 'taken-id', 'key' => 'taken-key', 'secret' => 'taken-secret']);
    $version = app(WebSocketRegistry::class)->version();
    $registryCount = WebSocketRegistryOperation::query()->count();

    $this->withHeader('Idempotency-Key', 'direct-create-invalid-'.$name)
        ->postJson('/api/v1/websocket-apps', ['name' => $name])
        ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');

    expect(WebSocketApp::query()->pluck('name')->all())->toBe(['taken']);
    expect(app(WebSocketRegistry::class)->version())->toBe($version);
    expect(WebSocketRegistryOperation::query()->count())->toBe($registryCount);
    expect(Operation::query()->where('kind', 'websocket.app_create')->exists())->toBeFalse();
    expect(app(WebSocketClient::class)->sent)->toBe([]);
})->with(['taken', 'gateway']);

it('returns 503 and preserves committed direct app intent when Swamp refuses the start', function (): void {
    $socket = app(WebSocketClient::class);
    $socket->reply = function (array $request): array {
        expect(WebSocketApp::query()->where('name', 'start-refused-disposable')->exists())->toBeTrue();
        expect(Operation::query()->where('kind', 'websocket.app_create')->exists())->toBeTrue();

        return [['type' => 'error', 'id' => $request['id']]];
    };

    $response = $this->withHeader('Idempotency-Key', 'direct-create-start-refused')
        ->postJson('/api/v1/websocket-apps', ['name' => 'start-refused-disposable'])
        ->assertStatus(503)->assertJsonPath('error.code', 'swamp.start_refused');

    $app = WebSocketApp::query()->where('name', 'start-refused-disposable')->sole();
    $operation = Operation::query()->where('kind', 'websocket.app_create')->sole();
    $registry = WebSocketRegistryOperation::query()->findOrFail($operation->input['registry_operation_id']);
    expect($registry->result['id'])->toBe($app->id);
    expect($operation->status)->toBe(OperationStatus::Queued);
    expect($operation->state)->not->toHaveKey('swamp_run_id');
    expect($socket->sent)->toHaveCount(1);
    $response->assertDontSee($app->secret);
});

it('confirms app destruction directly without repeating or exposing the saved registry mutation', function (): void {
    app(ChangeWebSocketRegistryAction::class)->execute('create', 'direct-disposable', $this->gateway->id, 'seed-destroy');
    $secret = WebSocketApp::query()->where('name', 'direct-disposable')->sole()->secret;
    $response = $this->withHeader('Idempotency-Key', 'direct-destroy')->deleteJson('/api/v1/websocket-apps/direct-disposable')->assertAccepted();
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    expect($operation->kind)->toBe('websocket.app_destroy')->and($operation->state['websocket_registry_writer'])->toBe('direct');
    expect($operation->state['swamp_run_id'])->toBe($this->run)->and($operation->lock_key)->toBeNull()->and($operation->attempts)->toBe(0);
    $registry = WebSocketRegistryOperation::query()->findOrFail($operation->input['registry_operation_id']);
    $path = '/api/v1/direct-operations/'.$operation->id;
    $this->getJson($path.'?run_id='.$this->run)->assertOk()->assertJsonPath('data.input', [
        'registry_operation_id' => $registry->id, 'epoch' => $registry->epoch, 'revision' => $registry->revision,
    ])->assertDontSee($secret);
    WebSocketRuntime::query()->create(['id' => (string) Str::uuid(), 'node_id' => $this->gateway->id, 'registry_token' => 'test-destroy',
        'boot_id' => (string) Str::uuid(), 'state' => 'serving', 'reported_at' => now(), 'epoch' => $registry->epoch, 'applied_revision' => $registry->revision]);
    $this->postJson($path.'/result', $this->body)->assertOk()->assertJsonPath('data.status', 'succeeded')->assertDontSee($secret);
    $this->deleteJson('/api/v1/websocket-apps/direct-disposable')->assertAccepted()->assertJsonPath('data.id', $operation->id);
    expect(WebSocketApp::query()->where('name', 'direct-disposable')->exists())->toBeFalse();
    Http::assertNothingSent();
});

it('refuses deleted app-destroy polling without deleting the app', function (): void {
    app(ChangeWebSocketRegistryAction::class)->execute('create', 'refused-disposable', $this->gateway->id, 'seed-refused-destroy');
    $registry = WebSocketRegistryOperation::query()->count();
    config(['swamp.direct_writers' => ['websocket.app_destroy' => 'polled']]);
    $this->withHeader('Idempotency-Key', 'refused-destroy')->deleteJson('/api/v1/websocket-apps/refused-disposable')
        ->assertStatus(503)->assertJsonPath('error.code', 'operation.writer_invalid');
    expect(WebSocketRegistryOperation::query()->count())->toBe($registry);
    expect(WebSocketApp::query()->where('name', 'refused-disposable')->exists())->toBeTrue();
});
