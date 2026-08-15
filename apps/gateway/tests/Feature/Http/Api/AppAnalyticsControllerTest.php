<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Models\App;
use App\Models\AppAnalyticsBinding;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\ProxyRoute;
use App\Services\Analytics\AnalyticsRouteRegistrar;
use App\Services\Analytics\AppAnalyticsBindingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    bind_dnsmasq_reconciler_test_double();
});

const APP_ANALYTICS_CALLER_WG_IP = '10.6.0.93';

function createAppAnalyticsCallerNode(array $overrides = [], ?string $role = null): Node
{
    $attributes = array_merge([
        'name' => 'analytics-caller',
        'host' => APP_ANALYTICS_CALLER_WG_IP,
        'wireguard_address' => APP_ANALYTICS_CALLER_WG_IP,
    ], $overrides);

    return match ($role) {
        'gateway' => Node::factory()->gateway()->create($attributes),
        default => Node::factory()->create($attributes),
    };
}

/**
 * @param  list<string>  $permissions
 */
function grantAppAnalyticsAccess(Node $caller, Node $appNode, array $permissions = ['instance:write']): void
{
    NodeAccess::query()->create([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'permissions' => $permissions,
        'custom_permissions' => [],
    ]);
}

function createAppAnalyticsRoutePrerequisites(bool $withRouter = true, bool $withAnalytics = true): void
{
    if ($withRouter) {
        Node::factory()
            ->router()
            ->create([
                'name' => 'router-1',
                'wireguard_address' => '10.6.0.2',
            ]);
    }

    if ($withAnalytics) {
        Node::factory()
            ->withActiveRole('analytics')
            ->create([
                'name' => 'analytics-1',
                'wireguard_address' => '10.6.0.50',
            ]);
    }

    if ($withRouter && $withAnalytics) {
        app(AnalyticsRouteRegistrar::class)->syncServiceRoute();
    }
}

function createAppAnalyticsApp(?string $domain = 'docs.test', bool $withIngress = true): App
{
    $ingress = $withIngress
        ? Node::factory()
            ->ingress()
            ->create([
                'name' => 'edge-1',
                'wireguard_address' => '10.6.0.10',
                'public_ipv4' => '203.0.113.10',
                'public_ipv6' => '2001:db8::10',
            ])
        : null;

    $appNode = Node::factory()
        ->appProd()
        ->create([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.21',
        ]);

    if ($ingress instanceof Node) {
        $appNode
            ->roleAssignments()
            ->where('role', 'app-prod')
            ->update(['settings' => ['ingress_node_id' => $ingress->id]]);
    }

    $app = App::factory()->create([
        'name' => 'docs',
        'node_id' => $appNode->id,
        'domain' => $domain,
    ]);

    Instance::factory()->for($app)->create([
        'name' => 'production',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $appNode->id,
            domain: $domain,
        ),
    ]);

    return $app;
}

function appAnalyticsInstance(App $app): Instance
{
    return $app->instances()->firstOrFail();
}

/**
 * @param  array<string, mixed>  $data
 */
function postAppAnalyticsEnableJson(string $uri, array $data): TestResponse
{
    return test()->call(
        'POST',
        $uri,
        $data,
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => APP_ANALYTICS_CALLER_WG_IP,
        ],
        json_encode($data, JSON_THROW_ON_ERROR),
    );
}

function postAppAnalyticsDisableJson(string $uri): TestResponse
{
    return test()->call(
        'POST',
        $uri,
        [],
        [],
        [],
        [
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => APP_ANALYTICS_CALLER_WG_IP,
        ],
    );
}

function getAppAnalyticsJson(string $uri): TestResponse
{
    return test()->call(
        'GET',
        $uri,
        [],
        [],
        [],
        [
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => APP_ANALYTICS_CALLER_WG_IP,
        ],
    );
}

