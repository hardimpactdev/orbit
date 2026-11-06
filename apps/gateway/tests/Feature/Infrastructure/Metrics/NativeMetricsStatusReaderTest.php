<?php

declare(strict_types=1);

use App\Data\Metrics\MetricsCredentialsData;
use App\Domain\Metrics\MetricsCredentialManager;
use App\Domain\Metrics\MetricsStatusReader;
use App\Domain\Nodes\RoleName;
use App\Models\Node;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

function metrics_status_node(string $name, string $address, RoleName $role): Node
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => $address,
        'public_ssh_host' => $address, 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:'.str_repeat('a', 43),
    ]));
    $node->roles()->create(['role' => $role, 'status' => 'active']);

    return $node;
}

function metrics_status_credentials(): void
{
    test()->mock(MetricsCredentialManager::class, fn ($mock) => $mock->shouldReceive('storedCredentials')
        ->andReturn(new MetricsCredentialsData('https://metrics.orbit', 'admin', 'secret')));
}

/** @param list<array{string, string}> $targets Each entry is the instance label and the up value. */
function metrics_status_fake(array $targets): void
{
    metrics_status_credentials();
    Http::fake([
        '*/api/datasources' => Http::response([['type' => 'prometheus', 'uid' => 'prom']]),
        '*/api/datasources/proxy/uid/prom/api/v1/query*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => array_map(
            static fn (array $target): array => ['metric' => ['instance' => $target[0]], 'value' => [1, $target[1]]],
            $targets,
        )]]),
    ]);
}

/** @return array<string, string> The actual state of each exporter, by Node name. */
function metrics_status_actuals(): array
{
    return collect(app(MetricsStatusReader::class)->status()->toArray()['exporters'])->pluck('actual', 'name')->all();
}

it('reports an exporter active only when both of its targets are up', function (): void {
    metrics_status_node('metrics', '10.44.0.3', RoleName::Metrics);
    metrics_status_node('web', '10.44.0.4', RoleName::AppProd);
    metrics_status_node('db', '10.44.0.5', RoleName::AppProd);
    metrics_status_fake([
        ['10.44.0.3:9100', '1'], ['10.44.0.3:9102', '1'],
        ['10.44.0.4:9100', '1'], ['10.44.0.4:9102', '0'],
    ]);

    $status = app(MetricsStatusReader::class)->status()->toArray();

    expect($status['prometheus'])->toBe('healthy')
        ->and($status['grafana'])->toBe('healthy')
        ->and(metrics_status_actuals())->toBe(['db' => 'inactive', 'metrics' => 'active', 'web' => 'inactive'])
        ->and($status['reconcile_status'])->toBe('degraded');
});

it('reports unhealthy services and unknown exporters when Prometheus does not answer', function (): void {
    metrics_status_node('metrics', '10.44.0.3', RoleName::Metrics);
    metrics_status_credentials();
    Http::fake(['*' => Http::response('', 502)]);

    $status = app(MetricsStatusReader::class)->status()->toArray();

    expect($status['prometheus'])->toBe('unhealthy')
        ->and($status['grafana'])->toBe('unhealthy')
        ->and(metrics_status_actuals())->toBe(['metrics' => 'unknown']);
});

it('reports a disabled Metrics role without querying Prometheus', function (): void {
    Http::fake();

    $status = app(MetricsStatusReader::class)->status()->toArray();

    expect($status['enabled'])->toBeFalse();
    Http::assertNothingSent();
});
