<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionStore;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\Http;
use Orbit\Extensions\Conn\Models\ConnEnvironment;

beforeEach(function (): void {
    $this->freezeTime();
    Http::preventStrayRequests();
    app(ExtensionStore::class)->set('conn', true);
    $this->services = $this->markAsGateway(Node::query()->create(['name' => 'services', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1', 'user' => 'orbit']));
    config(['dependency_detection.services_node_id' => $this->services->id]);
    $this->caller = Node::query()->create(['name' => 'phone', 'status' => 'active', 'platform' => 'ios',
        'public_ssh_host' => '192.0.2.2', 'wireguard_ip' => '10.44.0.2']);
    $this->other = Node::query()->create(['name' => 'other-phone', 'status' => 'active', 'platform' => 'ios',
        'public_ssh_host' => '192.0.2.3', 'wireguard_ip' => '10.44.0.3']);
    ConnEnvironment::query()->create(['environment_id' => 'conn-health', 'label' => 'dev', 'node_id' => $this->services->id,
        'url' => 'http://dev.test', 'admin_session' => 'health-session-sentinel', 'admin_session_expires_at' => now()->addDay(), 'registered_at' => now()]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);
});

it('alerts a caller and the scheduled check after five unclaimed minutes without running the effect', function (string $verb): void {
    $this->postJson('/api/v1/conn/environments/conn-health/pairings'.($verb === 'revoke' ? '/revoke' : ''), $verb === 'create' ? ['async' => true] : [])->assertAccepted();
    $operation = Operation::query()->sole();
    $path = '/api/v1/conn/operations/'.$operation->id;
    $this->travel(4)->minutes();
    $this->getJson($path)->assertOk()->assertJsonPath('data.assistance', null);
    $this->artisan('orbit:conn-operations-check')->assertSuccessful();
    $this->travel(1)->minutes();

    $response = $this->getJson($path)->assertOk()->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.assistance.error_code', 'conn.executor_unclaimed');
    $this->artisan('orbit:conn-operations-check')->expectsOutputToContain('conn.executor_unclaimed: Conn Operation '.$operation->id)->assertFailed();

    expect($response->getContent())->not->toContain('health-session-sentinel');
    expect($operation->fresh()->started_at)->toBeNull()->and($operation->fresh()->cancel_requested_at)->toBeNull();
    expect(Operation::query()->count())->toBe(1)->and($operation->steps()->count())->toBe(0);
    Http::assertNothingSent();

    $this->withServerVariables(['REMOTE_ADDR' => $this->services->wireguard_ip]);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $operation->kind, 'operation_id' => $operation->id, 'worker' => 'health-proof'])->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);
    $this->getJson($path)->assertOk()->assertJsonPath('data.status', 'running')->assertJsonPath('data.assistance', null);
    $this->artisan('orbit:conn-operations-check')->assertSuccessful();
    Http::assertNothingSent();
})->with(['create', 'revoke']);

it('does not alert terminal or unrelated Operations', function (string $status): void {
    $this->postJson('/api/v1/conn/environments/conn-health/pairings/revoke')->assertAccepted();
    $operation = Operation::query()->sole();
    $operation->update(['status' => $status]);
    Operation::query()->create(['kind' => 'firewall.allow', 'target_type' => 'node', 'target_id' => $this->services->id,
        'node_id' => $this->services->id, 'status' => 'queued', 'input' => []]);
    $this->travel(6)->minutes();

    $this->getJson('/api/v1/conn/operations/'.$operation->id)->assertOk()->assertJsonPath('data.assistance', null);
    $this->artisan('orbit:conn-operations-check')->assertSuccessful();

    Http::assertNothingSent();
})->with(['succeeded', 'failed', 'cancelled']);

it('keeps an unclaimed revocation private to its original requester', function (): void {
    $this->postJson('/api/v1/conn/environments/conn-health/pairings/revoke')->assertAccepted();
    $operation = Operation::query()->sole();
    $this->travel(6)->minutes();
    $this->withServerVariables(['REMOTE_ADDR' => $this->other->wireguard_ip]);

    $this->getJson('/api/v1/conn/operations/'.$operation->id)->assertNotFound();

    Http::assertNothingSent();
});
