<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Metrics\MetricsCredentialResetProjection;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Metrics\NativeMetricsCredentialManager;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    Http::preventStrayRequests();
    $make = static fn (string $name, string $ip): Node => Node::query()->create(['name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => $ip, 'public_ssh_host' => $ip, 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']);
    $this->gateway = $this->markAsGateway($make('gateway', '10.44.0.2'));
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $this->target = $make('metrics', '10.44.0.3');
    $this->target->roles()->create(['role' => 'metrics', 'status' => 'active']);
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = static fn (array $r): array => $r['type'] === 'vault.put'
        ? [['type' => 'vault.put', 'id' => $r['id'], 'payload' => ['data' => ['vaultName' => $r['payload']['vaultName'], 'secretKey' => $r['payload']['key'], 'vaultType' => 'local_encryption']]]]
        : [['type' => 'event', 'id' => $r['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-metrics-credentials-reset', 'runId' => '2d2deaf0-e03d-49fb-a6e1-57a038a92676']]];
    app()->instance(WebSocketClient::class, $this->socket);
    config(['swamp.run_token' => 'fixture-run', 'swamp.read_token' => 'fixture-read']);
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('public-fixture-root');
    app(SettingRepository::class)->put(new SettingScope(SettingScopeType::Node, $this->target->id), NativeMetricsCredentialManager::ActivePasswordKey, 'fixture-grafana-private-value', SettingValueProtection::Secret);
});

it('admits a credential reset with names-only native input and encrypted pending state', function (): void {
    $this->post('/api/v1/metrics/credentials/reset')->assertAccepted()->assertHeader('Cache-Control', 'no-store, private');
    $op = Operation::query()->sole();
    $scope = new SettingScope(SettingScopeType::Node, $this->target->id);
    $pending = app(SettingRepository::class)->get($scope, NativeMetricsCredentialManager::PendingPasswordKey);
    expect($op->kind)->toBe('metrics.credentials_reset');
    expect(array_column($this->socket->sent, 'type'))->toBe(['vault.put', 'workflow.run']);
    $native = $this->socket->sent[1]['payload']['inputs'];
    expect($native['execution_secrets']['credentials'])->toBe(['vault' => 'orbit-control-inputs', 'key' => 'metrics-credentials-operation-'.$op->id]);
    expect(json_encode([$op->toArray(), $native]))->not->toContain('fixture-grafana-private-value', $pending);
    expect(json_decode($this->socket->sent[0]['payload']['value'], true)['pending_password'])->toBe($pending);
    expect($op->input['ssh_execution']['target']['host_keys'])->toBe(['ssh-ed25519 fixture-public-key']);
});

it('promotes only after native success and remains stable on duplicate delivery', function (): void {
    $this->post('/api/v1/metrics/credentials/reset')->assertAccepted();
    $op = Operation::query()->sole();
    $scope = new SettingScope(SettingScopeType::Node, $this->target->id);
    $settings = app(SettingRepository::class);
    $pending = $settings->get($scope, NativeMetricsCredentialManager::PendingPasswordKey);
    $data = ['run_id' => $op->state['swamp_run_id'], 'status' => 'succeeded', 'result' => ['reset' => true], 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String()];
    $projection = app(MetricsCredentialResetProjection::class);
    $projection->apply($op, $data);
    $projection->apply($op->fresh(), $data);
    expect($op->fresh()->status->value)->toBe('succeeded');
    expect($op->fresh()->result)->toBe(['reset' => true]);
    expect($settings->get($scope, NativeMetricsCredentialManager::ActivePasswordKey))->toBe($pending);
    expect($settings->get($scope, NativeMetricsCredentialManager::PendingPasswordKey))->toBeNull();
});

it('retains pending and active credentials after failure or interruption', function (string $status): void {
    $this->post('/api/v1/metrics/credentials/reset')->assertAccepted();
    $op = Operation::query()->sole();
    $scope = new SettingScope(SettingScopeType::Node, $this->target->id);
    $settings = app(SettingRepository::class);
    $pending = $settings->get($scope, NativeMetricsCredentialManager::PendingPasswordKey);
    app(MetricsCredentialResetProjection::class)->apply($op, ['run_id' => $op->state['swamp_run_id'], 'status' => $status, 'error_code' => 'operation.native_failed', 'result' => ['error_code' => 'operation.native_failed'], 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String()]);
    expect($settings->get($scope, NativeMetricsCredentialManager::ActivePasswordKey))->toBe('fixture-grafana-private-value');
    expect($settings->get($scope, NativeMetricsCredentialManager::PendingPasswordKey))->toBe($pending);
})->with(['failed', 'interrupted']);

it('reuses pending state without excluding another native reset in the Gateway', function (): void {
    $this->post('/api/v1/metrics/credentials/reset')->assertAccepted();
    $first = Operation::query()->sole();
    $this->post('/api/v1/metrics/credentials/reset')->assertAccepted();
    expect(Operation::query()->count())->toBe(2);
    expect(Operation::query()->latest('id')->first()->input['pending_fingerprint'])->toBe($first->input['pending_fingerprint']);
});

it('reuses a failed candidate in a fresh Operation and promotes only on fresh success', function (): void {
    $this->post('/api/v1/metrics/credentials/reset')->assertAccepted();
    $first = Operation::query()->sole();
    $scope = new SettingScope(SettingScopeType::Node, $this->target->id);
    $settings = app(SettingRepository::class);
    $pending = $settings->get($scope, NativeMetricsCredentialManager::PendingPasswordKey);
    $projection = app(MetricsCredentialResetProjection::class);
    $times = ['started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String()];
    $projection->apply($first, $times + ['run_id' => $first->state['swamp_run_id'], 'status' => 'failed', 'error_code' => 'operation.native_failed', 'result' => ['error_code' => 'operation.native_failed']]);

    $this->post('/api/v1/metrics/credentials/reset')->assertAccepted();
    $fresh = Operation::query()->latest('id')->firstOrFail();
    expect($fresh->id)->not->toBe($first->id);
    expect($fresh->input['pending_fingerprint'])->toBe($first->input['pending_fingerprint']);
    $projection->apply($fresh, $times + ['run_id' => $fresh->state['swamp_run_id'], 'status' => 'succeeded', 'result' => ['reset' => true]]);

    expect($first->fresh()->status->value)->toBe('failed');
    expect($fresh->fresh()->status->value)->toBe('succeeded');
    expect($settings->get($scope, NativeMetricsCredentialManager::ActivePasswordKey))->toBe($pending);
    expect($settings->get($scope, NativeMetricsCredentialManager::PendingPasswordKey))->toBeNull();
});

it('reads stored credentials without a native request or host execution', function (): void {
    $this->getJson('/api/v1/metrics/credentials')->assertOk()->assertJsonPath('data.password', 'fixture-grafana-private-value');
    expect($this->socket->sent)->toBe([]);
    expect(Operation::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('refuses missing host pins before creating pending state', function (): void {
    $this->target->update(['ssh_host_key' => null]);
    $this->post('/api/v1/metrics/credentials/reset')->assertStatus(503);
    expect(Operation::query()->count())->toBe(0);
    expect($this->socket->sent)->toBe([]);
    expect(app(SettingRepository::class)->get(new SettingScope(SettingScopeType::Node, $this->target->id), NativeMetricsCredentialManager::PendingPasswordKey))->toBeNull();
});