describe('AppAnalyticsController', function (): void {
    it('enables app analytics bindings for authorized callers', function (): void {
        $caller = createAppAnalyticsCallerNode();
        createAppAnalyticsRoutePrerequisites();
        $app = createAppAnalyticsApp();
        grantAppAnalyticsAccess($caller, $app->node);

        $response = postAppAnalyticsEnableJson('/api/instances/docs/analytics/enable', [
            'public_hosts' => [],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success.data.binding.app', 'docs')
            ->assertJsonPath('success.data.binding.instance', 'production')
            ->assertJsonPath('success.data.binding.enabled', true)
            ->assertJsonPath('success.data.binding.site_domain', 'docs.test')
            ->assertJsonPath('success.data.binding.internal_host', 'analytics.orbit')
            ->assertJsonPath('success.data.binding.dashboard_url', 'https://analytics.orbit')
            ->assertJsonPath('success.data.binding.public_hosts', ['analytics.docs.test'])
            ->assertJsonPath('success.data.binding.tracking_paths', ['/js/*', '/api/event'])
            ->assertJsonPath('success.data.binding.tracking_endpoints.0.host', 'analytics.docs.test')
            ->assertJsonPath('success.data.binding.tracking_endpoints.0.script_base_url', 'https://analytics.docs.test')
            ->assertJsonPath(
                'success.data.binding.tracking_endpoints.0.script_url',
                'https://analytics.docs.test/js/script.js',
            )
            ->assertJsonPath(
                'success.data.binding.tracking_endpoints.0.event_endpoint',
                'https://analytics.docs.test/api/event',
            )
            ->assertJsonPath('success.data.binding.tracking_endpoints.0.data_domain', 'docs.test')
            ->assertJsonPath(
                'success.data.binding.tracking_endpoints.0.snippet',
                '<script defer data-domain="docs.test" src="https://analytics.docs.test/js/script.js"></script>',
            )
            ->assertJsonPath('success.data.route_enactment.status', 'completed')
            ->assertJsonPath('success.data.route_enactment.placements', ['router', 'ingress'])
            ->assertJsonPath('success.data.dns_expectation.ingress_node', 'edge-1')
            ->assertJsonPath('success.data.dns_expectation.targets', [
                ['type' => 'A', 'value' => '203.0.113.10'],
                ['type' => 'AAAA', 'value' => '2001:db8::10'],
            ])
            ->assertJsonPath('success.data.dns_expectation.provider_managed', false)
            ->assertJsonPath('success.data.public_readiness.status', 'not_verified')
            ->assertJsonPath('success.data.public_readiness.event', 'not_run')
            ->assertJsonPath('success.data.remaining_actions', [
                'configure_provider_dns',
                'ensure_plausible_site',
                'integrate_application_script',
                'verify_public_readiness',
            ]);

        expect(
            AppAnalyticsBinding::query()
                ->where('instance_id', appAnalyticsInstance($app)->id)
                ->where('enabled', true)
                ->exists(),
        )
            ->toBeTrue()
            ->and(ProxyRoute::query()->where('domain', 'analytics.orbit')->where('owner_type', 'router')->exists())
            ->toBeTrue()
            ->and(
                ProxyRoute::query()
                    ->where('domain', 'analytics.docs.test')
                    ->where('owner_type', 'app-analytics')
                    ->exists(),
            )
            ->toBeTrue();
    });

    it('rejects callers without app write permission before mutation', function (): void {
        $caller = createAppAnalyticsCallerNode();
        createAppAnalyticsRoutePrerequisites();
        $app = createAppAnalyticsApp();
        grantAppAnalyticsAccess($caller, $app->node, ['instance:read']);

        $response = postAppAnalyticsEnableJson('/api/instances/docs/analytics/enable', [
            'public_hosts' => ['analytics.docs.test'],
        ]);

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'instance:write');

        expect(AppAnalyticsBinding::query()->count())->toBe(0);
    });

    it('fails when no active analytics backend exists', function (): void {
        createAppAnalyticsCallerNode(role: 'gateway');
        createAppAnalyticsRoutePrerequisites(withAnalytics: false);
        createAppAnalyticsApp();

        $response = postAppAnalyticsEnableJson('/api/instances/docs/analytics/enable', [
            'public_hosts' => [],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'analytics.prerequisite_failed')
            ->assertJsonPath('error.meta.app', 'docs');

        expect(AppAnalyticsBinding::query()->count())->toBe(0);
    });

    it('rejects enablement before mutation when the app has no configured domain', function (): void {
        $caller = createAppAnalyticsCallerNode();
        createAppAnalyticsRoutePrerequisites();
        $app = createAppAnalyticsApp(domain: null);
        grantAppAnalyticsAccess($caller, $app->node);

        $response = postAppAnalyticsEnableJson('/api/instances/docs/analytics/enable', [
            'public_hosts' => ['analytics.docs.test'],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'analytics.domain_required')
            ->assertJsonPath('error.meta.app', 'docs');

        expect(AppAnalyticsBinding::query()->where('instance_id', appAnalyticsInstance($app)->id)->exists())
            ->toBeFalse()
            ->and(ProxyRoute::query()->where('owner_type', 'app-analytics')->exists())
            ->toBeFalse();
    });

    it('returns a repairable failure when public route enactment fails', function (): void {
        $caller = createAppAnalyticsCallerNode();
        $app = createAppAnalyticsApp();
        grantAppAnalyticsAccess($caller, $app->node);
        $registrar = new class extends AnalyticsRouteRegistrar {
            public function __construct() {}

            public function requireServiceRoute(): ProxyRoute
            {
                return new ProxyRoute(['domain' => self::ServiceDomain]);
            }

            public function assertPublicHostsAvailable(Instance $app, array $hosts): void {}

            public function removeObsoletePublicHosts(Instance $app, array $desiredHosts): void {}

            public function syncPublicHosts(AppAnalyticsBinding $binding): void {}

            public function convergePublicHosts(AppAnalyticsBinding $binding): void
            {
                throw new RuntimeException('Ingress Caddy reload failed.');
            }
        };
        app()->instance(AnalyticsRouteRegistrar::class, $registrar);

        $response = postAppAnalyticsEnableJson('/api/instances/docs/analytics/enable', [
            'public_hosts' => [],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'analytics.route_enactment_failed')
            ->assertJsonPath('error.meta.app', 'docs');

        expect(
            AppAnalyticsBinding::query()
                ->where('instance_id', appAnalyticsInstance($app)->id)
                ->where('enabled', true)
                ->exists(),
        )
            ->toBeTrue();
    });

    it('keeps the binding enabled when route cleanup fails during disable', function (): void {
        $caller = createAppAnalyticsCallerNode();
        $app = createAppAnalyticsApp();
        grantAppAnalyticsAccess($caller, $app->node);
        AppAnalyticsBinding::query()->create([
            'instance_id' => appAnalyticsInstance($app)->id,
            'enabled' => true,
            'public_hosts' => ['analytics.docs.test'],
        ]);
        $registrar = new class extends AnalyticsRouteRegistrar {
            public function __construct() {}

            public function removeObsoletePublicHosts(Instance $app, array $desiredHosts): void
            {
                throw new RuntimeException('Ingress Caddy cleanup failed.');
            }
        };
        app()->instance(AnalyticsRouteRegistrar::class, $registrar);

        $response = postAppAnalyticsDisableJson('/api/instances/docs/analytics/disable');

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'analytics.route_cleanup_failed')
            ->assertJsonPath('error.meta.app', 'docs');

        expect(
            AppAnalyticsBinding::query()
                ->where('instance_id', appAnalyticsInstance($app)->id)
                ->where('enabled', true)
                ->exists(),
        )
            ->toBeTrue();
    });

    it('disables app analytics bindings for authorized callers', function (): void {
        $caller = createAppAnalyticsCallerNode();
        createAppAnalyticsRoutePrerequisites();
        $app = createAppAnalyticsApp();
        grantAppAnalyticsAccess($caller, $app->node);

        app(AppAnalyticsBindingService::class)->enable(appAnalyticsInstance($app), ['analytics.docs.test']);

        $response = postAppAnalyticsDisableJson('/api/instances/docs/analytics/disable');

        $response
            ->assertOk()
            ->assertJsonPath('success.data.binding.app', 'docs')
            ->assertJsonPath('success.data.binding.enabled', false)
            ->assertJsonPath('success.data.binding.public_hosts', []);

        expect(
            AppAnalyticsBinding::query()
                ->where('instance_id', appAnalyticsInstance($app)->id)
                ->where('enabled', false)
                ->exists(),
        )
            ->toBeTrue()
            ->and(
                ProxyRoute::query()
                    ->where('domain', 'analytics.docs.test')
                    ->where('owner_type', 'app-analytics')
                    ->exists(),
            )
            ->toBeFalse();
    });

    it('shows app analytics bindings for authorized callers', function (): void {
        $caller = createAppAnalyticsCallerNode();
        createAppAnalyticsRoutePrerequisites();
        $app = createAppAnalyticsApp();
        grantAppAnalyticsAccess($caller, $app->node, ['instance:read']);

        app(AppAnalyticsBindingService::class)->enable(appAnalyticsInstance($app), ['analytics.docs.test']);

        $response = getAppAnalyticsJson('/api/instances/docs/analytics');

        $response
            ->assertOk()
            ->assertJsonPath('success.data.binding.app', 'docs')
            ->assertJsonPath('success.data.binding.enabled', true)
            ->assertJsonPath('success.data.binding.public_hosts', ['analytics.docs.test']);
    });

    it('returns binding missing when show is requested before enable', function (): void {
        $caller = createAppAnalyticsCallerNode();
        $app = createAppAnalyticsApp();
        grantAppAnalyticsAccess($caller, $app->node, ['instance:read']);

        $response = getAppAnalyticsJson('/api/instances/docs/analytics');

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'analytics.binding_missing')
            ->assertJsonPath('error.meta.app', 'docs');
    });

    it('returns read-only public verification context for authorized callers', function (): void {
        $caller = createAppAnalyticsCallerNode();
        createAppAnalyticsRoutePrerequisites();
        $app = createAppAnalyticsApp();
        grantAppAnalyticsAccess($caller, $app->node, ['instance:read', 'instance:write']);

        app(AppAnalyticsBindingService::class)->enable(appAnalyticsInstance($app), ['analytics.docs.test']);

        $bindingUpdatedAt = AppAnalyticsBinding::query()
            ->where('instance_id', appAnalyticsInstance($app)->id)
            ->value('updated_at');
        $routeUpdatedAt = ProxyRoute::query()->where('domain', 'analytics.docs.test')->value('updated_at');

        $response = getAppAnalyticsJson('/api/instances/docs/analytics/verify');

        $response
            ->assertOk()
            ->assertJsonPath('success.data.verification_context.binding.app', 'docs')
            ->assertJsonPath('success.data.verification_context.binding.enabled', true)
            ->assertJsonPath('success.data.verification_context.routes.0.host', 'analytics.docs.test')
            ->assertJsonPath('success.data.verification_context.routes.0.status', 'registered')
            ->assertJsonPath('success.data.verification_context.dns_expectation.ingress_node', 'edge-1')
            ->assertJsonPath('success.data.verification_context.dns_expectation.targets', [
                ['type' => 'A', 'value' => '203.0.113.10'],
                ['type' => 'AAAA', 'value' => '2001:db8::10'],
            ]);

        expect(AppAnalyticsBinding::query()->where('instance_id', appAnalyticsInstance($app)->id)->value('updated_at'))
            ->toEqual($bindingUpdatedAt)
            ->and(ProxyRoute::query()->where('domain', 'analytics.docs.test')->value('updated_at'))
            ->toEqual($routeUpdatedAt);
    });

    it('reports divergent stored route intent without repairing it', function (): void {
        $caller = createAppAnalyticsCallerNode();
        createAppAnalyticsRoutePrerequisites();
        $app = createAppAnalyticsApp();
        grantAppAnalyticsAccess($caller, $app->node, ['instance:read', 'instance:write']);

        app(AppAnalyticsBindingService::class)->enable(appAnalyticsInstance($app), ['analytics.docs.test']);
        ProxyRoute::query()->where('domain', 'analytics.docs.test')->update(['source_hash' => 'divergent']);

        $response = getAppAnalyticsJson('/api/instances/docs/analytics/verify');

        $response
            ->assertOk()
            ->assertJsonPath('success.data.verification_context.routes.0.status', 'divergent');

        expect(ProxyRoute::query()->where('domain', 'analytics.docs.test')->value('source_hash'))
            ->toBe('divergent');
    });
});
