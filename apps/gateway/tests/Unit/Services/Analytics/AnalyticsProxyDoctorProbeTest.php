<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Data\Doctor\DriftEntry;
use App\Enums\DriftKind;
use App\Models\App;
use App\Models\AppAnalyticsBinding;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\ProxyRoute;
use App\Services\Analytics\AnalyticsProxyDoctorProbe;
use App\Services\Analytics\AnalyticsPublicProxyDoctorProbe;
use App\Services\Analytics\AnalyticsRouteRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    bind_dnsmasq_reconciler_test_double();
});

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

it('reports a missing public analytics route for an enabled app binding', function (): void {
    analyticsProxyRouter();
    analyticsProxyBackend();
    [$ingress, $app, $binding] = analyticsPublicBinding();
    app(AnalyticsRouteRegistrar::class)->syncPublicHosts($binding);
    ProxyRoute::query()->where('domain', 'analytics.docs.test')->delete();

    $drift = app(AnalyticsPublicProxyDoctorProbe::class)->drift($ingress, 'docs');

    expect($drift)
        ->toHaveCount(1)
        ->and($drift[0]->key)
        ->toBe(AnalyticsPublicProxyDoctorProbe::PUBLIC_ROUTE_KEY)
        ->and($drift[0]->kind)
        ->toBe(DriftKind::Missing)
        ->and($drift[0]->detail['binding_id'] ?? null)
        ->toBe($binding->id)
        ->and($drift[0]->detail['domain'] ?? null)
        ->toBe('analytics.docs.test');
});

it('restores public analytics route intent from the enabled app binding', function (): void {
    analyticsProxyRouter();
    analyticsProxyBackend();
    [$ingress, $app, $binding] = analyticsPublicBinding();
    $entry = new DriftEntry(
        family: 'proxy',
        key: AnalyticsPublicProxyDoctorProbe::PUBLIC_ROUTE_KEY,
        kind: DriftKind::Missing,
        summary: 'Missing public analytics route.',
        detail: [
            'binding_id' => $binding->id,
            'domain' => 'analytics.docs.test',
        ],
    );

    $result = app(AnalyticsPublicProxyDoctorProbe::class)->restore($ingress, $entry);

    expect($result['status'] ?? null)
        ->toBe('completed')
        ->and(ProxyRoute::query()->where('domain', 'analytics.docs.test')->exists())
        ->toBeTrue();
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

/**
 * @return array{Node, App, AppAnalyticsBinding}
 */
function analyticsPublicBinding(): array
{
    $ingress = Node::factory()
        ->ingress()
        ->create([
            'name' => 'edge-1',
            'wireguard_address' => '10.6.0.10',
        ]);
    $appNode = Node::factory()
        ->appProd()
        ->create([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.21',
        ]);
    $appNode
        ->roleAssignments()
        ->where('role', 'app-prod')
        ->update(['settings' => ['ingress_node_id' => $ingress->id]]);
    $app = App::factory()->create([
        'name' => 'docs',
    ]);
    $instance = Instance::factory()->for($app)->create([
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $appNode->id,
            domain: 'docs.test',
        ),
    ]);
    $binding = AppAnalyticsBinding::query()->create([
        'instance_id' => $instance->id,
        'enabled' => true,
        'public_hosts' => ['analytics.docs.test'],
    ]);

    return [$ingress, $app, $binding];
}
