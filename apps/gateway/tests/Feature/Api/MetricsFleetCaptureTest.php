<?php

declare(strict_types=1);

use App\Domain\Metrics\ExporterPreference;
use App\Domain\Metrics\ExporterPreferenceRepository;
use App\Domain\Metrics\MetricsFleetCapture;
use App\Domain\Metrics\MetricsRoleReconcileImpact;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
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
