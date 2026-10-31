<?php

declare(strict_types=1);

use App\Actions\WebSocket\ChangeWebSocketRegistryAction;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
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
    config(['swamp.run_token' => 'fixture.shared']);
    $this->operation = Operation::query()->create(['kind' => 'websocket.sync', 'target_type' => 'websocket_sync', 'target_id' => $registry->revision,
        'node_id' => $this->gateway->id, 'status' => 'queued', 'sequence' => 1,
        'input' => ['registry_operation_id' => $registry->id, 'epoch' => $registry->epoch, 'revision' => $registry->revision, 'password' => 'private-sentinel'],
        'state' => ['swamp_run_id' => $this->run]]);
    $this->path = '/api/v1/direct-operations/'.$this->operation->id;
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => [
        'kind' => 'started', 'workflowName' => 'operation-websocket-registry', 'runId' => $this->run]]];
    app()->instance(WebSocketClient::class, $socket);
    $this->body = ['run_id' => $this->run, 'status' => 'succeeded', 'started_at' => '2026-10-30T18:30:00.000Z', 'finished_at' => '2026-10-30T18:30:01.000Z',
        'result' => ['outcome' => 'succeeded', 'data' => ['backend' => 'applied', 'secret' => 'private-sentinel'], 'secret' => 'private-sentinel']];
});

it('records native completion without requiring a current Reverb report', function (): void {
    $this->postJson($this->path.'/result', $this->body)
        ->assertOk()->assertJsonPath('data.status', 'succeeded')
        ->assertJsonPath('data.result', ['outcome' => 'succeeded', 'data' => ['backend' => 'applied']])
        ->assertDontSee('private-sentinel');

    expect($this->operation->fresh()->status)->toBe(OperationStatus::Succeeded);
    expect(WebSocketRuntime::query()->count())->toBe(0);
});

it('records a native failure without a second registry error allowlist', function (): void {
    $code = 'websocket.transport_unavailable';
    $this->postJson($this->path.'/result', [...$this->body, 'status' => 'failed', 'error_code' => $code,
        'result' => ['error_code' => $code, 'secret' => 'private-sentinel']])
        ->assertOk()->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.result', ['error_code' => $code])->assertDontSee('private-sentinel');

    expect($this->operation->fresh()->error_code)->toBe($code);
});

it('records native completion after the registry history row is removed', function (): void {
    $this->registry->delete();

    $this->getJson($this->path.'?run_id='.$this->run)->assertOk()->assertJsonPath('data.input', [
        'registry_operation_id' => $this->registry->id, 'epoch' => $this->registry->epoch, 'revision' => $this->registry->revision,
    ])->assertDontSee('private-sentinel');
    $this->postJson($this->path.'/result', $this->body)->assertOk()->assertJsonPath('data.status', 'succeeded');
    $sequence = $this->operation->fresh()->sequence;
    $this->postJson($this->path.'/result', $this->body)->assertOk();
    expect($this->operation->fresh()->sequence)->toBe($sequence);
});

it('selects direct registry kinds by default and binds root starts before exposing safe input', function (): void {
    foreach (['websocket.sync', 'websocket.app_create', 'websocket.app_destroy', 'websocket.app_rotate'] as $kind) {
        expect(app(DirectOperation::class)->writer($kind))->toBe('direct');
    }
    $this->operation->update(['state' => []]);
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
    expect($operation->kind)->toBe('websocket.sync');
    expect($operation->state['swamp_run_id'])->toBe($this->run)->and($operation->lock_key)->toBeNull()->and($operation->attempts)->toBe(0);
    Http::assertNothingSent();
});

