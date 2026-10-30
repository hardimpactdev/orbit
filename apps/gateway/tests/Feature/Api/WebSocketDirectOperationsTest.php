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
        expect(app(DirectOperation::class)->writer($kind))->toBe($kind === 'websocket.sync' ? 'direct' : null);
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
