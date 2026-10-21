<?php

declare(strict_types=1);

use App\Actions\WebSocket\ChangeWebSocketRegistryAction;
use App\Actions\WebSocket\ReadWebSocketApplyStatusAction;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Domain\WebSocket\WebSocketRegistry;
use App\Models\Node;
use App\Models\WebSocketApp;
use App\Models\WebSocketRuntime;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Http::fake();
    $this->gateway = $this->markAsGateway(registryNode('gateway', '10.44.0.1'));
    $this->server = registryNode('services', '10.44.0.2');
    $this->runtime = WebSocketRuntime::query()->create([
        'id' => (string) Str::uuid(), 'node_id' => $this->server->id,
        'registry_token' => 'test-registry-secret', 'notification_token' => 'test-notification-secret',
    ]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
});

it('commits once and reports pending until both current process boots confirm', function (): void {
    $response = $this->withHeader('Idempotency-Key', 'create-a')->postJson('/api/v1/websocket-apps', ['name' => 'alpha'])
        ->assertStatus(202)->assertJsonPath('operation.state', 'pending');
    $operationId = $response->json('operation.id');
    $this->postJson('/api/v1/websocket-apps', ['name' => 'alpha'])->assertStatus(202)->assertJsonPath('operation.id', $operationId);
    expect(WebSocketApp::query()->where('name', 'alpha')->count())->toBe(1);
    $second = WebSocketRuntime::query()->create([
        'id' => (string) Str::uuid(), 'node_id' => $this->server->id, 'slot' => 'second',
        'registry_token' => 'second-secret', 'notification_token' => 'second-notify',
    ]);
    $version = app(WebSocketRegistry::class)->version();
    foreach ([$this->runtime, $second] as $i => $runtime) {
        $boot = (string) Str::uuid();
        $this->withServerVariables(['REMOTE_ADDR' => $this->server->wireguard_ip])->withHeader('Authorization', 'Bearer '.$runtime->registry_token)
            ->postJson('/api/v1/websocket-registry/processes/'.$runtime->id.'/boots', ['boot_id' => $boot])->assertNoContent();
        $this->putJson('/api/v1/websocket-registry/processes/'.$runtime->id, [
            'boot_id' => $boot, 'sequence' => 1, ...$version, 'state' => 'serving',
            'app_count' => 1, 'connections' => [], 'error' => null,
        ])->assertNoContent();
        $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip])->withoutHeader('Authorization')
            ->getJson('/api/v1/websocket-operations/'.$operationId)->assertOk()
            ->assertJsonPath('operation.state', $i === 0 ? 'pending' : 'applied');
    }
    Http::assertSent(fn ($request): bool => $request->url() === 'https://reverb.orbit/internal/orbit/registry-changed'
        && array_keys($request->data()) === ['epoch', 'revision']);
});

it('restricts credential snapshots to an active peer and its runtime secret without caching them', function (): void {
    app(ChangeWebSocketRegistryAction::class)->execute('create', 'alpha', $this->gateway->id, 'fixture');
    $secret = WebSocketApp::query()->sole()->secret;
    $this->getJson('/api/v1/websocket-registry')->assertForbidden();
    $this->withHeader('Authorization', 'Bearer test-registry-secret')->getJson('/api/v1/websocket-registry')->assertForbidden();
    $this->withServerVariables(['REMOTE_ADDR' => $this->server->wireguard_ip]);
    $response = $this->getJson('/api/v1/websocket-registry')->assertOk()->assertJsonPath('apps.0.secret', $secret);
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(DB::table('websocket_runtimes')->value('registry_token'))->not->toBe('test-registry-secret');
    $this->withHeader('If-None-Match', $response->headers->get('ETag'))->getJson('/api/v1/websocket-registry')->assertStatus(304);
    $this->withHeader('Authorization', 'Bearer '.$secret)->getJson('/api/v1/websocket-registry')->assertForbidden();
});

it('fences reports from an older boot and rejects its registration replay', function (): void {
    $base = '/api/v1/websocket-registry/processes/'.$this->runtime->id;
    $old = (string) Str::uuid();
    $new = (string) Str::uuid();
    $this->withServerVariables(['REMOTE_ADDR' => $this->server->wireguard_ip])->withHeader('Authorization', 'Bearer test-registry-secret');
    $this->postJson($base.'/boots', ['boot_id' => $old])->assertNoContent();
    $this->postJson($base.'/boots', ['boot_id' => $new])->assertNoContent();
    $this->postJson($base.'/boots', ['boot_id' => $old])->assertConflict();
    $this->putJson($base, ['boot_id' => $old, 'sequence' => 100, ...app(WebSocketRegistry::class)->version(), 'state' => 'serving', 'app_count' => 0, 'connections' => []])->assertConflict();
    expect($this->runtime->refresh()->boot_id)->toBe($new)->and($this->runtime->applied_revision)->toBeNull();
});

it('replays a removal after its route model has been deleted without mutating twice', function (): void {
    $this->withHeader('Idempotency-Key', 'make')->postJson('/api/v1/websocket-apps', ['name' => 'alpha'])->assertStatus(202);
    $response = $this->withHeader('Idempotency-Key', 'remove')->deleteJson('/api/v1/websocket-apps/alpha')->assertStatus(202);
    $revision = app(WebSocketRegistry::class)->version();
    $this->deleteJson('/api/v1/websocket-apps/alpha')->assertStatus(202)->assertJsonPath('operation.id', $response->json('operation.id'));
    expect(app(WebSocketRegistry::class)->version())->toBe($revision)->and(WebSocketApp::query()->count())->toBe(0);
});

