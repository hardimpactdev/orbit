<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Data\Doctor\DriftEntry;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\DriftKind;
use App\Enums\Nodes\NodeRoleStatus;
use App\Models\App;
use App\Models\AppAnalyticsBinding;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Services\Analytics\AnalyticsProxyDoctorProbe;
use App\Services\Analytics\AnalyticsPublicProxyDoctorProbe;
use App\Services\Analytics\AnalyticsRouteRegistrar;
use App\Services\Gateway\CaddyGlobalConfig;
use App\Services\Proxy\PublicBindingProxyRouteOwnership;
use App\Services\Proxy\RemoteCaddyConfig;
use App\Services\RemoteShell\RunsInternalCommands;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    bind_dnsmasq_reconciler_test_double();
});

final readonly class AnalyticsRouteRegistrarTestInternalExecutor implements RunsInternalCommands
{
    public function runInternal(
        Node $node,
        string $commandName,
        array $arguments = [],
        array $commandOptions = [],
        array $transportOptions = [],
    ): RemoteShellResult {
        $action = is_string($arguments[0] ?? null) ? $arguments[0] : '';
        $data = $action === 'read-global'
            ? ['content' => new CaddyGlobalConfig()->fresh()]
            : [];

        return new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode(['success' => ['data' => $data, 'meta' => []]], JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: 1,
        );
    }
}

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

it('allows an active analytics node during its pending role transition', function (): void {
    analyticsProxyRouter();
    $backend = Node::factory()->create([
        'name' => 'services1',
        'wireguard_address' => '10.6.0.14',
        'status' => 'active',
    ]);
    NodeRoleAssignment::factory()->for($backend)->create([
        'role' => 'analytics',
        'status' => NodeRoleStatus::Pending,
    ]);

    $route = app(AnalyticsRouteRegistrar::class)->syncServiceRoute($backend);

    expect($route->config['router_backend_pool'])->toBe([[
        'node_id' => $backend->id,
        'node' => 'services1',
        'url' => 'http://10.6.0.14:8000',
    ]]);
});

it('rejects an inactive analytics backend during its pending role transition', function (): void {
    analyticsProxyRouter();
    $backend = Node::factory()->create([
        'name' => 'services1',
        'wireguard_address' => '10.6.0.14',
        'status' => 'inactive',
    ]);
    NodeRoleAssignment::factory()->for($backend)->create([
        'role' => 'analytics',
        'status' => NodeRoleStatus::Pending,
    ]);

    app(AnalyticsRouteRegistrar::class)->syncServiceRoute($backend);
})->throws(RuntimeException::class, 'The analytics service route requires at least one active analytics backend.');

it('does not overwrite another owner at the reserved analytics service domain', function (): void {
    $router = analyticsProxyRouter();
    analyticsProxyBackend();
    $route = ProxyRoute::factory()->create([
        'domain' => AnalyticsRouteRegistrar::ServiceDomain,
        'node_id' => $router->id,
        'owner_type' => 'custom',
        'kind' => 'proxy',
        'config' => ['target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:8000']],
    ]);

    expect(fn () => app(AnalyticsRouteRegistrar::class)->syncServiceRoute())
        ->toThrow(
            RuntimeException::class,
            "Analytics service route 'analytics.orbit' conflicts with existing ownership.",
        );

    expect($route->fresh()?->owner_type)
        ->toBe('custom')
        ->and($route->fresh()?->config)
        ->toBe(['target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:8000']]);
});

it('does not restore active analytics drift by converting another owner', function (): void {
    $router = analyticsProxyRouter();
    analyticsProxyBackend();
    $route = ProxyRoute::factory()->create([
        'domain' => AnalyticsRouteRegistrar::ServiceDomain,
        'node_id' => $router->id,
        'owner_type' => 'custom',
        'kind' => 'proxy',
        'config' => ['target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:8000']],
    ]);
    $probe = app(AnalyticsProxyDoctorProbe::class);
    $drift = $probe->drift($router);

    expect($drift)
        ->toHaveCount(1)
        ->and($drift[0]->kind)
        ->toBe(DriftKind::Unverifiable)
        ->and($drift[0]->detail['reason'] ?? null)
        ->toBe('ownership_conflict')
        ->and($probe->restore($router, $drift[0]))
        ->toBeNull()
        ->and($route->fresh()?->owner_type)
        ->toBe('custom');
});

