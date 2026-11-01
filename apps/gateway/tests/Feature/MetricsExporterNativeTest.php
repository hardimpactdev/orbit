<?php

declare(strict_types=1);

use App\Domain\Metrics\ExporterPreference;
use App\Domain\Metrics\ExporterPreferenceRepository;
use App\Domain\Metrics\MetricsExporterOperationProjection;
use App\Domain\Nodes\RoleName;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVm;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    Http::preventStrayRequests();
    $make = static fn (string $name, string $ip): Node => Node::query()->create(['name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => $ip, 'public_ssh_host' => $ip, 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fixture',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']);
    $this->gateway = $this->markAsGateway($make('gateway', '10.44.0.2'));
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $this->metrics = $make('metrics', '10.44.0.3');
    $this->assignment = $this->metrics->roles()->create(['role' => RoleName::Metrics, 'status' => 'active']);
    $this->target = $make('workload', '10.44.0.9');
    $this->url = '/api/v1/metrics/exporters/'.$this->target->id;
    $this->socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $this->socket);
    config(['swamp.run_token' => 'fixture-run', 'swamp.read_token' => 'fixture-read']);
    app(SettingRepository::class)->put(new SettingScope(SettingScopeType::Node, $this->metrics->id), 'metrics.grafana.admin_password', 'never-copy-grafana-secret', SettingValueProtection::Secret);
});

it('admits one public immutable native exporter intent without a Grafana execution copy', function (bool $enabled): void {
    $response = $enabled ? $this->putJson($this->url) : $this->deleteJson($this->url);
    $response->assertAccepted();
    $operation = Operation::query()->sole();
    $intent = $operation->input;
    expect($intent)->toMatchArray(['exporter_node_id' => $this->target->id, 'enabled' => $enabled, 'metrics_address' => '10.44.0.3']);
    expect($intent['ssh_execution']['control_node_id'])->toBe(209);
    expect($intent['ssh_execution']['target']['host_keys'])->toBe(['ssh-ed25519 fixture-public-key']);
    expect(str_contains($intent['runtime']['configuration'], '10.44.0.9:9100'))->toBe($enabled);
    expect($intent['runtime']['configuration'])->toContain('10.44.0.3:9100');
    expect($intent['service']['rules'][0])->toMatchArray(['port' => 9100, 'source' => '10.44.0.3', 'action' => 'allow', 'ensure' => $enabled ? 'present' : 'absent']);
    expect($intent['service']['rules'][1])->toMatchArray(['port' => 9102, 'source' => '10.44.0.3', 'ensure' => $enabled ? 'present' : 'absent']);
    expect(array_column($intent['service']['units'], 'enabled', 'service'))->toBe([
        'prometheus-node-exporter.service' => $enabled,
        'orbit-cadvisor.service' => $enabled,
        'orbit-fpm-exporter.service' => false,
    ]);
    $dropin = collect($intent['service']['files'])->firstWhere('path', '/etc/systemd/system/prometheus-node-exporter.service.d/orbit.conf');
    expect($dropin)->toMatchArray(['owner' => 'root', 'group' => 'root', 'mode' => '0644', 'ensure' => $enabled ? 'present' : 'absent']);
    expect($dropin['content'])->toContain('--web.listen-address=10.44.0.9:9100');
    expect($this->socket->sent)->toHaveCount(1);
    expect($this->socket->sent[0]['payload']['workflowIdOrName'])->toBe('operation-metrics-exporter');
    expect($this->socket->sent[0]['payload']['inputs']['intent'])->toBe($intent);
    expect(json_encode($operation->toArray()))->not->toContain('never-copy-grafana-secret', '$secret');
    expect(app(ExporterPreferenceRepository::class)->get($this->target->id))->toBe($enabled ? ExporterPreference::Enabled : ExporterPreference::Disabled);
    $before = app(MetricsExporterOperationProjection::class)->input($operation);
    $this->target->update(['wireguard_ip' => '10.44.0.99']);
    expect(app(MetricsExporterOperationProjection::class)->input($operation->fresh()))->toBe($before);
    Http::assertNothingSent();
})->with([true, false]);

