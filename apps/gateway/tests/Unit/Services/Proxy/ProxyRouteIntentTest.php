<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Services\Ca\OrbitCaService;
use App\Services\Proxy\ProxyRouteEnactment;
use App\Services\Proxy\ProxyRouteFixer;
use App\Services\Proxy\ProxyRouteIntent;
use App\Services\Proxy\ProxyRouteRenderer;
use App\Services\Proxy\RemoteCaddyConfig;
use App\Services\RemoteShell\RunsInternalCommands;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Orbit\Sdk\Laravel\GatewayApiException;
use Tests\Fakes\SiteCertificateInstallerFake;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function grantProxyRouteIntentAccess(Node $caller, Node $servingNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $servingNode->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function invalidate_proxy_route_intent_ownership(
    ProxyRoute $route,
    App $app,
    Instance $instance,
    string $validKind,
    string $invalidity,
): void {
    if ($invalidity === 'missing app') {
        $route->forceFill(['app_id' => null])->save();
    }

    if ($invalidity === 'missing instance') {
        $route->forceFill(['instance_id' => null])->save();
    }

    if ($invalidity === 'conflicting app') {
        $route->forceFill(['app_id' => App::factory()->create()->id])->save();
    }

    if ($invalidity === 'wrong kind') {
        $route->forceFill(['kind' => $validKind === 'app' ? 'proxy' : 'app'])->save();
    }

    if ($invalidity === 'workspace identity') {
        $workspace = Workspace::factory()->for($app)->create(['instance_id' => $instance->id]);
        $route->forceFill(['workspace_id' => $workspace->id])->save();
    }
}

describe('ProxyRouteIntent', function (): void {
    beforeEach(function (): void {
        // Enactment uses ProxyRouteFixer; bind shell + TLS fakes like fixer unit tests.
        new ProxyIntentRecordingRemoteShell;
        app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);
        app()->instance(ProxyRouteFixer::class, new ProxyRouteFixer(
            renderer: new ProxyRouteRenderer,
            ca: new ProxyIntentFakeCa,
            siteCertificateInstaller: new SiteCertificateInstallerFake,
        ));
    });

    it('creates custom upstream route and enacts backend/TLS in one step', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1']);

        $result = app(ProxyRouteIntent::class)->add(
            domain: 'vite.docs.test',
            nodeName: 'app-1',
            upstream: 'http://127.0.0.1:5173',
            redirect: null,
            code: null,
            force: false,
        );

        expect($result['data']['route'])
            ->toMatchArray([
                'domain' => 'vite.docs.test',
                'kind' => 'proxy',
                'owner' => ['type' => 'custom', 'name' => null],
                'node' => 'app-1',
                'target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:5173'],
                'status' => 'converged',
            ])
            ->and($result['meta']['action'])
            ->toBe('created')
            ->and(collect($result['meta']['warnings'])->pluck('code')->all())
            ->not
            ->toContain('proxy.enactment_deferred')
            ->and(collect($result['meta']['warnings'])->firstWhere('code', 'firewall_rule.host_upstream_may_block'))
            ->toMatchArray([
                'code' => 'firewall_rule.host_upstream_may_block',
                'family' => 'firewall_rule',
                'node' => 'app-1',
                'port' => '5173',
                'upstream' => 'http://127.0.0.1:5173',
            ])
            ->and(ProxyRoute::query()->where('domain', 'vite.docs.test')->exists())
            ->toBeTrue();

        $route = ProxyRoute::query()->where('domain', 'vite.docs.test')->firstOrFail();

        expect($route->source_hash)->toBe(app(ProxyRouteRenderer::class)->sourceHash($route));
    });

    it('creates redirect intent with redirect code', function (): void {
        createTestAppHostNode(['name' => 'app-1']);

        $result = app(ProxyRouteIntent::class)->add(
            domain: 'old.test',
            nodeName: 'app-1',
            upstream: null,
            redirect: 'https://docs.test',
            code: 301,
            force: false,
        );

        expect($result['data']['route'])->toMatchArray([
            'domain' => 'old.test',
            'kind' => 'redirect',
            'target' => ['type' => 'redirect', 'value' => 'https://docs.test'],
            'redirect_code' => 301,
        ]);
    });

    it('keeps custom route and returns success proxy.enactment_failed when fixer fails', function (): void {
        createTestAppHostNode(['name' => 'app-1']);
        bindFailingProxyRouteFixer();

        $result = app(ProxyRouteIntent::class)->add(
            domain: 'broken.docs.test',
            nodeName: 'app-1',
            upstream: 'http://127.0.0.1:5173',
            redirect: null,
            code: null,
            force: false,
        );

        $warning = collect($result['meta']['warnings'])->firstWhere('code', 'proxy.enactment_failed');
        $route = ProxyRoute::query()->where('domain', 'broken.docs.test')->first();

        expect($route)
            ->toBeInstanceOf(ProxyRoute::class)
            ->and(ProxyRouteEnactment::status(is_array($route->config) ? $route->config : []))
            ->toBeIn(['failed', 'partial'])
            ->and($result['data']['route']['status'])
            ->toBeIn(['failed', 'partial'])
            ->and($result['meta']['action'])
            ->toBe('created')
            ->and($warning)
            ->toMatchArray([
                'code' => 'proxy.enactment_failed',
                'family' => 'proxy',
                'domain' => 'broken.docs.test',
                'node' => 'app-1',
                'next_command' => 'doctor --family=proxy --restore --node=app-1',
            ])
            ->and(collect($result['meta']['warnings'])->pluck('code')->all())
            ->not->toContain('proxy.enactment_deferred');
    });

    it('requires force before replacing different custom intent', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1']);

        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'vite.docs.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => [
                'target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:5173'],
                'upstream' => 'http://127.0.0.1:5173',
            ],
        ]);

        app(ProxyRouteIntent::class)->add(
            domain: 'vite.docs.test',
            nodeName: 'app-1',
            upstream: 'http://127.0.0.1:5174',
            redirect: null,
            code: null,
            force: false,
        );
    })->throws(GatewayApiException::class, 'Existing custom proxy route differs from requested intent.');

    it('does not claim malformed custom route ownership even with force', function (array $attributes): void {
        $node = createTestAppHostNode(['name' => 'app-1']);
        $route = ProxyRoute::query()->create([
            'node_id' => $node->id,
            'domain' => 'vite.docs.test',
            'app_id' => null,
            'workspace_id' => null,
            'instance_id' => null,
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => [
                'target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:5173'],
                'upstream' => 'http://127.0.0.1:5173',
            ],
            'source_hash' => str_repeat('a', 64),
            ...$attributes,
        ]);
        $original = $route->fresh()->getAttributes();

        expect(fn () => app(ProxyRouteIntent::class)->add(
            domain: 'vite.docs.test',
            nodeName: 'app-1',
            upstream: 'http://127.0.0.1:5174',
            redirect: null,
            code: null,
            force: true,
        ))
            ->toThrow(GatewayApiException::class, "Domain 'vite.docs.test' has invalid custom ownership.");

        expect($route->fresh()?->getAttributes())->toBe($original);
    })->with([
        'incomplete config' => [['config' => []]],
        'wrong stable target' => [[
            'config' => [
                'target' => ['type' => 'redirect', 'value' => 'https://wrong.test'],
                'code' => 302,
            ],
        ]],
        'inactive serving node' => [
            fn (): array => [
                'node_id' => createTestAppHostNode(['name' => 'inactive-app', 'status' => 'inactive'])->id,
            ],
        ],
        'node without a serving role' => [
            fn (): array => [
                'node_id' => Node::factory()->create(['name' => 'unassigned-app', 'status' => 'active'])->id,
            ],
        ],
        'stray app identity' => [fn (): array => ['app_id' => App::factory()->create()->id]],
        'stray workspace identity' => [fn (): array => ['workspace_id' => Workspace::factory()->create()->id]],
        'stray instance identity' => [fn (): array => ['instance_id' => Instance::factory()->create()->id]],
        'invalid kind' => [['kind' => 'app']],
    ]);

    it('rejects domains owned by another route family', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1']);
        $app = App::factory()->create(['name' => 'docs']);
        $instance = Instance::factory()->for($app)->create();

        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
        ]);

        app(ProxyRouteIntent::class)->add(
            domain: 'docs.test',
            nodeName: 'app-1',
            upstream: 'http://127.0.0.1:5173',
            redirect: null,
            code: null,
            force: true,
        );
    })->throws(GatewayApiException::class, "Domain 'docs.test' is owned by instance.");

    it('reports the stored owner type for malformed direct-owner conflict metadata', function (
        string $ownerType,
        string $validKind,
        string $invalidity,
    ): void {
        $node = createTestAppHostNode(['name' => 'app-1']);
        $app = App::factory()->create();
        $instance = Instance::factory()->for($app)->create();
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'domain' => 'docs.test',
            'owner_type' => $ownerType,
            'kind' => $validKind,
        ]);

        invalidate_proxy_route_intent_ownership($route, $app, $instance, $validKind, $invalidity);

        try {
            app(ProxyRouteIntent::class)->add(
                domain: 'docs.test',
                nodeName: 'app-1',
                upstream: 'http://127.0.0.1:5173',
                redirect: null,
                code: null,
                force: true,
            );
            $this->fail('Expected a proxy domain conflict.');
        } catch (GatewayApiException $exception) {
            expect($exception->errorCode())
                ->toBe('proxy.domain_conflict')
                ->and($exception->errorMeta())
                ->toMatchArray([
                    'domain' => 'docs.test',
                    'owner_type' => $ownerType,
                ]);
        }
    })->with([
        'primary app missing app' => ['app', 'app', 'missing app'],
        'primary app missing instance' => ['app', 'app', 'missing instance'],
        'primary app conflicting app' => ['app', 'app', 'conflicting app'],
        'primary app wrong kind' => ['app', 'app', 'wrong kind'],
        'primary app workspace identity' => ['app', 'app', 'workspace identity'],
        'analytics missing app' => ['app-analytics', 'proxy', 'missing app'],
        'analytics missing instance' => ['app-analytics', 'proxy', 'missing instance'],
        'analytics conflicting app' => ['app-analytics', 'proxy', 'conflicting app'],
        'analytics wrong kind' => ['app-analytics', 'proxy', 'wrong kind'],
        'analytics workspace identity' => ['app-analytics', 'proxy', 'workspace identity'],
        'websocket missing app' => ['app-websocket', 'proxy', 'missing app'],
        'websocket missing instance' => ['app-websocket', 'proxy', 'missing instance'],
        'websocket conflicting app' => ['app-websocket', 'proxy', 'conflicting app'],
        'websocket wrong kind' => ['app-websocket', 'proxy', 'wrong kind'],
        'websocket workspace identity' => ['app-websocket', 'proxy', 'workspace identity'],
    ]);

    it('fails closed for every malformed direct-owner removal tuple', function (
        string $ownerType,
        string $validKind,
        string $invalidity,
    ): void {
        $node = createTestAppHostNode(['name' => 'app-1']);
        $app = App::factory()->create();
        $instance = Instance::factory()->for($app)->create();
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'domain' => 'docs.test',
            'owner_type' => $ownerType,
            'kind' => $validKind,
        ]);

        invalidate_proxy_route_intent_ownership($route, $app, $instance, $validKind, $invalidity);

        try {
            app(ProxyRouteIntent::class)->remove('docs.test');
            $this->fail('Expected owned route removal to be denied.');
        } catch (GatewayApiException $exception) {
            expect($exception->errorCode())
                ->toBe('proxy.owned_route_denied')
                ->and($exception->errorMeta())
                ->toMatchArray([
                    'domain' => 'docs.test',
                    'owner_type' => $ownerType,
                ]);
        }

        expect(ProxyRoute::query()->whereKey($route->id)->exists())->toBeTrue();
    })->with([
        'primary app missing app' => ['app', 'app', 'missing app'],
        'primary app missing instance' => ['app', 'app', 'missing instance'],
        'primary app conflicting app' => ['app', 'app', 'conflicting app'],
        'primary app wrong kind' => ['app', 'app', 'wrong kind'],
        'primary app workspace identity' => ['app', 'app', 'workspace identity'],
        'analytics missing app' => ['app-analytics', 'proxy', 'missing app'],
        'analytics missing instance' => ['app-analytics', 'proxy', 'missing instance'],
        'analytics conflicting app' => ['app-analytics', 'proxy', 'conflicting app'],
        'analytics wrong kind' => ['app-analytics', 'proxy', 'wrong kind'],
        'analytics workspace identity' => ['app-analytics', 'proxy', 'workspace identity'],
        'websocket missing app' => ['app-websocket', 'proxy', 'missing app'],
        'websocket missing instance' => ['app-websocket', 'proxy', 'missing instance'],
        'websocket conflicting app' => ['app-websocket', 'proxy', 'conflicting app'],
        'websocket wrong kind' => ['app-websocket', 'proxy', 'wrong kind'],
        'websocket workspace identity' => ['app-websocket', 'proxy', 'workspace identity'],
    ]);

    it('removes custom route backend and TLS through the fixer in one step', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1']);
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'old.test',
            'owner_type' => 'custom',
            'kind' => 'redirect',
            'config' => ['target' => ['type' => 'redirect', 'value' => 'https://docs.test'], 'code' => 302],
        ]);

        $result = app(ProxyRouteIntent::class)->remove('old.test');

        expect($result['data']['route'])
            ->toMatchArray([
                'domain' => 'old.test',
                'kind' => 'redirect',
                'status' => 'removed',
            ])
            ->and($result['meta']['backend_removed'])
            ->toBeTrue()
            ->and($result['meta']['tls_removed'])
            ->toBeTrue()
            ->and($result['meta']['removal_reason'])
            ->toBe('custom')
            ->and($result['meta']['warnings'])
            ->toBeEmpty()
            ->and(ProxyRoute::query()->where('domain', 'old.test')->exists())
            ->toBeFalse();
    });

    it('does not remove malformed custom ownership', function (array $attributes): void {
        $node = createTestAppHostNode(['name' => 'app-1']);
        $route = ProxyRoute::query()->create([
            'node_id' => $node->id,
            'domain' => 'old.test',
            'app_id' => null,
            'workspace_id' => null,
            'instance_id' => null,
            'owner_type' => 'custom',
            'kind' => 'redirect',
            'config' => ['target' => ['type' => 'redirect', 'value' => 'https://docs.test'], 'code' => 302],
            'source_hash' => str_repeat('a', 64),
            ...$attributes,
        ]);

        expect(fn () => app(ProxyRouteIntent::class)->remove('old.test'))
            ->toThrow(GatewayApiException::class, "Domain 'old.test' has invalid custom ownership.");

        expect($route->fresh())->toBeInstanceOf(ProxyRoute::class);
    })->with([
        'incomplete stable config' => [['config' => []]],
        'wrong stable target' => [[
            'config' => [
                'target' => ['type' => 'redirect', 'value' => 'https://wrong.test'],
                'code' => 302,
                'unexpected' => true,
            ],
        ]],
        'inactive serving node' => [
            fn (): array => [
                'node_id' => createTestAppHostNode(['name' => 'inactive-remove-app', 'status' => 'inactive'])->id,
            ],
        ],
        'node without a serving role' => [
            fn (): array => [
                'node_id' => Node::factory()->create(['name' => 'unassigned-remove-app', 'status' => 'active'])->id,
            ],
        ],
        'stray app identity' => [fn (): array => ['app_id' => App::factory()->create()->id]],
        'stray workspace identity' => [fn (): array => ['workspace_id' => Workspace::factory()->create()->id]],
        'stray instance identity' => [fn (): array => ['instance_id' => Instance::factory()->create()->id]],
        'invalid kind' => [['kind' => 'internal']],
    ]);

    it('does not remove malformed or differently hosted tool ownership', function (array $attributes): void {
        $routeNode = createTestAppHostNode(['name' => 'app-1']);
        $toolNode = createTestAppHostNode(['name' => 'app-2']);
        NodeTool::factory()->for($toolNode)->create([
            'name' => 'hermes',
            'expected_state' => 'installed',
        ]);
        $route = ProxyRoute::query()->create([
            'node_id' => $routeNode->id,
            'domain' => 'hermes.test',
            'app_id' => null,
            'workspace_id' => null,
            'instance_id' => null,
            'owner_type' => 'tool',
            'kind' => 'proxy',
            'config' => ['owner_name' => 'hermes'],
            'source_hash' => str_repeat('a', 64),
            ...$attributes,
        ]);

        expect(fn () => app(ProxyRouteIntent::class)->remove('hermes.test'))
            ->toThrow(GatewayApiException::class, "Domain 'hermes.test' is owned by tool.");

        expect($route->fresh())->toBeInstanceOf(ProxyRoute::class);
    })->with([
        'wrong tool host identity' => [[]],
        'stray app identity' => [fn (): array => ['app_id' => App::factory()->create()->id]],
        'stray workspace identity' => [fn (): array => ['workspace_id' => Workspace::factory()->create()->id]],
        'stray instance identity' => [fn (): array => ['instance_id' => Instance::factory()->create()->id]],
        'invalid kind' => [['kind' => 'redirect']],
        'missing owner identity' => [['config' => []]],
    ]);

    it('removes an orphaned tool route only when its complete ownership tuple is valid', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1', 'tld' => 'test']);
        $route = ProxyRoute::query()->create([
            'node_id' => $node->id,
            'domain' => 'hermes.test',
            'app_id' => null,
            'workspace_id' => null,
            'instance_id' => null,
            'owner_type' => 'tool',
            'kind' => 'proxy',
            'config' => [
                'owner_name' => 'hermes',
                'upstream' => 'http://host.docker.internal:8080',
                'target' => ['type' => 'upstream', 'value' => 'http://host.docker.internal:8080'],
            ],
            'source_hash' => str_repeat('a', 64),
        ]);

        $result = app(ProxyRouteIntent::class)->remove('hermes.test');

        expect($result['meta'])
            ->toMatchArray([
                'removal_reason' => 'orphan_owner',
                'owner_type' => 'tool',
            ])
            ->and($route->fresh())
            ->toBeNull();
    });

    it('removes an orphaned tool route when the same tool name exists on another node', function (): void {
        $routeNode = createTestAppHostNode(['name' => 'agent-1', 'tld' => 'agent']);
        $otherNode = createTestAppHostNode(['name' => 'agent-2', 'tld' => 'other']);
        NodeTool::factory()->for($otherNode)->create([
            'name' => 'hermes',
            'expected_state' => 'installed',
        ]);
        $route = ProxyRoute::query()->create([
            'node_id' => $routeNode->id,
            'domain' => 'hermes.agent',
            'app_id' => null,
            'workspace_id' => null,
            'instance_id' => null,
            'owner_type' => 'tool',
            'kind' => 'proxy',
            'config' => [
                'owner_name' => 'hermes',
                'upstream' => 'http://host.docker.internal:8080',
                'target' => ['type' => 'upstream', 'value' => 'http://host.docker.internal:8080'],
            ],
            'source_hash' => str_repeat('a', 64),
        ]);

        app(ProxyRouteIntent::class)->remove('hermes.agent');

        expect($route->fresh())->toBeNull();
    });

    it('keeps registry row and returns hard proxy.cleanup_failed when cleanup throws', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1']);
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'stuck.test',
            'owner_type' => 'custom',
            'kind' => 'redirect',
            'config' => [
                'target' => ['type' => 'redirect', 'value' => 'https://docs.test'],
                'code' => 302,
            ],
        ]);
        bindFailingProxyRouteFixer();

        try {
            app(ProxyRouteIntent::class)->remove('stuck.test');
            $this->fail('Expected GatewayApiException for cleanup failure.');
        } catch (GatewayApiException $exception) {
            expect($exception->errorCode())
                ->toBe('proxy.cleanup_failed')
                ->and($exception->getMessage())
                ->toContain('registry is intact')
                ->and($exception->errorMeta())
                ->toMatchArray([
                    'domain' => 'stuck.test',
                    'node' => 'app-1',
                    'backend_removed' => false,
                    'tls_removed' => false,
                    'next_command' => 'doctor --family=proxy --restore --node=app-1',
                ])
                ->and(ProxyRoute::query()->where('domain', 'stuck.test')->exists())
                ->toBeTrue();
        }
    });

    it('denies removal when a workspace owner still exists', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1']);
        $app = App::factory()->create(['name' => 'docs']);
        $workspace = Workspace::factory()->for($app)->create(['name' => 'feature']);

        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
            'workspace_id' => $workspace->id,
            'instance_id' => $workspace->instance_id,
            'domain' => 'feature.docs.test',
            'owner_type' => 'workspace',
            'kind' => 'workspace',
        ]);

        app(ProxyRouteIntent::class)->remove('feature.docs.test');
    })->throws(GatewayApiException::class, "Domain 'feature.docs.test' is owned by workspace.");

    it('keeps an incomplete workspace ownership tuple when the workspace relation is missing', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1']);
        $app = App::factory()->create(['name' => 'docs']);
        $instance = Instance::factory()->for($app)->create();

        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'workspace_id' => null,
            'domain' => 'auth.craft-starterkit-react.test',
            'owner_type' => 'workspace',
            'kind' => 'workspace',
        ]);

        expect(fn (): array => app(ProxyRouteIntent::class)->remove('auth.craft-starterkit-react.test'))
            ->toThrow(
                GatewayApiException::class,
                "Domain 'auth.craft-starterkit-react.test' is owned by workspace.",
            )
            ->and(ProxyRoute::query()->where('domain', 'auth.craft-starterkit-react.test')->exists())
            ->toBeTrue();
    });

    it('denies removal when a living instance owner has mismatched app compatibility', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1']);
        $app = App::factory()->create(['name' => 'docs']);
        $compatibility = App::factory()->create(['name' => 'other']);
        $instance = Instance::factory()->for($app)->create();
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
        ]);
        $route->forceFill(['app_id' => $compatibility->id])->save();

        expect(fn (): array => app(ProxyRouteIntent::class)->remove('docs.test'))
            ->toThrow(GatewayApiException::class, "Domain 'docs.test' is owned by app.")
            ->and(ProxyRoute::query()->whereKey($route->id)->exists())
            ->toBeTrue();
    });

    it('denies removal when a living workspace owner has a mismatched instance tuple', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1']);
        $app = App::factory()->create(['name' => 'docs']);
        $instance = Instance::factory()->for($app)->create();
        $otherInstance = Instance::factory()->for($app)->create(['name' => 'preview']);
        $workspace = Workspace::factory()->for($app)->create(['instance_id' => $instance->id]);
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
            'workspace_id' => $workspace->id,
            'instance_id' => $instance->id,
            'domain' => 'feature.docs.test',
            'owner_type' => 'workspace',
            'kind' => 'workspace',
        ]);
        $route->forceFill(['instance_id' => $otherInstance->id])->save();

        expect(fn (): array => app(ProxyRouteIntent::class)->remove('feature.docs.test'))
            ->toThrow(GatewayApiException::class, "Domain 'feature.docs.test' is owned by workspace.")
            ->and(ProxyRoute::query()->whereKey($route->id)->exists())
            ->toBeTrue();
    });

    it('denies removal when an app owner still exists', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1']);
        $app = App::factory()->create(['name' => 'docs']);
        $instance = Instance::factory()->for($app)->create();

        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
        ]);

        app(ProxyRouteIntent::class)->remove('docs.test');
    })->throws(GatewayApiException::class, "Domain 'docs.test' is owned by");

    it('keeps an incomplete direct ownership tuple when the instance relation is missing', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1']);
        $app = App::factory()->create();
        $instance = Instance::factory()->for($app)->create();

        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'domain' => 'orphan-app.test',
            'owner_type' => 'app',
            'kind' => 'app',
        ]);
        $instance->delete();

        expect(fn (): array => app(ProxyRouteIntent::class)->remove('orphan-app.test'))
            ->toThrow(GatewayApiException::class, "Domain 'orphan-app.test' is owned by app.")
            ->and(ProxyRoute::query()->where('domain', 'orphan-app.test')->exists())
            ->toBeTrue();
    });

    it('authorizes non-gateway callers by serving node grant', function (): void {
        $caller = Node::factory()->appDev()->create();
        $servingNode = createTestAppHostNode(['name' => 'app-1']);
        grantProxyRouteIntentAccess($caller, $servingNode);

        $result = app(ProxyRouteIntent::class)->add(
            domain: 'vite.docs.test',
            nodeName: 'app-1',
            upstream: 'http://127.0.0.1:5173',
            redirect: null,
            code: null,
            force: false,
            caller: $caller,
        );

        expect($result['data']['route']['domain'])->toBe('vite.docs.test');
    });

    it('rejects custom proxy:add on php app-owned domains so frankenphp routes are not overwritten', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1']);
        $app = App::factory()->create(['name' => 'docs']);
        $instance = Instance::factory()->for($app)->create();

        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
        ]);

        app(ProxyRouteIntent::class)->add(
            domain: 'docs.test',
            nodeName: 'app-1',
            upstream: 'http://127.0.0.1:5173',
            redirect: null,
            code: null,
            force: true,
        );
    })->throws(GatewayApiException::class, "Domain 'docs.test' is owned by instance.");
});