it('does not overwrite malformed analytics service ownership', function (array $attributes): void {
    $router = analyticsProxyRouter();
    analyticsProxyBackend();
    $route = persist_proxy_route_bypassing_owner_guard([
        'node_id' => $router->id,
        'domain' => AnalyticsRouteRegistrar::ServiceDomain,
        'app_id' => null,
        'workspace_id' => null,
        'instance_id' => null,
        'owner_type' => 'router',
        'kind' => 'proxy',
        'config' => ['protocol' => 'analytics'],
        'source_hash' => str_repeat('a', 64),
        ...$attributes,
    ]);
    $original = $route->fresh()->getAttributes();

    expect(fn () => app(AnalyticsRouteRegistrar::class)->syncServiceRoute())
        ->toThrow(
            RuntimeException::class,
            "Analytics service route 'analytics.orbit' conflicts with existing ownership.",
        );

    expect($route->fresh()?->getAttributes())->toBe($original);
})->with([
    'incomplete stable config' => [[]],
    'wrong kind' => [['kind' => 'redirect']],
    'wrong protocol' => [['config' => ['protocol' => 'websocket']]],
    'wrong node identity' => [fn (): array => ['node_id' => Node::factory()->create()->id]],
    'stray app identity' => [fn (): array => ['app_id' => App::factory()->create()->id]],
    'stray workspace identity' => [fn (): array => ['workspace_id' => Workspace::factory()->create()->id]],
    'stray instance identity' => [fn (): array => ['instance_id' => Instance::factory()->create()->id]],
]);

it('does not remove incomplete analytics service ownership', function (): void {
    $router = analyticsProxyRouter();
    $route = ProxyRoute::query()->create([
        'node_id' => $router->id,
        'domain' => AnalyticsRouteRegistrar::ServiceDomain,
        'app_id' => null,
        'workspace_id' => null,
        'instance_id' => null,
        'owner_type' => 'router',
        'kind' => 'proxy',
        'config' => ['protocol' => 'analytics'],
        'source_hash' => str_repeat('a', 64),
    ]);

    app(AnalyticsRouteRegistrar::class)->removeServiceRoute();

    expect($route->fresh())->not->toBeNull();
});

