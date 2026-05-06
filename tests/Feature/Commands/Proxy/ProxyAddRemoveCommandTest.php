<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Proxy\AddProxyRouteRequest;
use App\Http\Gateway\Requests\Proxy\RemoveProxyRouteRequest;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\ProxyRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

function createProxyMutationLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",
        'role' => $role,
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
        'is_local' => true,
    ]);
}

describe('proxy add/remove commands', function (): void {
    it('adds custom proxy intent for gateway callers', function (): void {
        createProxyMutationLocalNode('gateway');
        Node::factory()->create(['name' => 'app-1', 'role' => 'app']);

        $exitCode = Artisan::call('proxy:add', [
            'domain' => 'vite.docs.test',
            '--node' => 'app-1',
            '--upstream' => 'http://127.0.0.1:5173',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['route']['domain'])->toBe('vite.docs.test')
            ->and($payload['success']['meta']['action'])->toBe('created')
            ->and($payload['success']['meta']['warnings'][0]['code'])->toBe('proxy.enactment_deferred');
    });

    it('removes custom proxy intent with force', function (): void {
        createProxyMutationLocalNode('gateway');
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
        ProxyRoute::factory()->create(['node_id' => $node->id, 'domain' => 'vite.docs.test']);

        $exitCode = Artisan::call('proxy:remove', [
            'domain' => 'vite.docs.test',
            '--force' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['route']['status'])->toBe('removed_with_drift')
            ->and($payload['success']['meta']['warnings'][0]['code'])->toBe('proxy.cleanup_deferred')
            ->and(ProxyRoute::query()->where('domain', 'vite.docs.test')->exists())->toBeFalse();
    });

    it('requires destructive consent in json mode', function (): void {
        createProxyMutationLocalNode('gateway');

        $exitCode = Artisan::call('proxy:remove', [
            'domain' => 'vite.docs.test',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('destructive_consent_required');
    });

    it('forwards add and remove through typed gateway requests for non-gateway callers', function (): void {
        createProxyMutationLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            AddProxyRouteRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'route' => [
                            'domain' => 'vite.docs.test',
                            'kind' => 'proxy',
                            'owner' => ['type' => 'custom', 'name' => null],
                            'node' => 'app-1',
                            'target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:5173'],
                            'redirect_code' => null,
                            'tls' => ['managed_by' => 'orbit', 'trusted_by_gateway_ca' => true],
                            'status' => 'expected',
                        ],
                    ],
                    'meta' => ['action' => 'created', 'warnings' => []],
                ],
            ], 200),
            RemoveProxyRouteRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'route' => [
                            'domain' => 'vite.docs.test',
                            'kind' => 'proxy',
                            'owner' => ['type' => 'custom', 'name' => null],
                            'node' => 'app-1',
                            'target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:5173'],
                            'redirect_code' => null,
                            'tls' => ['managed_by' => 'orbit', 'trusted_by_gateway_ca' => true],
                            'status' => 'removed_with_drift',
                        ],
                    ],
                    'meta' => ['backend_removed' => false, 'tls_removed' => false, 'warnings' => []],
                ],
            ], 200),
        ]);

        $addExit = Artisan::call('proxy:add', [
            'domain' => 'vite.docs.test',
            '--node' => 'app-1',
            '--upstream' => 'http://127.0.0.1:5173',
            '--json' => true,
        ]);

        $removeExit = Artisan::call('proxy:remove', [
            'domain' => 'vite.docs.test',
            '--force' => true,
            '--json' => true,
        ]);

        expect($addExit)->toBe(0)
            ->and($removeExit)->toBe(0);
    });
});
