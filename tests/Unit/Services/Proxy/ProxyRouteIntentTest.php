<?php

declare(strict_types=1);

use App\Http\Gateway\GatewayApiException;
use App\Models\App;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Services\Proxy\ProxyRouteIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);

        $result = app(ProxyRouteIntent::class)->add(
            domain: 'vite.docs.test',
            nodeName: 'app-1',
            upstream: 'http://127.0.0.1:5173',
            redirect: null,
            code: null,
            force: false,
        );

        expect($result['data']['route'])->toMatchArray([
            'domain' => 'vite.docs.test',
            'kind' => 'proxy',
            'owner' => ['type' => 'custom', 'name' => null],
            'node' => 'app-1',
            'target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:5173'],
            'status' => 'expected',
        ])
            ->and($result['meta']['action'])->toBe('created')
            ->and($result['meta']['warnings'][0]['code'])->toBe('proxy.enactment_deferred')
            ->and(ProxyRoute::query()->where('domain', 'vite.docs.test')->exists())->toBeTrue();
    });

    it('creates redirect intent with redirect code', function (): void {
        Node::factory()->create(['name' => 'app-1', 'role' => 'app']);

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
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);

        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'vite.docs.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => ['target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:5173'], 'upstream' => 'http://127.0.0.1:5173'],
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
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);

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
    })->throws(GatewayApiException::class, "Domain 'docs.test' is owned by app.");

    it('removes only custom route intent and returns cleanup warning', function (): void {
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'old.test',
            'owner_type' => 'custom',
            'kind' => 'redirect',
            'config' => ['target' => ['type' => 'redirect', 'value' => 'https://docs.test'], 'code' => 302],
        ]);

        $result = app(ProxyRouteIntent::class)->remove('old.test');

        expect($result['data']['route'])->toMatchArray([
            'domain' => 'old.test',
            'kind' => 'redirect',
            'status' => 'removed_with_drift',
        ])
            ->and($result['meta']['backend_removed'])->toBeFalse()
            ->and($result['meta']['warnings'][0]['code'])->toBe('proxy.cleanup_deferred')
            ->and(ProxyRoute::query()->where('domain', 'old.test')->exists())->toBeFalse();
    });

    it('authorizes non-gateway callers by serving node grant', function (): void {
        $caller = Node::factory()->create(['role' => 'app']);
        $servingNode = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
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
});
