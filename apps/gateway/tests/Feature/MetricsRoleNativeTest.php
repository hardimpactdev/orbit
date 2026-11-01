<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Instances\ProductionPhpRuntimeIdentity;
use App\Domain\Metrics\MetricsFleetCapture;
use App\Domain\Metrics\MetricsRoleProjection;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Metrics\NativeMetricsCredentialManager;
use App\Models\Activity;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Project;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    Http::preventStrayRequests();
    $make = static fn (string $name, string $ip): Node => Node::query()->create(['name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => $ip, 'public_ssh_host' => $ip, 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']);
    $this->gateway = $this->markAsGateway($make('gateway', '10.44.0.2'));
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $this->target = $make('metrics', '10.44.0.3');
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = static fn (array $r): array => $r['type'] === 'vault.put'
        ? [['type' => 'vault.put', 'id' => $r['id'], 'payload' => ['data' => ['vaultName' => $r['payload']['vaultName'], 'secretKey' => $r['payload']['key'], 'vaultType' => 'local_encryption']]]]
        : [['type' => 'event', 'id' => $r['id'], 'event' => ['kind' => 'started', 'workflowName' => $r['payload']['workflowIdOrName'], 'runId' => '2d2deaf0-e03d-49fb-a6e1-57a038a92676']]];
    app()->instance(WebSocketClient::class, $this->socket);
    config(['swamp.run_token' => 'fixture-run', 'swamp.read_token' => 'fixture-read']);
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('public-fixture-root');
    app(SettingRepository::class)->put(new SettingScope(SettingScopeType::Node, $this->target->id), NativeMetricsCredentialManager::ActivePasswordKey, 'fixture-grafana-private-value', SettingValueProtection::Secret);
});

it('admits a complete public role intent and a names-only password execution copy', function (): void {
    $this->postJson('/api/v1/metrics', ['node_id' => $this->target->id])->assertAccepted();
    $op = Operation::query()->sole();
    expect($op->kind)->toBe('metrics.enable');
    expect($op->input['enabled'])->toBeTrue();
    expect(array_column($this->socket->sent, 'type'))->toBe(['vault.put', 'workflow.run']);
    $input = $this->socket->sent[1]['payload']['inputs'];
    expect($input['execution_secrets']['password'])->toBe(['vault' => 'orbit-control-inputs', 'key' => 'metrics-role-operation-'.$op->id]);
    expect(json_encode([$op->toArray(), $input]))->not->toContain('fixture-grafana-private-value', 'PRIVATE KEY');
    expect($op->input['caddy']['caddy']['content'] ?? json_encode($op->input['caddy']))->toContain('metrics.orbit');
    expect(NodeRole::query()->where('role', RoleName::Metrics)->sole()->status->value)->toBe('provisioning');
    Http::assertNothingSent();
});

it('retains the role and data intent until native terminal success', function (bool $purge): void {
    $assignment = $this->target->roles()->create(['role' => RoleName::Metrics, 'status' => 'active']);
    $this->deleteJson('/api/v1/metrics', ['force' => true, 'purge_data' => $purge])->assertAccepted();
    $op = Operation::query()->sole();
    expect($assignment->fresh()->status->value)->toBe('removing');
    expect(array_column($this->socket->sent, 'type'))->toBe(['workflow.run']);
    expect($op->input['purge_volumes'])->toHaveCount($purge ? 2 : 0);
    app(MetricsRoleProjection::class)->apply($op, ['run_id' => $op->state['swamp_run_id'], 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'status' => 'succeeded', 'result' => ['ready' => true]]);
    expect($assignment->fresh())->toBeNull();
    expect($op->fresh()->status->value)->toBe('succeeded');
    expect(app(SettingRepository::class)->get(new SettingScope(SettingScopeType::Node, $this->target->id), NativeMetricsCredentialManager::ActivePasswordKey))->toBe($purge ? null : 'fixture-grafana-private-value');
})->with([false, true]);

it('preserves failed role state and private settings for native recovery', function (): void {
    $this->postJson('/api/v1/metrics', ['node_id' => $this->target->id])->assertAccepted();
    $op = Operation::query()->sole();
    app(MetricsRoleProjection::class)->apply($op, ['run_id' => $op->state['swamp_run_id'], 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'status' => 'failed', 'error_code' => 'operation.native_failed', 'result' => ['error_code' => 'operation.native_failed']]);
    expect(NodeRole::query()->where('role', RoleName::Metrics)->sole()->status->value)->toBe('failed');
    expect($op->fresh()->result)->toBe(['error_code' => 'operation.native_failed']);
});

it('refuses singleton drift unsupported platform and missing SSH before submission', function (string $case): void {
    match ($case) {
        'existing' => $this->target->roles()->create(['role' => RoleName::Metrics, 'status' => 'failed']),
        'macos' => $this->target->update(['platform' => 'macos']),
        'ssh' => $this->target->update(['ssh_host_key' => null]),
        'inactive' => $this->target->update(['status' => 'failed']),
    };
    $response = $this->postJson('/api/v1/metrics', ['node_id' => $this->target->id]);
    expect($response->status())->toBeIn([409, 422, 503]);
    expect($this->socket->sent)->toBe([]);
    expect(Operation::query()->count())->toBe(0);
})->with(['existing', 'macos', 'ssh', 'inactive']);

