<?php

declare(strict_types=1);

use App\Actions\Operations\CancelOperationAction;
use App\Actions\WebSocket\ChangeWebSocketRegistryAction;
use App\Actions\WebSocket\CloseWebSocketOperationsAction;
use App\Domain\Operations\OperationStatus;
use App\Domain\WebSocket\WebSocketRegistry;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Node;
use App\Models\Operation;
use App\Models\WebSocketApp;
use App\Models\WebSocketRuntime;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->gateway = $this->markAsGateway(Node::query()->create(['name' => 'close-gateway', 'platform' => 'linux', 'status' => 'active',
        'wireguard_ip' => '10.44.0.14', 'public_ssh_host' => '192.0.2.14', 'user' => 'orbit']));
    $this->socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $this->socket);
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
});

function close_websocket_serving_runtime(Node $node, int $revision): WebSocketRuntime
{
    return WebSocketRuntime::query()->create(['id' => (string) Str::uuid(), 'node_id' => $node->id, 'registry_token' => 'test-close',
        'boot_id' => (string) Str::uuid(), 'state' => 'serving', 'reported_at' => now(),
        'epoch' => app(WebSocketRegistry::class)->version()['epoch'], 'applied_revision' => $revision]);
}

it('records each registry change as a task without a Swamp run', function (string $method, string $path, string $kind): void {
    app(ChangeWebSocketRegistryAction::class)->execute('create', 'existing', $this->gateway->id, 'seed');
    $response = $this->withHeader('Idempotency-Key', 'start-'.$kind)->json($method, $path, $method === 'POST' && $path === '/api/v1/websocket-apps' ? ['name' => 'fresh'] : [])
        ->assertAccepted()->assertJsonPath('data.kind', $kind)->assertJsonPath('data.status', 'queued');
    $operation = Operation::query()->findOrFail($response->json('data.id'));

    expect($operation->state)->not->toHaveKey('swamp_run_id')->and($operation->attempts)->toBe(0)
        ->and($this->socket->sent)->toBe([]);
    $this->withHeader('Idempotency-Key', 'start-'.$kind)->json($method, $path, $method === 'POST' && $path === '/api/v1/websocket-apps' ? ['name' => 'fresh'] : [])
        ->assertAccepted()->assertJsonPath('data.id', $operation->id);
})->with([
    ['POST', '/api/v1/websocket-sync', 'websocket.sync'],
    ['POST', '/api/v1/websocket-apps', 'websocket.app_create'],
    ['POST', '/api/v1/websocket-apps/existing/rotate', 'websocket.app_rotate'],
    ['DELETE', '/api/v1/websocket-apps/existing', 'websocket.app_destroy'],
]);

it('succeeds once every serving process applied the revision', function (): void {
    $id = $this->withHeader('Idempotency-Key', 'applied')->postJson('/api/v1/websocket-sync')->assertAccepted()->json('data.id');
    $operation = Operation::query()->findOrFail($id);
    app(CloseWebSocketOperationsAction::class)->execute();
    expect($operation->fresh()->status)->toBe(OperationStatus::Queued);

    close_websocket_serving_runtime($this->gateway, $operation->input['revision']);
    app(CloseWebSocketOperationsAction::class)->execute();

    expect($operation->fresh()->status)->toBe(OperationStatus::Succeeded)
        ->and($operation->fresh()->result)->toBe(['outcome' => 'succeeded', 'data' => ['backend' => 'applied']]);
});

it('fails a rotation that a newer rotation superseded and keeps the newer credentials', function (): void {
    app(ChangeWebSocketRegistryAction::class)->execute('create', 'superseded', $this->gateway->id, 'seed');
    $id = $this->withHeader('Idempotency-Key', 'first-rotate')->postJson('/api/v1/websocket-apps/superseded/rotate')->assertAccepted()->json('data.id');
    app(ChangeWebSocketRegistryAction::class)->execute('rotate', 'superseded', $this->gateway->id, 'second-rotate');
    $secret = WebSocketApp::query()->where('name', 'superseded')->sole()->secret;

    app(CloseWebSocketOperationsAction::class)->execute();

    $operation = Operation::query()->findOrFail($id);
    expect($operation->status)->toBe(OperationStatus::Failed)
        ->and($operation->error_code)->toBe('websocket.registry_superseded')
        ->and(WebSocketApp::query()->where('name', 'superseded')->sole()->secret)->toBe($secret);
});

it('fails with websocket.registry_pending when a process is still stale after five minutes', function (): void {
    $id = $this->withHeader('Idempotency-Key', 'stale')->postJson('/api/v1/websocket-sync')->assertAccepted()->json('data.id');
    close_websocket_serving_runtime($this->gateway, 0);

    $this->travel(CloseWebSocketOperationsAction::TimeoutSeconds - 1)->seconds();
    app(CloseWebSocketOperationsAction::class)->execute();
    expect(Operation::query()->findOrFail($id)->status)->toBe(OperationStatus::Queued);

    $this->travel(1)->seconds();
    app(CloseWebSocketOperationsAction::class)->execute();
    expect(Operation::query()->findOrFail($id)->status)->toBe(OperationStatus::Failed)
        ->and(Operation::query()->findOrFail($id)->error_code)->toBe('websocket.registry_pending');
});

it('cancels a waiting task at once', function (): void {
    $id = $this->withHeader('Idempotency-Key', 'cancel')->postJson('/api/v1/websocket-sync')->assertAccepted()->json('data.id');

    expect(app(CancelOperationAction::class)->execute(Operation::query()->findOrFail($id))->status)->toBe(OperationStatus::Cancelled);
    close_websocket_serving_runtime($this->gateway, PHP_INT_MAX);
    app(CloseWebSocketOperationsAction::class)->execute();

    expect(Operation::query()->findOrFail($id)->status)->toBe(OperationStatus::Cancelled);
});