function registryNode(string $name, string $address): Node
{
    return Node::query()->create(['name' => $name, 'public_ssh_host' => '192.0.2.90', 'user' => 'orbit', 'platform' => 'linux', 'wireguard_ip' => $address, 'status' => LifecycleStatus::Active]);
}

it('refuses ordinary Node removal while a runtime still requires application', function (): void {
    $operation = app(ChangeWebSocketRegistryAction::class)->execute('sync', null, $this->gateway->id, 'sync');
    $this->deleteJson('/api/v1/nodes/'.$this->server->id, ['offline' => false])->assertConflict()->assertJsonPath('error.code', 'websocket.runtime_shutdown_required');
    expect(fn () => $this->server->delete())->toThrow(QueryException::class);
    expect($this->runtime->refresh()->required)->toBeTrue()
        ->and(app(ReadWebSocketApplyStatusAction::class)->execute($operation)['state'])->toBe('pending');
});

it('withdraws only the default service identity after verified shutdown', function (): void {
    $second = WebSocketRuntime::query()->create([
        'id' => (string) Str::uuid(), 'node_id' => $this->server->id, 'slot' => 'second',
        'registry_token' => 'second-secret', 'notification_token' => 'second-notify',
    ]);
    $manager = app(WebSocketCredentialManager::class);
    $manager->purge($this->server);
    expect($this->runtime->refresh()->required)->toBeTrue();
    $manager->purge($this->server, runtimeStopped: true);
    expect($this->runtime->refresh()->required)->toBeFalse()->and($second->refresh()->required)->toBeTrue();
    $this->withServerVariables(['REMOTE_ADDR' => $this->server->wireguard_ip])->withHeader('Authorization', 'Bearer second-secret')
        ->getJson('/api/v1/websocket-registry')->assertOk();
});

it('distinguishes superseded creates and rotations from results preserved by later revisions', function (): void {
    $changes = app(ChangeWebSocketRegistryAction::class);
    $status = app(ReadWebSocketApplyStatusAction::class);
    $create = $changes->execute('create', 'alpha', $this->gateway->id, 'create');
    $rotate = $changes->execute('rotate', 'alpha', $this->gateway->id, 'rotate');
    $changes->execute('rotate', 'alpha', $this->gateway->id, 'rotate-again');
    $version = app(WebSocketRegistry::class)->version();
    $this->runtime->update(['epoch' => $version['epoch'], 'applied_revision' => $version['revision'], 'reported_at' => now(), 'state' => 'serving']);
    expect($status->execute($create)['state'])->toBe('applied')
        ->and($status->execute($rotate)['state'])->toBe('superseded');
    $remove = $changes->execute('destroy', 'alpha', $this->gateway->id, 'remove');
    expect($status->execute($create)['state'])->toBe('superseded');
    $changes->execute('create', 'alpha', $this->gateway->id, 'recreate');
    $version = app(WebSocketRegistry::class)->version();
    $this->runtime->update(['applied_revision' => $version['revision'], 'reported_at' => now()]);
    expect($status->execute($create)['state'])->toBe('superseded')
        ->and($status->execute($remove)['state'])->toBe('applied');
    $this->travel(16)->seconds();
    expect($status->execute($remove)['state'])->toBe('pending');
});

it('rejects idempotency key reuse for a different mutation', function (): void {
    $this->withHeader('Idempotency-Key', 'one')->postJson('/api/v1/websocket-apps', ['name' => 'alpha'])->assertStatus(202);
    $this->postJson('/api/v1/websocket-apps', ['name' => 'beta'])->assertConflict()->assertJsonPath('error.code', 'websocket.idempotency_conflict');
    expect(WebSocketApp::query()->count())->toBe(1);
});


it('returns the latest safe process error and clears it after recovery or a new boot', function (): void {
    $operation = app(ChangeWebSocketRegistryAction::class)->execute('sync', null, $this->gateway->id, 'diagnostics');
    $base = '/api/v1/websocket-registry/processes/'.$this->runtime->id;
    $boot = (string) Str::uuid();
    $this->withServerVariables(['REMOTE_ADDR' => $this->server->wireguard_ip])->withHeader('Authorization', 'Bearer test-registry-secret');
    $this->postJson($base.'/boots', ['boot_id' => $boot])->assertNoContent();
    $report = ['boot_id' => $boot, ...app(WebSocketRegistry::class)->version(), 'state' => 'starting'];
    $status = fn (): array => app(ReadWebSocketApplyStatusAction::class)->execute($operation);

    $this->putJson($base, [...$report, 'sequence' => 1, 'error' => 'registry.unreachable'])->assertNoContent();
    expect($status()['processes'][0]['error'])->toBe('registry.unreachable');
    $this->putJson($base, [...$report, 'sequence' => 2, 'state' => 'serving', 'error' => null])->assertNoContent();
    expect($status()['processes'][0]['error'])->toBeNull();
    $this->putJson($base, [...$report, 'sequence' => 1, 'error' => 'registry.apply_failed'])->assertNoContent();
    expect($status()['processes'][0]['error'])->toBeNull();
    $this->putJson($base, [...$report, 'sequence' => 3, 'error' => 'registry.apply_failed'])->assertNoContent();
    $this->postJson($base.'/boots', ['boot_id' => (string) Str::uuid()])->assertNoContent();
    expect($status()['processes'][0]['error'])->toBeNull();
    $this->putJson($base, [...$report, 'sequence' => 4, 'error' => 'credential-sentinel'])->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
});
