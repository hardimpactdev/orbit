<?php

declare(strict_types=1);

use App\Domain\Metrics\MetricsCredentialManager;
use App\Domain\Metrics\MetricsFleetCapture;
use App\Domain\Metrics\MetricsFleetRuntimeMaterial;
use App\Domain\Nodes\NodeEnrollmentCandidate;
use App\Domain\Nodes\RoleName;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Metrics\NativeMetricsCredentialManager;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    config(['swamp_workers.nodes' => []]);
    $this->mock(MetricsCredentialManager::class)->shouldNotReceive('passwordForConvergence');
});

/** @return array{Node,array<string,mixed>} */
function admittedMetricsMaterialPlan(): array
{
    FleetFixtures::node('material-gateway', [RoleName::Gateway]);
    $node = FleetFixtures::node('material-metrics', [RoleName::Metrics]);
    app(SettingRepository::class)->put(new SettingScope(SettingScopeType::Node, $node->id),
        NativeMetricsCredentialManager::ActivePasswordKey, 'fixture-existing-grafana-secret', SettingValueProtection::Secret);

    return [$node, app(MetricsFleetCapture::class)->captureFor($node->roles->sole())];
}

function metricsMaterialCandidate(Node $node, string $address): NodeEnrollmentCandidate
{
    return new NodeEnrollmentCandidate($node->id, $node->name, $node->user, 'linux', $node->architecture,
        $address, $node->public_ssh_host, 22, null, null, null, null, null);
}

it('renders existing fleet credentials from an admitted plan using database reads only', function (): void {
    [$node, $plan] = admittedMetricsMaterialPlan();
    $before = $node->fresh()->getAttributes();
    $settingsBefore = Setting::query()->get()->toArray();
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $material = app(MetricsFleetRuntimeMaterial::class)->resolveFleetMaterial($plan, $node->id);

    expect(array_any(MetricsFleetCapture::members($plan), static fn (array $member): bool => $member['worker'] === true))->toBeFalse();
    expect($material->value['runtime'])->toBe(['operation' => 'converge', 'assignment' => $plan['assignment_id'],
        'configuration' => [...$plan['runtime_files'], '/etc/orbit/metrics/grafana/admin-password' => 'fixture-existing-grafana-secret']])
        ->and($material->value['verify'])->toBe(['operation' => 'verify', 'password' => 'fixture-existing-grafana-secret'])
        ->and($material->strings())->toContain('fixture-existing-grafana-secret')
        ->and(json_encode($material))->toBe('"[REDACTED]"')
        ->and($node->fresh()->getAttributes())->toBe($before)
        ->and(Setting::query()->get()->toArray())->toBe($settingsBefore)
        ->and(Operation::query()->count())->toBe(0)
        ->and(implode('\n', $queries))->not->toMatch('/\b(?:insert|update|delete)\b/i');
});

it('reads the same existing type for its real prospective Metrics owner without active-state writes', function (): void {
    [$node, $plan] = admittedMetricsMaterialPlan();
    $address = $node->wireguard_ip;
    $node->update(['status' => LifecycleStatus::Provisioning, 'wireguard_ip' => null]);
    $node->roles()->update(['status' => LifecycleStatus::Provisioning]);
    $before = $node->fresh()->getAttributes();
    $value = app(MetricsFleetRuntimeMaterial::class)->resolveFleetMaterial($plan, $node->id, metricsMaterialCandidate($node, $address));
    expect($value->value['verify']['password'])->toBe('fixture-existing-grafana-secret')
        ->and($node->fresh()->getAttributes())->toBe($before);
    expect(fn () => app(MetricsFleetRuntimeMaterial::class)->resolveFleetMaterial($plan, $node->id))
        ->toThrow(ResourceOperationException::class);
});

it('refuses a changed owner or assignment before reading credential settings', function (string $change): void {
    [$node, $plan] = admittedMetricsMaterialPlan();
    $id = $node->id;
    match ($change) {
        'owner-argument' => $id++,
        'assignment' => $plan['assignment_id']++,
        'failed-assignment' => $node->roles()->update(['status' => LifecycleStatus::Failed]),
        'address' => $node->update(['wireguard_ip' => '10.44.0.222']),
        'inactive' => $node->update(['status' => LifecycleStatus::Provisioning]),
        'digest' => $plan['runtime_files']['/etc/orbit/metrics/unadmitted'] = 'changed',
    };
    if ($change === 'assignment') {
        unset($plan['digest']);
        $plan['digest'] = MetricsFleetCapture::digest($plan);
    }
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    expect(fn () => app(MetricsFleetRuntimeMaterial::class)->resolveFleetMaterial($plan, $id))
        ->toThrow(ResourceOperationException::class);
    expect(implode('\n', $queries))->not->toContain('"settings"');
})->with(['owner-argument', 'assignment', 'failed-assignment', 'address', 'inactive', 'digest']);

it('rejects a foreign explicit candidate even when the admitted owner is active', function (): void {
    [$node, $plan] = admittedMetricsMaterialPlan();
    $other = FleetFixtures::node('other-material-owner');
    expect(fn () => app(MetricsFleetRuntimeMaterial::class)->resolveFleetMaterial($plan, $node->id,
        metricsMaterialCandidate($other, $plan['metrics_address'])))
        ->toThrow(ResourceOperationException::class);
});

it('refuses a missing existing password without minting or storing one', function (): void {
    [$node, $plan] = admittedMetricsMaterialPlan();
    app(SettingRepository::class)->delete(new SettingScope(SettingScopeType::Node, $node->id), NativeMetricsCredentialManager::ActivePasswordKey);
    $before = Setting::query()->get()->toArray();
    expect(fn () => app(MetricsFleetRuntimeMaterial::class)->resolveFleetMaterial($plan, $node->id))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('metrics.credentials_missing'));
    expect(Setting::query()->get()->toArray())->toBe($before);
});

it('preserves the retained Operation ownership checks while sharing the read-only renderer', function (): void {
    [$node, $plan] = admittedMetricsMaterialPlan();
    $operation = Operation::query()->create(['kind' => 'node.role_converge', 'target_type' => 'node', 'target_id' => $node->id,
        'node_id' => $node->id, 'status' => 'running', 'sequence' => 1,
        'input' => ['_role' => ['metrics_fleet' => $plan], 'private_bindings' => ['metrics_runtime' => [
            'owner_node_id' => $node->id, 'read_node_ids' => [$node->id], 'write_node_ids' => [],
        ]]], 'state' => ['swamp_run_id' => 'ec3c90a5-9838-4e0b-bb1e-a1d0308a27f8']]);
    $source = app(MetricsFleetRuntimeMaterial::class);
    expect($source->resolve($operation, $node->id, '', 'metrics_runtime')->value)
        ->toBe($source->resolveFleetMaterial($plan, $node->id)->value);
    expect(fn () => $source->resolve($operation, $node->id, '', 'runtime_material'))->toThrow(ResourceOperationException::class);
    $operation->status = 'succeeded';
    expect(fn () => $source->resolve($operation, $node->id, '', 'metrics_runtime'))->toThrow(ResourceOperationException::class);
});