it('replays role admission without a Gateway execution hold', function (): void {
    $this->withHeader('Idempotency-Key', 'metrics-role-fixture')->postJson('/api/v1/metrics', ['node_id' => $this->target->id])->assertAccepted();
    $this->withHeader('Idempotency-Key', 'metrics-role-fixture')->postJson('/api/v1/metrics', ['node_id' => $this->target->id])->assertAccepted();
    expect(Operation::query()->count())->toBe(1);
    expect($this->socket->sent)->toHaveCount(2);
    $this->withoutHeader('Idempotency-Key')->putJson('/api/v1/metrics/exporters/'.$this->gateway->id)->assertAccepted();
    expect(Operation::query()->count())->toBe(2);
    expect(app(DirectOperation::class)->definition('metrics.enable')['workflow'])->toBe('operation-metrics-role');
});

it('requires explicit removal consent before any native effect', function (): void {
    $assignment = $this->target->roles()->create(['role' => RoleName::Metrics, 'status' => 'active']);
    $this->deleteJson('/api/v1/metrics', ['force' => false])->assertUnprocessable()->assertJsonPath('error.code', 'metrics.force_required');
    expect($assignment->fresh()->status->value)->toBe('active');
    expect($this->socket->sent)->toBe([]);
});

it('records accepted enable and disable through the existing command activity boundary', function (): void {
    $this->postJson('/api/v1/metrics', ['node_id' => $this->target->id])->assertAccepted();
    expect(Activity::query()->where('command', 'metrics:enable')->exists())->toBeTrue();
});

it('keeps duplicate terminal delivery stable after removing the assignment', function (): void {
    $this->target->roles()->create(['role' => RoleName::Metrics, 'status' => 'active']);
    $this->deleteJson('/api/v1/metrics', ['force' => true])->assertAccepted();
    $op = Operation::query()->sole();
    $data = ['run_id' => $op->state['swamp_run_id'], 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'status' => 'succeeded', 'result' => ['ready' => true]];
    $projection = app(MetricsRoleProjection::class);
    $projection->apply($op, $data);
    $result = $op->fresh()->result;
    $projection->apply($op->fresh(), $data);
    expect($op->fresh()->result)->toBe($result);
    expect(NodeRole::query()->where('role', RoleName::Metrics)->exists())->toBeFalse();
});

it('captures disabled service metrics for Nodes with production PHP', function (): void {
    $this->target->roles()->create(['role' => RoleName::Metrics, 'status' => 'active']);
    $this->gateway->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']);
    $project = Project::query()->create(['name' => 'monitored', 'slug' => 'monitored', 'repository_url' => 'https://example.test/monitored.git', 'default_branch' => 'main', 'root' => 'public']);
    $user = 'orbit-app-'.$project->id;
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->gateway->id, 'name' => 'default', 'environment' => 'production', 'status' => 'active', 'checkout_path' => '/home/'.$user.'/releases/initial', 'production_user' => $user, 'production_home' => '/home/'.$user, 'root' => 'public', 'selected_php_version' => '8.5']);
    $instance->update(ProductionPhpRuntimeIdentity::forProvisioning($instance, '8.5')->attributes());
    expect(app(MetricsFleetCapture::class)->forExporter($this->target, $this->gateway, true)['units'][2]['enabled'])->toBeTrue();

    $this->deleteJson('/api/v1/metrics', ['force' => true])->assertAccepted();
    $member = collect(Operation::query()->sole()->input['fleet'])->firstWhere('exporter_node_id', $this->gateway->id);
    expect($member['enabled'])->toBeFalse();
    expect(array_column($member['service']['units'], 'enabled'))->toBe([false, false, false]);
    expect(array_column($member['service']['rules'], 'ensure'))->toBe(['absent', 'absent', 'absent', 'absent']);
    expect($member['service']['downloads'])->toBe([]);
});

it('authorizes role admission against directed Gateway access', function (bool $gatewayAccess): void {
    $consumer = Node::query()->create(['name' => 'consumer', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.99', 'wireguard_ip' => '10.44.0.99']);
    $consumer->accessibleNodes()->attach($gatewayAccess ? $this->gateway : $this->target);
    $response = $this->withServerVariables(['REMOTE_ADDR' => $consumer->wireguard_ip])->postJson('/api/v1/metrics', ['node_id' => $this->target->id]);
    if ($gatewayAccess) {
        $response->assertAccepted();
        expect(Operation::query()->sole()->kind)->toBe('metrics.enable');
    } else {
        $response->assertForbidden();
        expect($this->socket->sent)->toBe([]);
    }
})->with([true, false]);