it('keeps a preference-only native intent when Metrics is not enabled', function (): void {
    $this->assignment->delete();
    $this->putJson($this->url)->assertAccepted();
    expect(Operation::query()->sole()->input['runtime'])->toBeNull();
    expect(app(ExporterPreferenceRepository::class)->get($this->target->id))->toBe(ExporterPreference::Enabled);
});

it('refuses platform eligibility missing pins owner disable and assignment drift before native submission', function (string $case): void {
    match ($case) {
        'inactive' => $this->target->update(['status' => 'failed']),
        'macos' => $this->target->update(['platform' => 'macos']),
        'unmanaged' => $this->target->update(['ssh_host_fingerprint' => null]),
        'pins' => $this->target->update(['ssh_host_key' => null]),
        'owner' => $this->target->roles()->create(['role' => RoleName::Metrics, 'status' => 'active']),
        'task_vm' => TaskVm::query()->create(['node_id' => $this->target->id, 'host_node_id' => $this->gateway->id, 'provider' => 'incus', 'name' => 'fixture-task-vm', 'state' => 'ready', 'wireguard_ip' => '10.44.0.9', 'pi_token' => 'fixture-private-placeholder']),
        'drift' => $this->gateway->roles()->create(['role' => RoleName::Metrics, 'status' => 'active']),
    };
    $response = $case === 'owner' ? $this->deleteJson($this->url) : $this->putJson($this->url);
    expect($response->status())->toBeIn([409, 422, 503]);
    expect(Operation::query()->count())->toBe(0);
    expect(app(ExporterPreferenceRepository::class)->get($this->target->id))->toBeNull();
    expect($this->socket->sent)->toBe([]);
})->with(['inactive', 'macos', 'unmanaged', 'pins', 'owner', 'drift', 'task_vm']);

it('returns the admitted Operation on idempotent replay without changing the desired preference again', function (): void {
    $this->withHeader('Idempotency-Key', 'exporter-enable-fixture')->putJson($this->url)->assertAccepted();
    app(ExporterPreferenceRepository::class)->put($this->target->id, ExporterPreference::Disabled);
    $this->withHeader('Idempotency-Key', 'exporter-enable-fixture')->putJson($this->url)->assertAccepted();
    expect(Operation::query()->count())->toBe(1);
    expect($this->socket->sent)->toHaveCount(1);
    expect(app(ExporterPreferenceRepository::class)->get($this->target->id))->toBe(ExporterPreference::Disabled);
});

it('projects the exact native success through the shared terminal reader', function (): void {
    $this->putJson($this->url)->assertAccepted();
    $op = Operation::query()->sole();
    $run = ['id' => $op->state['swamp_run_id'], 'workflowName' => 'operation-metrics-exporter', 'status' => 'succeeded',
        'inputs' => ['operation_id' => $op->id, 'kind' => $op->kind, 'node_id' => $op->node_id],
        'startedAt' => now()->toIso8601String(), 'completedAt' => now()->toIso8601String(),
        'jobs' => [['name' => 'exporter', 'steps' => [['name' => 'result', 'outputs' => ['stdout' => '{"ready":true}']]]]]];
    $this->socket->reply = static fn (array $r): array => [['id' => $r['id'], 'type' => 'workflow.history.get', 'payload' => ['data' => $run]]];
    app(SwampCommandStep::class)->projectTerminal($op, $run, ['job' => 'exporter', 'step' => 'result']);
    expect($op->fresh()->status->value)->toBe('succeeded');
    expect($op->fresh()->result)->toMatchArray(['node_id' => $this->target->id, 'status' => 'enabled', 'preference_only' => false]);
});

it('records preferences without a Gateway execution-lifetime hold', function (): void {
    $this->putJson($this->url)->assertAccepted();
    $this->putJson('/api/v1/metrics/exporters/'.$this->gateway->id)->assertAccepted();
    expect(Operation::query()->count())->toBe(2);
    expect(app(ExporterPreferenceRepository::class)->get($this->gateway->id))->toBe(ExporterPreference::Enabled);
    expect($this->socket->sent)->toHaveCount(2);
});