it('replays native completion after the current Reverb boot changes', function (): void {
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

it('refuses Gateway effects foreign workers and another native run', function (): void {
    $this->postJson($this->path.'/primitive', ['run_id' => $this->run])->assertUnprocessable();
    $this->getJson($this->path.'?run_id='.(string) Str::uuid())->assertConflict()->assertJsonPath('error.code', 'operation.run_mismatch');
    $this->postJson($this->path.'/result', [...$this->body, 'run_id' => (string) Str::uuid()])->assertConflict()->assertJsonPath('error.code', 'operation.run_mismatch');
    $other = Node::query()->create(['name' => 'foreign-worker', 'platform' => 'linux', 'status' => 'active', 'wireguard_ip' => '10.44.0.18', 'public_ssh_host' => '192.0.2.18']);
    $this->withServerVariables(['REMOTE_ADDR' => $other->wireguard_ip])->getJson($this->path.'?run_id='.$this->run)->assertForbidden();
    $this->postJson($this->path.'/result', $this->body)->assertForbidden();
    expect($this->operation->fresh()->status)->toBe(OperationStatus::Queued);
});

it('records native registry failure codes', function (string $code): void {
    $this->postJson($this->path.'/result', [...$this->body, 'status' => 'failed', 'error_code' => $code, 'result' => ['error_code' => $code, 'secret' => 'private-sentinel']])
        ->assertOk()->assertJsonPath('data.result', ['error_code' => $code])->assertDontSee('private-sentinel');
})->with(['websocket.registry_pending', 'websocket.registry_superseded']);

it('starts app creation directly and exposes only recorded confirmation input', function (): void {
    $response = $this->withHeader('Idempotency-Key', 'direct-create')->postJson('/api/v1/websocket-apps', ['name' => 'direct-disposable'])->assertAccepted();
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    expect($operation->kind)->toBe('websocket.app_create');
    expect($operation->state['swamp_run_id'])->toBe($this->run)->and($operation->lock_key)->toBeNull()->and($operation->attempts)->toBe(0);
    $registry = WebSocketRegistryOperation::query()->findOrFail($operation->input['registry_operation_id']);
    $path = '/api/v1/direct-operations/'.$operation->id;
    $secret = WebSocketApp::query()->where('name', 'direct-disposable')->sole()->secret;

    $this->getJson($path.'?run_id='.$this->run)->assertOk()->assertJsonPath('data.input', [
        'registry_operation_id' => $registry->id, 'epoch' => $registry->epoch, 'revision' => $registry->revision,
    ])->assertDontSee($secret);
    WebSocketRuntime::query()->create(['id' => (string) Str::uuid(), 'node_id' => $this->gateway->id, 'registry_token' => 'test-create',
        'boot_id' => (string) Str::uuid(), 'state' => 'serving', 'reported_at' => now(), 'epoch' => $registry->epoch, 'applied_revision' => $registry->revision]);
    $this->postJson($path.'/result', $this->body)->assertOk()->assertJsonPath('data.status', 'succeeded')->assertDontSee($secret);
    expect(WebSocketApp::query()->where('name', 'direct-disposable')->count())->toBe(1);
    Http::assertNothingSent();
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
    expect($operation->status)->toBe(OperationStatus::Failed);
    expect($operation->error_code)->toBe('swamp.start_refused');
    expect($operation->state)->not->toHaveKey('swamp_run_id');
    $this->postJson('/api/v1/websocket-apps', ['name' => 'start-refused-disposable'])
        ->assertAccepted()->assertJsonPath('data.id', $operation->id)->assertJsonPath('data.status', 'failed');
    expect($socket->sent)->toHaveCount(1);
    expect(WebSocketApp::query()->where('name', 'start-refused-disposable')->count())->toBe(1);
    $response->assertDontSee($app->secret);
});

it('confirms app destruction directly without repeating or exposing the saved registry mutation', function (): void {
    app(ChangeWebSocketRegistryAction::class)->execute('create', 'direct-disposable', $this->gateway->id, 'seed-destroy');
    $secret = WebSocketApp::query()->where('name', 'direct-disposable')->sole()->secret;
    $response = $this->withHeader('Idempotency-Key', 'direct-destroy')->deleteJson('/api/v1/websocket-apps/direct-disposable')->assertAccepted();
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    expect($operation->kind)->toBe('websocket.app_destroy');
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

it('confirms rotation directly without transferring or rotating credentials twice', function (): void {
    app(ChangeWebSocketRegistryAction::class)->execute('create', 'direct-disposable', $this->gateway->id, 'seed-rotate');
    $app = WebSocketApp::query()->where('name', 'direct-disposable')->sole();
    $secret = $app->secret;
    $generation = $app->credential_generation;
    $response = $this->withHeader('Idempotency-Key', 'direct-rotate')->postJson('/api/v1/websocket-apps/direct-disposable/rotate')->assertAccepted();
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    expect($operation->kind)->toBe('websocket.app_rotate');
    expect($operation->state['swamp_run_id'])->toBe($this->run)->and($operation->lock_key)->toBeNull()->and($operation->attempts)->toBe(0);
    expect($app->refresh()->credential_generation)->toBe($generation + 1)->and($app->secret)->not->toBe($secret);
    $rotated = $app->secret;
    $registry = WebSocketRegistryOperation::query()->findOrFail($operation->input['registry_operation_id']);
    $path = '/api/v1/direct-operations/'.$operation->id;
    $this->getJson($path.'?run_id='.$this->run)->assertOk()->assertJsonPath('data.input', [
        'registry_operation_id' => $registry->id, 'epoch' => $registry->epoch, 'revision' => $registry->revision,
    ])->assertDontSee($secret)->assertDontSee($rotated);
    WebSocketRuntime::query()->create(['id' => (string) Str::uuid(), 'node_id' => $this->gateway->id, 'registry_token' => 'test-rotate',
        'boot_id' => (string) Str::uuid(), 'state' => 'serving', 'reported_at' => now(), 'epoch' => $registry->epoch, 'applied_revision' => $registry->revision]);
    $this->postJson($path.'/result', $this->body)->assertOk()->assertJsonPath('data.status', 'succeeded')->assertDontSee($rotated);
    $this->postJson('/api/v1/websocket-apps/direct-disposable/rotate')->assertAccepted()->assertJsonPath('data.id', $operation->id);
    expect($app->refresh()->secret)->toBe($rotated)->and($app->credential_generation)->toBe($generation + 1);
    Http::assertNothingSent();
});

it('records native supersession without changing the newer app credentials', function (): void {
    app(ChangeWebSocketRegistryAction::class)->execute('create', 'superseded-disposable', $this->gateway->id, 'seed-superseded');
    $response = $this->withHeader('Idempotency-Key', 'superseded-rotate')->postJson('/api/v1/websocket-apps/superseded-disposable/rotate')->assertAccepted();
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    app(ChangeWebSocketRegistryAction::class)->execute('rotate', 'superseded-disposable', $this->gateway->id, 'superseding-rotate');
    $app = WebSocketApp::query()->where('name', 'superseded-disposable')->sole();
    $secret = $app->secret;
    $generation = $app->credential_generation;
    $code = 'websocket.registry_superseded';

    $this->postJson('/api/v1/direct-operations/'.$operation->id.'/result', [...$this->body,
        'status' => 'failed', 'error_code' => $code, 'result' => ['error_code' => $code]])
        ->assertOk()->assertJsonPath('data.status', 'failed')->assertDontSee($secret);

    expect($operation->fresh()->status)->toBe(OperationStatus::Failed);
    expect($app->refresh()->secret)->toBe($secret)->and($app->credential_generation)->toBe($generation);
});
