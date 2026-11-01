<?php

declare(strict_types=1);

use App\Domain\Metrics\ExporterPreference;
use App\Domain\Metrics\ExporterPreferenceRepository;
use App\Domain\Metrics\ExporterSelector;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Metrics\AgentMetricsExporterRuntime;
use App\Infrastructure\Metrics\MetricsExporterCommands;
use App\Infrastructure\Metrics\NativeMetricsExporterLifecycle;
use App\Infrastructure\Metrics\NativeMetricsExporterProjection;
use App\Models\Node;

it('returns stable targets from explicit defaults and prospective roles', function (): void {
    $metrics = metricsExporterLifecycleNode('metrics', '10.44.0.3');
    $metrics
        ->roles()
        ->create([
            'role' => RoleName::Metrics,
            'status' => LifecycleStatus::Provisioning,
        ]);
    $gateway = metricsExporterLifecycleNode('gateway', '10.44.0.1');
    $gateway
        ->roles()
        ->create([
            'role' => RoleName::Gateway,
            'status' => LifecycleStatus::Active,
        ]);
    $prospective = metricsExporterLifecycleNode('app-dev', '10.44.0.2');
    $prospective
        ->roles()
        ->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Provisioning,
        ]);
    $disabled = metricsExporterLifecycleNode('app-prod', '10.44.0.4');
    $disabled
        ->roles()
        ->create([
            'role' => RoleName::AppProd,
            'status' => LifecycleStatus::Active,
        ]);
    $roleless = metricsExporterLifecycleNode('orbit-ops', '10.44.0.5');
    $preferences = app(ExporterPreferenceRepository::class);
    $preferences->put($disabled->id, ExporterPreference::Disabled);
    $preferences->put($roleless->id, ExporterPreference::Enabled);
    $lifecycle = new NativeMetricsExporterLifecycle(
        executor: new AgentMetricsExporterRuntime(
            commands: app(MetricsExporterCommands::class),
        ),
        projection: new NativeMetricsExporterProjection(new ExporterSelector, $preferences),
    );

    expect($lifecycle->targets($metrics))->toBe([
        ['name' => 'app-dev', 'address' => '10.44.0.2'],
        ['name' => 'gateway', 'address' => '10.44.0.1'],
        ['name' => 'metrics', 'address' => '10.44.0.3'],
        ['name' => 'orbit-ops', 'address' => '10.44.0.5'],
    ]);
});

it('does not project a selected node without managed identity', function (): void {
    $metrics = metricsExporterLifecycleNode('metrics', '10.44.0.3');
    $metrics
        ->roles()
        ->create([
            'role' => RoleName::Metrics,
            'status' => LifecycleStatus::Active,
        ]);
    $invalid = metricsExporterLifecycleNode('invalid-target', '10.44.0.4');
    $invalid->update(['wireguard_ip' => null]);
    $invalid
        ->roles()
        ->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);
    $lifecycle = new NativeMetricsExporterLifecycle(
        executor: new AgentMetricsExporterRuntime(
            commands: app(MetricsExporterCommands::class),
        ),
        projection: new NativeMetricsExporterProjection(
            new ExporterSelector,
            app(ExporterPreferenceRepository::class),
        ),
    );

    expect($lifecycle->targets($metrics))->toBe([
        ['name' => 'metrics', 'address' => '10.44.0.3'],
    ]);
});

it('fails closed with a bounded error for an eligible exporter with an invalid address', function (): void {
    $sentinel = 'credential=metrics-secret';
    $metrics = metricsExporterLifecycleNode('metrics', '10.44.0.3');
    $metrics->roles()->create([
        'role' => RoleName::Metrics,
        'status' => LifecycleStatus::Active,
    ]);
    $invalid = metricsExporterLifecycleNode('invalid-target', $sentinel);
    $invalid->roles()->create([
        'role' => RoleName::AppDev,
        'status' => LifecycleStatus::Active,
    ]);
    $lifecycle = new NativeMetricsExporterLifecycle(
        executor: new AgentMetricsExporterRuntime(
            commands: app(MetricsExporterCommands::class),
        ),
        projection: new NativeMetricsExporterProjection(
            new ExporterSelector,
            app(ExporterPreferenceRepository::class),
        ),
    );

    try {
        $lifecycle->targets($metrics);
        $this->fail('Expected the invalid exporter address to be refused.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)
            ->toBe('metrics.exporter_address_invalid')
            ->and($exception->status)
            ->toBe(409)
            ->and($exception->getMessage())
            ->not->toContain($sentinel);
    }
});

function metricsExporterLifecycleNode(string $name, string $address): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => "192.0.2.{$name}",
        'ssh_user' => 'orbit',
        'wireguard_ip' => $address,
        'ssh_host_fingerprint' => 'SHA256:managed',
    ]);
}
