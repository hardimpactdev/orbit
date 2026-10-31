<?php

declare(strict_types=1);

use App\Domain\Metrics\ExporterPreference;
use App\Domain\Metrics\ExporterPreferenceRepository;
use App\Domain\Metrics\MetricsFleetCapture;
use App\Domain\Metrics\MetricsRoleReconcileImpact;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->manifest = tempnam(sys_get_temp_dir(), 'fleet-agent-');
    file_put_contents($this->manifest, json_encode(['commit' => str_repeat('c', 40), 'version' => '0.5.0',
        'artifacts' => ['linux-x86_64' => ['sha256' => str_repeat('a', 64)]]], JSON_THROW_ON_ERROR));
    app()->instance(AgentDelivery::class, new AgentDelivery($this->manifest, sys_get_temp_dir()));
    $this->nodes = [];
    foreach ([9 => ['metrics'], 13 => ['app-dev'], 1 => ['vpn'], 18 => []] as $id => $roles) {
        $node = Node::query()->forceCreate(['id' => $id, 'name' => 'node-'.$id, 'status' => 'active', 'platform' => 'linux',
            'architecture' => 'x86_64', 'public_ssh_host' => '192.0.2.'.$id, 'wireguard_ip' => '10.44.0.'.$id,
            'user' => 'orbit', 'ssh_host_fingerprint' => 'fixture']);
        foreach ($roles as $role) {
            $node->roles()->create(['role' => $role, 'status' => 'active']);
        }
        $this->nodes[$id] = $node;
    }
    app(ExporterPreferenceRepository::class)->put(18, ExporterPreference::Enabled);
    config(['swamp_workers.nodes' => [9, 13]]);
});
afterEach(function (): void {
    unlink($this->manifest);
});

it('captures the complete owning fleet without effects credentials or worker filtering', function (): void {
    $before = Operation::query()->count();
    DB::enableQueryLog();
    $plan = app(MetricsFleetCapture::class)->capture();
    expect(array_column($plan['fleet'], 'node_id'))->toBe([1, 9, 13, 18]);
    $members = array_column($plan['fleet'], null, 'node_id');
    expect($members[1]['selected'])->toBeTrue()->and($members[1]['worker'])->toBeFalse()
        ->and($members[18]['selected'])->toBeTrue()->and($members[18]['worker'])->toBeFalse()
        ->and($members[13]['worker'])->toBeTrue()
        ->and($plan['runtime_files'])->not->toHaveKey('/etc/orbit/metrics/grafana/admin-password')
        ->and(Operation::query()->count())->toBe($before);
    MetricsFleetCapture::assertDigest($plan);
    expect(json_encode(DB::getQueryLog(), JSON_THROW_ON_ERROR))->not->toContain('metrics.credentials');
});

it('classifies adding and removing a CI or database role as unchanged while app-dev remains', function (string $role): void {
    $capture = app(MetricsFleetCapture::class);
    $before = $capture->capture();
    $assignment = $this->nodes[13]->roles()->create(['role' => $role, 'status' => 'active']);
    $after = $capture->capture();
    $impact = app(MetricsRoleReconcileImpact::class);
    expect($impact->compare($before, $after)['required'])->toBeFalse();
    $assignment->delete();
    expect($impact->compare($after, $capture->capture())['required'])->toBeFalse();
    expect(array_column($after['fleet'], 'node_id'))->toContain(1, 18);
})->with(['ci-runner', 'database']);

it('requires reconciliation when exporter selection changes', function (): void {
    $capture = app(MetricsFleetCapture::class);
    $before = $capture->capture();
    app(ExporterPreferenceRepository::class)->put(13, ExporterPreference::Disabled);
    expect(app(MetricsRoleReconcileImpact::class)->compare($before, $capture->capture())['required'])->toBeTrue();
});

it('requires reconciliation when a Node identity changes its runtime labels', function (): void {
    $capture = app(MetricsFleetCapture::class);
    $before = $capture->capture();
    $this->nodes[13]->update(['name' => 'renamed-shark']);
    expect(app(MetricsRoleReconcileImpact::class)->compare($before, $capture->capture())['required'])->toBeTrue();
});

it('refuses a changed capture instead of accepting an asserted unchanged projection', function (): void {
    $plan = app(MetricsFleetCapture::class)->capture();
    $changed = $plan;
    array_pop($changed['fleet']);
    expect(fn () => app(MetricsRoleReconcileImpact::class)->compare($plan, $changed))->toThrow(ResourceOperationException::class);
});

it('refuses a rehashed incomplete capture', function (): void {
    $plan = ['fleet' => [['node_id' => 13, 'worker' => false]]];
    $plan['digest'] = MetricsFleetCapture::digest($plan);
    expect(fn () => app(MetricsRoleReconcileImpact::class)->compare($plan, $plan))->toThrow(ResourceOperationException::class);
});

it('refuses a rehashed full-shaped capture that omits the worker-free VPN', function (): void {
    $plan = app(MetricsFleetCapture::class)->capture();
    $plan['fleet'] = array_values(array_filter($plan['fleet'], static fn (array $node): bool => $node['node_id'] !== 1));
    unset($plan['digest']);
    $plan['digest'] = MetricsFleetCapture::digest($plan);
    expect(fn () => app(MetricsRoleReconcileImpact::class)->compare($plan, $plan))->toThrow(ResourceOperationException::class);
});

