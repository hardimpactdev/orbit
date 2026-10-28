<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionStore;
use App\Domain\Metrics\ExporterPreference;
use App\Domain\Metrics\ExporterPreferenceRepository;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Models\WebSocketApp;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Orbit\Extensions\Conn\Domain\ConnOperationSecrets;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnPairing;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Queue::fake();
    $this->caller = $this->markAsGateway(Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1', 'user' => 'orbit', 'ssh_host_fingerprint' => 'fixture-pin']));
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);
});

it('records Reverb intent without a webhook, restart or secret in the Operation', function (): void {
    $response = $this->withHeader('Idempotency-Key', 'reverb-create')->postJson('/api/v1/websocket-apps', ['name' => 'dev-proof'])->assertAccepted();
    $operation = Operation::query()->sole();
    $app = WebSocketApp::query()->where('name', 'dev-proof')->sole();
    expect($operation->kind)->toBe('websocket.app_create')->and($operation->node_id)->toBe($this->caller->id)
        ->and($operation->status)->toBe(OperationStatus::Queued);
    expect(json_encode($operation->toArray()))->not->toContain($app->secret);
    $this->postJson('/api/v1/websocket-apps', ['name' => 'dev-proof'])->assertAccepted()->assertJsonPath('data.id', $response->json('data.id'));
    $this->getJson('/api/v1/websocket-apps/dev-proof')->assertOk()->assertJsonPath('data.name', 'dev-proof');
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('retains Reverb reserved names and slug validation before admission', function (string $name): void {
    $this->postJson('/api/v1/websocket-apps', ['name' => $name])->assertUnprocessable();
    expect(Operation::query()->count())->toBe(0);
})->with(['gateway', 'Invalid name', 'with_underscore']);

it('records exporter preference without requiring an existing Metrics role', function (): void {
    $node = Node::query()->create(['name' => 'dev', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.5',
        'wireguard_ip' => '10.44.0.5', 'user' => 'orbit', 'ssh_host_fingerprint' => 'fixture-pin']);
    $this->withHeader('Idempotency-Key', 'exporter-enable')->putJson('/api/v1/metrics/exporters/'.$node->id)->assertAccepted()->assertJsonPath('data.kind', 'metrics.exporter_enable');
    expect(app(ExporterPreferenceRepository::class)->get($node->id))->toBe(ExporterPreference::Enabled)
        ->and(Operation::query()->sole()->input['preference_only'])->toBeTrue();
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('records Conn pairing admission with only an owner-bound session reference', function (): void {
    app(ExtensionStore::class)->set('conn', true);
    $environment = ConnEnvironment::query()->create(['environment_id' => 'env-dev', 'label' => 'dev', 'node_id' => $this->caller->id,
        'url' => 'http://10.44.0.30:3773', 'admin_session' => 'admin-session-sentinel', 'admin_session_expires_at' => now()->addDay(), 'registered_at' => now()]);
    $response = $this->withHeader('Idempotency-Key', 'conn-issue')->postJson('/api/v1/conn/environments/env-dev/pairings')->assertAccepted();
    $operation = Operation::query()->sole();
    $pairing = ConnPairing::query()->sole();
    expect($operation->kind)->toBe('conn.pairing_create')->and($pairing->node_id)->toBe($this->caller->id)
        ->and($pairing->pairing_link_id)->toBeNull()->and($pairing->credential)->toBeNull();
    expect($response->getContent())->not->toContain('admin-session-sentinel');
    expect(app(ConnOperationSecrets::class)->resolve($operation, $environment->id, 'pairing-create', 'environment')->value['admin_session'])->toBe('admin-session-sentinel');
    $pairing->update(['conn_environment_id' => ConnEnvironment::query()->create(['environment_id' => 'env-other', 'label' => 'other', 'node_id' => $this->caller->id,
        'url' => 'http://10.44.0.31:3773', 'admin_session' => 'other-session', 'admin_session_expires_at' => now()->addDay(), 'registered_at' => now()])->id]);
    expect(fn () => app(ConnOperationSecrets::class)->resolve($operation->fresh(), $environment->id, 'pairing-create', 'environment'))->toThrow(ResourceOperationException::class);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});
