<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Proxy\ListProxyRoutesRequest;
use App\Models\App;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\ProxyRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

function createProxyListLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",
        'role' => $role,
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
        'is_local' => true,
    ]);
}

describe('proxy:list', function (): void {
    it('lists gateway registry intent as json with metadata', function (): void {
        createProxyListLocalNode('gateway');
        $node = Node::factory()->create(['name' => 'app-1']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);

        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
        ]);

        $exitCode = Artisan::call('proxy:list', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['routes'][0]['domain'])->toBe('docs.test')
            ->and($payload['success']['meta'])->toBe([
                'filter' => 'all',
                'node' => null,
                'count' => 1,
            ]);
    });

    it('filters by node and route filter', function (): void {
        createProxyListLocalNode('gateway');
        $firstNode = Node::factory()->create(['name' => 'app-1']);
        $secondNode = Node::factory()->create(['name' => 'app-2']);

        ProxyRoute::factory()->create([
            'node_id' => $firstNode->id,
            'domain' => 'custom.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $secondNode->id,
            'domain' => 'old.test',
            'owner_type' => 'custom',
            'kind' => 'redirect',
        ]);

        $exitCode = Artisan::call('proxy:list', [
            '--json' => true,
            '--node' => 'app-2',
            '--filter' => 'redirect',
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and(array_column($payload['success']['data']['routes'], 'domain'))->toBe(['old.test'])
            ->and($payload['success']['meta'])->toBe([
                'filter' => 'redirect',
                'node' => 'app-2',
                'count' => 1,
            ]);
    });

    it('renders human tables and empty scope states', function (): void {
        createProxyListLocalNode('gateway');
        $node = Node::factory()->create(['name' => 'app-1']);

        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'custom.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => ['upstream' => 'http://127.0.0.1:9000'],
        ]);

        $this->artisan('proxy:list')
            ->expectsTable(
                ['Domain', 'Kind', 'Owner', 'Node', 'Target', 'TLS', 'Status'],
                [['custom.test', 'proxy', 'custom', 'app-1', 'upstream:http://127.0.0.1:9000', 'orbit', 'expected']],
            )
            ->assertSuccessful();

        $this->artisan('proxy:list --filter=tool')
            ->expectsOutput("No proxy routes found for filter 'tool' on all nodes.")
            ->assertSuccessful();
    });

    it('rejects invalid filter input before gateway calls', function (): void {
        $exitCode = Artisan::call('proxy:list', ['--json' => true, '--filter' => 'bad']);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('filter')
            ->and($payload['error']['meta']['allowed'])->toBe(['all', 'app', 'workspace', 'gateway', 'tool', 'custom', 'redirect']);
    });

    it('forwards non-gateway callers through the typed gateway request', function (): void {
        createProxyListLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            ListProxyRoutesRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'routes' => [
                            [
                                'domain' => 'docs.test',
                                'kind' => 'app',
                                'owner' => ['type' => 'app', 'name' => 'docs'],
                                'node' => 'app-1',
                                'target' => ['type' => 'app', 'value' => 'docs'],
                                'redirect_code' => null,
                                'tls' => ['managed_by' => 'orbit', 'trusted_by_gateway_ca' => true],
                                'status' => 'expected',
                            ],
                        ],
                    ],
                    'meta' => [
                        'filter' => 'app',
                        'node' => 'app-1',
                        'count' => 1,
                    ],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('proxy:list', [
            '--json' => true,
            '--filter' => 'app',
            '--node' => 'app-1',
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['routes'][0]['domain'])->toBe('docs.test')
            ->and($payload['success']['meta']['filter'])->toBe('app');
    });

    it('preserves structured gateway authorization failures', function (): void {
        createProxyListLocalNode('app');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            ListProxyRoutesRequest::class => MockResponse::make([
                'error' => [
                    'code' => 'authorization_failed',
                    'message' => 'This node is not authorized to read the proxy route registry.',
                    'meta' => ['caller_role' => 'app'],
                ],
            ], 403),
        ]);

        $exitCode = Artisan::call('proxy:list', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('authorization_failed')
            ->and($payload['error']['meta']['caller_role'])->toBe('app');
    });

    it('does not mutate proxy registry state or run live probes', function (): void {
        Process::fake();
        Process::preventStrayProcesses();

        createProxyListLocalNode('gateway');
        ProxyRoute::factory()->count(2)->create();

        $routeCount = DB::table('proxy_routes')->count();

        $this->artisan('proxy:list')->assertSuccessful();

        expect(DB::table('proxy_routes')->count())->toBe($routeCount);
        Process::assertNothingRan();
    });
});