it('refuses a stale post-role capture instead of silently missing another desired change', function (): void {
    $plan = app(MetricsFleetCapture::class)->capture();
    $this->nodes[13]->update(['name' => 'changed-after-capture']);
    expect(fn () => app(MetricsRoleReconcileImpact::class)->compare($plan, $plan))->toThrow(ResourceOperationException::class);
});

it('retains managed Ingress publication when desired Caddy monitoring is disabled', function (): void {
    $cluster = Cluster::query()->create(['name' => 'metrics-ingress-fixture']);
    $this->nodes[13]->update(['cluster_id' => $cluster->id]);
    $this->nodes[13]->roles()->create(['role' => 'ingress', 'status' => 'active', 'cluster_id' => $cluster->id]);
    app(ExporterPreferenceRepository::class)->put(13, ExporterPreference::Disabled);
    $plan = app(MetricsFleetCapture::class)->capture();
    $members = array_column($plan['fleet'], null, 'node_id');
    expect($members[13]['caddy'])->toBeFalse()->and($members[13]['caddy_managed'])->toBeTrue()
        ->and($members[18]['caddy_managed'])->toBeFalse();
});

it('captures provisioning and removing Metrics assignments for one native role root without effects', function (): void {
    $assignment = $this->nodes[9]->roles()->where('role', 'metrics')->firstOrFail();
    $capture = app(MetricsFleetCapture::class);
    $assignment->update(['status' => 'provisioning']);
    $adding = $capture->captureFor($assignment);
    expect(array_column($adding['fleet'], 'node_id'))->toBe([1, 9, 13, 18]);
    MetricsFleetCapture::assertDigest($adding);
    $assignment->update(['status' => 'removing']);
    $removing = $capture->captureFor($assignment, false);
    expect(array_column($removing['fleet'], 'node_id'))->toBe([1, 9, 13, 18]);
    expect(array_column($removing['fleet'], 'selected'))->each->toBeFalse();
    expect(array_column($removing['fleet'], 'fpm'))->each->toBeFalse();
    MetricsFleetCapture::assertDigest($removing);
    expect(Operation::query()->count())->toBe(0);
});

it('captures complete retirement intent without dropping VPN or USExit or changing role state', function (): void {
    $plan = app(MetricsFleetCapture::class)->captureRetiring($this->nodes[13]);
    expect(array_column($plan['fleet'], 'node_id'))->toBe([1, 9, 13, 18]);
    $members = array_column($plan['fleet'], null, 'node_id');
    expect($members[13]['selected'])->toBeFalse()->and($members[13]['fpm'])->toBeFalse()
        ->and($members[1]['selected'])->toBeTrue()->and($members[18]['selected'])->toBeTrue()
        ->and($this->nodes[13]->roles()->where('role', 'app-dev')->value('status')->value)->toBe('active')
        ->and(Operation::query()->count())->toBe(0);
    expect($plan['runtime_files']['/etc/orbit/metrics/prometheus.yml'])->not->toContain('10.44.0.13:9100');
    MetricsFleetCapture::assertDigest($plan);
});

it('withdraws the entire desired fleet when the retiring Node owns Metrics', function (): void {
    $plan = app(MetricsFleetCapture::class)->captureRetiring($this->nodes[9]);
    expect(array_column($plan['fleet'], 'node_id'))->toBe([1, 9, 13, 18]);
    expect(array_column($plan['fleet'], 'selected'))->each->toBeFalse();
    MetricsFleetCapture::assertDigest($plan);
});

it('captures surviving cleanup for an offline Metrics owner without claiming target effects', function (): void {
    $this->nodes[9]->update(['status' => 'failed']);
    $plan = app(MetricsFleetCapture::class)->captureRetiring($this->nodes[9]);
    expect(array_column($plan['fleet'], 'node_id'))->toBe([1, 13, 18]);
    expect(array_column($plan['fleet'], 'selected'))->each->toBeFalse();
    expect(Operation::query()->count())->toBe(0);
    MetricsFleetCapture::assertDigest($plan);
});

it('reports no Metrics contribution when retirement has no Metrics assignment', function (): void {
    $this->nodes[9]->roles()->delete();
    expect(app(MetricsFleetCapture::class)->captureRetiring($this->nodes[13]))->toBeNull();
});

it('captures the exact Gateway for access revocation without changing host or intent state', function (): void {
    $gateway = Node::query()->forceCreate(['id' => 14, 'name' => 'gateway', 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'x86_64', 'wireguard_ip' => '10.44.0.14', 'public_ssh_host' => '192.0.2.14']);
    $gateway->roles()->create(['role' => 'gateway', 'status' => 'active']);
    expect(app(MetricsFleetCapture::class)->captureAccessRevocation())->toBe(['node_id' => 14]);
    expect(Operation::query()->count())->toBe(0);
});

it('requires a Gateway when Metrics streams may exist, and no stage when Metrics has no owner', function (): void {
    expect(fn () => app(MetricsFleetCapture::class)->captureAccessRevocation())->toThrow(ResourceOperationException::class);
    $this->nodes[9]->roles()->delete();
    expect(app(MetricsFleetCapture::class)->captureAccessRevocation())->toBeNull();
});