/**
 * Force ProxyRouteFixer backend/TLS operations to throw so intent failure paths
 * can be proven without mutating live nodes.
 */
function bindFailingProxyRouteFixer(): void
{
    $executor = new class implements RunsInternalCommands {
        public function runInternal(
            Node $node,
            string $commandName,
            array $arguments = [],
            array $commandOptions = [],
            array $transportOptions = [],
        ): RemoteShellResult {
            return new RemoteShellResult(
                exitCode: 1,
                stdout: '',
                stderr: 'simulated proxy fixer failure',
                durationMs: 1,
            );
        }
    };

    app()->instance(RemoteCaddyConfig::class, new RemoteCaddyConfig($executor));
    app()->instance(ProxyRouteFixer::class, new ProxyRouteFixer(
        renderer: new ProxyRouteRenderer,
        ca: new ProxyIntentFakeCa,
        siteCertificateInstaller: new SiteCertificateInstallerFake,
        caddyConfig: new RemoteCaddyConfig($executor),
    ));
}

final class ProxyIntentRecordingRemoteShell implements RemoteShell
{
    public function __construct()
    {
        app()->instance(RemoteShell::class, $this);
    }

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        if (str_contains($script, "internal:managed-file 'probe'")) {
            return new RemoteShellResult(
                exitCode: 0,
                stdout: json_encode([
                    'success' => [
                        'data' => [
                            'exists' => false,
                            'hash' => null,
                            'mode' => null,
                        ],
                    ],
                ], JSON_THROW_ON_ERROR),
                stderr: '',
                durationMs: 1,
            );
        }

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

final readonly class ProxyIntentFakeCa extends OrbitCaService
{
    #[\Override]
    public function rootCert(): string
    {
        return 'fake-root-ca';
    }

    /**
     * @return array{cert: string, key: string}
     */
    #[\Override]
    public function issueLeaf(string $host, array $additionalSans = []): array
    {
        $dir = sys_get_temp_dir().'/orbit-proxy-intent-ca';

        if (! is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        $cert = "{$dir}/{$host}.crt";
        $key = "{$dir}/{$host}.key";
        file_put_contents($cert, "fake-cert-for-{$host}");
        file_put_contents($key, "fake-key-for-{$host}");

        return ['cert' => $cert, 'key' => $key];
    }
}
