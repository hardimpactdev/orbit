<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Apps\ListAppsRequest;
use App\Models\App;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
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

function createAppListLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",
        'role' => $role,
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
        'is_local' => true,
    ]);
}

describe('app:list base contract', function (): void {
    it('lists apps sorted by node then app name for gateway callers', function (): void {
        createAppListLocalNode('gateway');
        $zNode = Node::factory()->create(['name' => 'z-node', 'role' => 'app']);
        $aNode = Node::factory()->create(['name' => 'a-node', 'role' => 'app']);

        App::factory()->create(['name' => 'zebra', 'node_id' => $zNode->id]);
        App::factory()->create(['name' => 'beta', 'node_id' => $aNode->id]);
        App::factory()->create(['name' => 'alpha', 'node_id' => $aNode->id]);

        $exitCode = Artisan::call('app:list', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and(array_column($payload['success']['data']['apps'], 'name'))->toBe(['alpha', 'beta', 'zebra']);
    });

    it('filters by node and environment', function (): void {
        createAppListLocalNode('gateway');
        $devNode = Node::factory()->create(['name' => 'dev-1', 'role' => 'app']);
        $prodNode = Node::factory()->create(['name' => 'prod-1', 'role' => 'app']);

        App::factory()->create(['name' => 'docs', 'node_id' => $devNode->id, 'environment' => 'development']);
        App::factory()->create(['name' => 'site', 'node_id' => $prodNode->id, 'environment' => 'production']);

        $exitCode = Artisan::call('app:list', [
            '--json' => true,
            '--node' => 'prod-1',
            '--environment' => 'production',
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['apps'])->toHaveCount(1)
            ->and($payload['success']['data']['apps'][0]['name'])->toBe('site');
    });

    it('rejects invalid environment', function (): void {
        $exitCode = Artisan::call('app:list', ['--json' => true, '--environment' => 'staging']);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('environment');
    });

    it('forwards non-gateway callers through the typed gateway request', function (): void {
        createAppListLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            ListAppsRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'apps' => [
                            [
                                'name' => 'docs',
                                'node' => 'app-1',
                                'environment' => 'development',
                                'url' => 'https://docs.test',
                                'path' => '/srv/docs',
                                'root' => 'public',
                                'repository' => null,
                                'php_version' => '8.5',
                                'adopted' => false,
                                'workspaces' => [
                                    [
                                        'name' => 'feature-docs',
                                        'url' => 'https://feature-docs.docs.test',
                                        'lifecycle_status' => 'expected',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('app:list', [
            '--json' => true,
            '--node' => 'app-1',
            '--environment' => 'development',
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['apps'][0]['name'])->toBe('docs')
            ->and($payload['success']['data']['apps'][0]['workspaces'][0]['name'])->toBe('feature-docs');
    });

    it('preserves structured gateway authorization failures', function (): void {
        createAppListLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            ListAppsRequest::class => MockResponse::make([
                'error' => [
                    'code' => 'authorization_failed',
                    'message' => 'This node is not authorized to read the app registry.',
                    'meta' => ['caller_role' => 'control'],
                ],
            ], 403),
        ]);

        $exitCode = Artisan::call('app:list', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('authorization_failed')
            ->and($payload['error']['meta']['caller_role'])->toBe('control');
    });

    it('does not mutate app registry state or run processes', function (): void {
        Process::fake();
        Process::preventStrayProcesses();

        createAppListLocalNode('gateway');
        $node = Node::factory()->create(['role' => 'app']);
        App::factory()->count(2)->create(['node_id' => $node->id]);

        $appCount = DB::table('apps')->count();
        $nodeCount = DB::table('nodes')->count();

        $this->artisan('app:list')->assertSuccessful();

        expect(DB::table('apps')->count())->toBe($appCount)
            ->and(DB::table('nodes')->count())->toBe($nodeCount);
        Process::assertNothingRan();
    });
});
