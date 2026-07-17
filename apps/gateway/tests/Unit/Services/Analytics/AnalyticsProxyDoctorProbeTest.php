<?php

declare(strict_types=1);

use App\Data\Doctor\DriftEntry;
use App\Enums\DriftKind;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\ProxyRoute;
use App\Services\Analytics\AnalyticsProxyDoctorProbe;
use App\Services\Analytics\AnalyticsRouteRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('reports a missing private analytics route for the router', function (): void {
    $router = analyticsProxyRouter();
    analyticsProxyBackend();

    $drift = app(AnalyticsProxyDoctorProbe::class)->drift($router);

    expect($drift)
        ->toHaveCount(1)
        ->and($drift[0]->key)
        ->toBe(AnalyticsProxyDoctorProbe::RouterRouteKey)
        ->and($drift[0]->kind)
        ->toBe(DriftKind::Missing);
});

it('reports divergent private analytics route intent', function (): void {
    $router = analyticsProxyRouter();
    analyticsProxyBackend();
    app(AnalyticsRouteRegistrar::class)->syncServiceRoute();
    ProxyRoute::query()
        ->where('domain', AnalyticsRouteRegistrar::ServiceDomain)
        ->update(['source_hash' => 'divergent']);

    $drift = app(AnalyticsProxyDoctorProbe::class)->drift($router);

    expect($drift)
        ->toHaveCount(1)
        ->and($drift[0]->key)
        ->toBe(AnalyticsProxyDoctorProbe::RouterRouteKey)
        ->and($drift[0]->kind)
        ->toBe(DriftKind::Divergent);
});

it('reports and removes an orphaned private analytics route', function (): void {
    $router = analyticsProxyRouter();
    ProxyRoute::factory()->create([
        'domain' => AnalyticsRouteRegistrar::ServiceDomain,
        'node_id' => $router->id,
        'owner_type' => 'router',
        'kind' => 'proxy',
        'config' => ['protocol' => 'analytics'],
    ]);

    $registrar = new class extends AnalyticsRouteRegistrar {
        public bool $removed = false;

        public function __construct() {}

        public function removeServiceRoute(): void
        {
            $this->removed = true;
            ProxyRoute::query()->where('domain', self::ServiceDomain)->delete();
        }
    };
    app()->instance(AnalyticsRouteRegistrar::class, $registrar);

    $probe = app(AnalyticsProxyDoctorProbe::class);
    $drift = $probe->drift($router);
    $result = $probe->restore($router, new DriftEntry(
        family: 'proxy',
        key: AnalyticsProxyDoctorProbe::RouterRouteOrphanedKey,
        kind: DriftKind::Extra,
        summary: 'Orphaned analytics route.',
    ));

    expect($drift)
        ->toHaveCount(1)
        ->and($drift[0]->key)
        ->toBe(AnalyticsProxyDoctorProbe::RouterRouteOrphanedKey)
        ->and($registrar->removed)
        ->toBeTrue()
        ->and($result['status'] ?? null)
        ->toBe('completed')
        ->and(ProxyRoute::query()->where('domain', AnalyticsRouteRegistrar::ServiceDomain)->exists())
        ->toBeFalse();
});

function analyticsProxyRouter(): Node
{
    $router = Node::factory()->create([
        'name' => 'router-1',
        'wireguard_address' => '10.6.0.2',
        'status' => 'active',
    ]);
    NodeRoleAssignment::factory()->for($router)->create([
        'role' => 'router',
        'status' => 'active',
    ]);

    return $router;
}

function analyticsProxyBackend(): Node
{
    $backend = Node::factory()->create([
        'name' => 'services1',
        'wireguard_address' => '10.6.0.14',
        'status' => 'active',
    ]);
    NodeRoleAssignment::factory()->for($backend)->create([
        'role' => 'analytics',
        'status' => 'active',
    ]);

    return $backend;
}
