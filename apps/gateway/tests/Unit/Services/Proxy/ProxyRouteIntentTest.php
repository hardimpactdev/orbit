<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\Project;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Services\Proxy\ProxyRouteIntent;
use App\Services\Proxy\ProxyRouteRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Orbit\Sdk\Laravel\GatewayApiException;
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

describe('ProxyRouteIntent', function (): void {
    it('creates custom upstream intent with runtime enactment warning', function (): void {
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
                'status' => 'intent_only',
            ])
            ->and($result['meta']['action'])
            ->toBe('created')
            ->and($result['meta']['warnings'][0]['code'])
            ->toBe('proxy.enactment_deferred')
            ->and($result['meta']['warnings'][1])
            ->toMatchArray([
                'code' => 'firewall_rule.host_upstream_may_block',
                'family' => 'firewall_rule',
                'node' => 'app-1',
                'port' => '5173',
                'upstream' => 'http://127.0.0.1:5173',
            ])
            ->and($result['meta']['warnings'][1]['next_command'])
            ->toContain('firewall:allow caddy-to-host-5173 --node=app-1 --port=5173')
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

    it('rejects domains owned by another route family', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1']);
        $app = Project::factory()->create(['name' => 'docs', 'node_id' => $node->id]);

        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
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
    })->throws(GatewayApiException::class, "Domain 'docs.test' is owned by project.");

    it('removes only custom route intent and returns cleanup warning', function (): void {
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
                'status' => 'removed_with_drift',
            ])
            ->and($result['meta']['backend_removed'])
            ->toBeFalse()
            ->and($result['meta']['removal_reason'])
            ->toBe('custom')
            ->and($result['meta']['warnings'][0]['code'])
            ->toBe('proxy.cleanup_deferred')
            ->and(ProxyRoute::query()->where('domain', 'old.test')->exists())
            ->toBeFalse();
    });

    it('denies removal when a workspace owner still exists', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1']);
        $app = Project::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        $workspace = Workspace::factory()->for($app)->create(['name' => 'feature']);

        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
            'workspace_id' => $workspace->id,
            'domain' => 'feature.docs.test',
            'owner_type' => 'workspace',
            'kind' => 'workspace',
        ]);

        app(ProxyRouteIntent::class)->remove('feature.docs.test');
    })->throws(GatewayApiException::class, "Domain 'feature.docs.test' is owned by workspace.");

    it('removes orphaned workspace-owned routes when the workspace record is missing', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1']);
        $app = Project::factory()->create(['name' => 'docs', 'node_id' => $node->id]);

        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
            'workspace_id' => null,
            'domain' => 'auth.craft-starterkit-react.test',
            'owner_type' => 'workspace',
            'kind' => 'workspace',
        ]);

        $result = app(ProxyRouteIntent::class)->remove('auth.craft-starterkit-react.test');

        expect($result['data']['route'])
            ->toMatchArray([
                'domain' => 'auth.craft-starterkit-react.test',
                'status' => 'removed_with_drift',
            ])
            ->and($result['meta']['removal_reason'])
            ->toBe('orphan_owner')
            ->and($result['meta']['owner_type'])
            ->toBe('workspace')
            ->and($result['meta']['warnings'][0]['code'])
            ->toBe('proxy.cleanup_deferred')
            ->and(ProxyRoute::query()->where('domain', 'auth.craft-starterkit-react.test')->exists())
            ->toBeFalse();
    });

    it('denies removal when an app owner still exists', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1']);
        $app = Project::factory()->create(['name' => 'docs', 'node_id' => $node->id]);

        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
        ]);

        app(ProxyRouteIntent::class)->remove('docs.test');
    })->throws(GatewayApiException::class, "Domain 'docs.test' is owned by");

    it('removes orphaned app-owned routes when the project record is missing', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1']);

        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => null,
            'domain' => 'orphan-app.test',
            'owner_type' => 'app',
            'kind' => 'app',
        ]);

        $result = app(ProxyRouteIntent::class)->remove('orphan-app.test');

        expect($result['meta']['removal_reason'])
            ->toBe('orphan_owner')
            ->and($result['meta']['owner_type'])
            ->toBe('project')
            ->and(ProxyRoute::query()->where('domain', 'orphan-app.test')->exists())
            ->toBeFalse();
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
        $app = Project::factory()->create(['name' => 'docs', 'node_id' => $node->id]);

        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
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
    })->throws(GatewayApiException::class, "Domain 'docs.test' is owned by project.");
});