it('reports and removes an orphaned private analytics route', function (): void {
    $router = analyticsProxyRouter();
    $backend = analyticsProxyBackend();
    app(AnalyticsRouteRegistrar::class)->syncServiceRoute($backend);
    $backend->roleAssignments()->where('role', 'analytics')->update(['status' => 'removing']);

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

it('does not classify or remove another owner at the reserved analytics service domain', function (): void {
    $router = analyticsProxyRouter();
    $route = ProxyRoute::factory()->create([
        'domain' => AnalyticsRouteRegistrar::ServiceDomain,
        'node_id' => $router->id,
        'owner_type' => 'custom',
        'kind' => 'proxy',
        'config' => ['target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:8000']],
    ]);
    $probe = app(AnalyticsProxyDoctorProbe::class);
    $entry = new DriftEntry(
        family: 'proxy',
        key: AnalyticsProxyDoctorProbe::RouterRouteOrphanedKey,
        kind: DriftKind::Extra,
        summary: 'Orphaned analytics route.',
    );

    $drift = $probe->drift($router);
    $result = $probe->restore($router, $entry);

    expect(collect($drift)->pluck('key')->all())
        ->not
        ->toContain(AnalyticsProxyDoctorProbe::RouterRouteOrphanedKey)
        ->and($result)
        ->toBeNull()
        ->and($route->fresh()?->owner_type)
        ->toBe('custom');
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

it('does not restore public analytics drift by converting another owner', function (): void {
    analyticsProxyRouter();
    analyticsProxyBackend();
    [$ingress, $app, $binding] = analyticsPublicBinding();
    $route = ProxyRoute::factory()->create([
        'node_id' => $ingress->id,
        'domain' => 'analytics.docs.test',
        'owner_type' => 'custom',
        'kind' => 'proxy',
        'config' => ['target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:8000']],
    ]);
    $probe = app(AnalyticsPublicProxyDoctorProbe::class);
    $drift = $probe->drift($ingress, $app->name);

    expect($drift)
        ->toHaveCount(1)
        ->and($drift[0]->kind)
        ->toBe(DriftKind::Unverifiable)
        ->and($drift[0]->detail['reason'] ?? null)
        ->toBe('ownership_conflict')
        ->and($probe->restore($ingress, $drift[0]))
        ->toBeNull()
        ->and($route->fresh()?->owner_type)
        ->toBe('custom');
});

it('treats a stable public analytics config mismatch as an ownership conflict', function (): void {
    analyticsProxyRouter();
    analyticsProxyBackend();
    [$ingress, $app, $binding] = analyticsPublicBinding();
    app(AnalyticsRouteRegistrar::class)->syncPublicHosts($binding);
    $route = ProxyRoute::query()->where('domain', 'analytics.docs.test')->firstOrFail();
    $config = $route->config;
    $config['target']['value'] = 'https://custom.example.test';
    $route->forceFill(['config' => $config])->save();
    $original = $route->fresh()->getAttributes();
    $probe = app(AnalyticsPublicProxyDoctorProbe::class);
    $drift = $probe->drift($ingress, $app->name);
    $staleEntry = new DriftEntry(
        family: 'proxy',
        key: AnalyticsPublicProxyDoctorProbe::PUBLIC_ROUTE_KEY,
        kind: DriftKind::Divergent,
        summary: 'Stale public analytics repair entry.',
        detail: [
            'binding_id' => $binding->id,
            'domain' => 'analytics.docs.test',
        ],
    );

    expect($drift)
        ->toHaveCount(1)
        ->and($drift[0]->kind)
        ->toBe(DriftKind::Unverifiable)
        ->and($drift[0]->detail['reason'] ?? null)
        ->toBe('ownership_conflict')
        ->and($probe->restore($ingress, $drift[0]))
        ->toBeNull()
        ->and($probe->restore($ingress, $staleEntry))
        ->toBeNull()
        ->and(fn (): mixed => app(AnalyticsRouteRegistrar::class)->syncPublicHosts($binding))
        ->toThrow(
            RuntimeException::class,
            "Analytics public host 'analytics.docs.test' conflicts with an existing proxy route.",
        )
        ->and($route->fresh()->getAttributes())
        ->toBe($original);
});

it('does not validate public analytics ownership through an inactive backend node', function (): void {
    analyticsProxyRouter();
    $backend = analyticsProxyBackend();
    [, , $binding] = analyticsPublicBinding();
    app(AnalyticsRouteRegistrar::class)->syncPublicHosts($binding);
    $route = ProxyRoute::query()->where('domain', 'analytics.docs.test')->firstOrFail();
    $backend->forceFill(['status' => 'inactive'])->save();

    expect(app(PublicBindingProxyRouteOwnership::class)->matches($route->refresh()))->toBeFalse();
});

it('rejects malformed or differently-owned routes at a public analytics host', function (string $invalidity): void {
    analyticsProxyRouter();
    analyticsProxyBackend();
    [$ingress, $app, $binding] = analyticsPublicBinding();
    $instance = $binding->instance()->firstOrFail();
    $route = ProxyRoute::factory()->create([
        'node_id' => $ingress->id,
        'app_id' => $app->id,
        'instance_id' => $instance->id,
        'workspace_id' => null,
        'domain' => 'analytics.docs.test',
        'owner_type' => 'app-analytics',
        'kind' => 'proxy',
    ]);

    if ($invalidity === 'missing app') {
        $route->forceFill(['app_id' => null])->saveQuietly();
    }

    if ($invalidity === 'missing instance') {
        $route->forceFill(['instance_id' => null])->saveQuietly();
    }

    if ($invalidity === 'conflicting app') {
        $route->forceFill(['app_id' => App::factory()->create()->id])->saveQuietly();
    }

    if ($invalidity === 'wrong kind') {
        $route->forceFill(['kind' => 'app'])->saveQuietly();
    }

    if ($invalidity === 'workspace identity') {
        $workspace = Workspace::factory()->for($app)->create(['instance_id' => $instance->id]);
        $route->forceFill(['workspace_id' => $workspace->id])->saveQuietly();
    }

    if ($invalidity === 'different valid owner') {
        $route->forceFill([
            'instance_id' => Instance::factory()->for($app)->create(['name' => 'preview'])->id,
        ])->save();
    }

    if ($invalidity === 'wrong node') {
        $route->forceFill(['node_id' => Node::factory()->create()->id])->save();
    }

    if ($invalidity === 'wrong protocol') {
        $route->forceFill(['config' => ['protocol' => 'websocket']])->save();
    }

    $original = $route->fresh()->getAttributes();

    expect(fn (): mixed => app(AnalyticsRouteRegistrar::class)->assertPublicHostsAvailable(
        $instance,
        ['analytics.docs.test'],
    ))
        ->toThrow(
            RuntimeException::class,
            "Analytics public host 'analytics.docs.test' conflicts with an existing proxy route.",
        )
        ->and(fn (): mixed => app(AnalyticsRouteRegistrar::class)->syncPublicHosts($binding))
        ->toThrow(
            RuntimeException::class,
            "Analytics public host 'analytics.docs.test' conflicts with an existing proxy route.",
        )
        ->and($route->fresh()->getAttributes())
        ->toBe($original);
})->with([
    'missing app',
    'missing instance',
    'conflicting app',
    'wrong kind',
    'workspace identity',
    'different valid owner',
    'wrong node',
    'wrong protocol',
]);

it('removes only valid obsolete public analytics ownership', function (): void {
    $router = analyticsProxyRouter();
    analyticsProxyBackend();
    [$ingress, $app, $binding] = analyticsPublicBinding();
    $instance = $binding->instance()->firstOrFail();
    $binding->forceFill(['public_hosts' => ['valid-analytics.docs.test']])->save();
    app(AnalyticsRouteRegistrar::class)->syncPublicHosts($binding);
    $validRoute = ProxyRoute::query()->where('domain', 'valid-analytics.docs.test')->firstOrFail();
    $malformedRoute = ProxyRoute::factory()->create([
        'node_id' => $ingress->id,
        'app_id' => $app->id,
        'instance_id' => $instance->id,
        'domain' => 'malformed-analytics.docs.test',
        'owner_type' => 'app-analytics',
        'kind' => 'proxy',
        'config' => $validRoute->config,
    ]);
    $malformedRoute->forceFill([
        'app_id' => App::factory()->create(['name' => 'compatibility'])->id,
    ])->saveQuietly();
    $strayForeignKeyRoute = persist_made_proxy_route_bypassing_owner_guard(ProxyRoute::factory()->make([
        'node_id' => $ingress->id,
        'app_id' => null,
        'instance_id' => $instance->id,
        'domain' => 'custom-analytics.docs.test',
        'owner_type' => 'custom',
        'kind' => 'proxy',
    ]));
    $executor = new AnalyticsRouteRegistrarTestInternalExecutor;
    app()->instance(RunsInternalCommands::class, $executor);
    app()->instance(RemoteCaddyConfig::class, new RemoteCaddyConfig($executor));

    app(AnalyticsRouteRegistrar::class)->removeObsoletePublicHosts($instance, []);

    expect(ProxyRoute::query()->whereKey($validRoute->id)->exists())
        ->toBeFalse()
        ->and(ProxyRoute::query()->whereKey($malformedRoute->id)->exists())
        ->toBeTrue()
        ->and(ProxyRoute::query()->whereKey($strayForeignKeyRoute->id)->exists())
        ->toBeTrue();
});

it('flags an analytics assignment that still lacks postgres_process_id', function (): void {
    $backend = analyticsProxyBackend();

    $drift = app(AnalyticsProxyDoctorProbe::class)->drift($backend);

    expect($drift)
        ->toHaveCount(1)
        ->and($drift[0]->key)
        ->toBe(AnalyticsProxyDoctorProbe::POSTGRES_PROCESS_ID_MISSING_KEY)
        ->and($drift[0]->kind)
        ->toBe(DriftKind::Missing)
        ->and($drift[0]->family)
        ->toBe('proxy');
});

it('does not flag an analytics assignment that stores postgres_process_id', function (): void {
    $backend = analyticsProxyBackend();
    $assignment = NodeRoleAssignment::query()
        ->where('node_id', $backend->id)
        ->where('role', 'analytics')
        ->firstOrFail();
    $assignment->forceFill([
        'settings' => [
            'postgres_node_id' => 1,
            'postgres_process_id' => 12,
            'clickhouse_node_id' => 1,
        ],
    ])->save();

    $drift = app(AnalyticsProxyDoctorProbe::class)->drift($backend);

    expect($drift)->toBeEmpty();
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
