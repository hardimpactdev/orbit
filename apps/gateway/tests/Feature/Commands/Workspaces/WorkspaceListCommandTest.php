<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Workspaces\ListWorkspacesRequest;
use App\Models\App;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Workspace;
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

function createWorkspaceListLocalNode(string $role = 'gateway'): Node
{
    config(['orbit.is_gateway' => $role === 'gateway']);

    return Node::factory()->create([
        'name' => "local-{$role}",

        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
    ]);
}

function assignWorkspaceListAppHostRole(Node $node, string $role = 'app-dev', array $settings = ['tld' => 'test']): void
{
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => 'active',
        'settings' => $settings,
    ]);
}

describe('workspace:list base contract', function (): void {
    it('lists workspaces sorted by node then app then workspace for gateway callers', function (): void {
        createWorkspaceListLocalNode('gateway');
        $zNode = Node::factory()->create(['name' => 'z-node']);
        $aNode = Node::factory()->create(['name' => 'a-node']);
        $zApp = App::factory()->create(['name' => 'zebra', 'node_id' => $zNode->id]);
        $bApp = App::factory()->create(['name' => 'beta', 'node_id' => $aNode->id]);
        $aApp = App::factory()->create(['name' => 'alpha', 'node_id' => $aNode->id]);

        Workspace::factory()->create(['name' => 'z-workspace', 'app_id' => $zApp->id]);
        Workspace::factory()->create(['name' => 'beta-two', 'app_id' => $bApp->id]);
        Workspace::factory()->create(['name' => 'alpha-one', 'app_id' => $aApp->id]);
        Workspace::factory()->create(['name' => 'beta-one', 'app_id' => $bApp->id]);

        $exitCode = Artisan::call('workspace:list', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and(array_column($payload['success']['data']['workspaces'], 'name'))->toBe(['alpha-one', 'beta-one', 'beta-two', 'z-workspace']);
    });

    it('filters by app and node', function (): void {
        createWorkspaceListLocalNode('gateway');
        $devNode = Node::factory()->create(['name' => 'dev-1']);
        $prodNode = Node::factory()->create(['name' => 'prod-1']);
        assignWorkspaceListAppHostRole($devNode);
        assignWorkspaceListAppHostRole($prodNode, 'app-prod', []);
        $docs = App::factory()->create(['name' => 'docs', 'node_id' => $devNode->id]);
        $site = App::factory()->create(['name' => 'site', 'node_id' => $prodNode->id]);

        Workspace::factory()->create(['name' => 'docs-feature', 'app_id' => $docs->id]);
        Workspace::factory()->create(['name' => 'site-feature', 'app_id' => $site->id]);

        $exitCode = Artisan::call('workspace:list', [
            '--json' => true,
            '--app' => 'site',
            '--node' => 'prod-1',
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['workspaces'])->toHaveCount(1)
            ->and($payload['success']['data']['workspaces'][0]['name'])->toBe('site-feature');
    });

    it('rejects legacy app-only node filters without an active app host role', function (): void {
        createWorkspaceListLocalNode('gateway');
        Node::factory()->create(['name' => 'legacy-app-only']);

        $exitCode = Artisan::call('workspace:list', [
            '--json' => true,
            '--node' => 'legacy-app-only',
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('node')
            ->and($payload['error']['meta']['value'])->toBe('legacy-app-only');
    });

    it('rejects invalid scalar filters before gateway calls', function (): void {
        $exitCode = Artisan::call('workspace:list', ['--json' => true, '--app' => 'docs,site']);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('app')
            ->and($payload['error']['meta']['value'])->toBe('docs,site');
    });

    it('forwards non-gateway callers through the typed gateway request', function (): void {
        createWorkspaceListLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            ListWorkspacesRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'workspaces' => [
                            [
                                'name' => 'feature-docs',
                                'app' => 'docs',
                                'node' => 'app-1',
                                'url' => 'https://feature-docs.docs.test',
                                'lifecycle_status' => 'expected',
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('workspace:list', [
            '--json' => true,
            '--app' => 'docs',
            '--node' => 'app-1',
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['workspaces'][0]['name'])->toBe('feature-docs');
    });

    it('preserves structured gateway authorization failures', function (): void {
        createWorkspaceListLocalNode('app');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            ListWorkspacesRequest::class => MockResponse::make([
                'error' => [
                    'code' => 'authorization_failed',
                    'message' => 'This node is not authorized to read the workspace registry.',
                    'meta' => [
                        'reason' => 'missing_permission',
                        'missing_permission' => 'workspace:read',
                    ],
                ],
            ], 403),
        ]);

        $exitCode = Artisan::call('workspace:list', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('authorization_failed')
            ->and($payload['error']['meta']['missing_permission'])->toBe('workspace:read');
    });

    it('renders gateway unavailable failures', function (): void {
        createWorkspaceListLocalNode('control');

        $exitCode = Artisan::call('workspace:list', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('gateway_unavailable')
            ->and($payload['error']['message'])->toBe('Gateway connection is required to list workspaces.')
            ->and($payload['error']['meta'])->toBe([]);
    });

    it('does not mutate workspace registry state or run processes', function (): void {
        Process::fake();
        Process::preventStrayProcesses();

        createWorkspaceListLocalNode('gateway');
        $node = Node::factory()->create([]);
        $app = App::factory()->create(['node_id' => $node->id]);
        Workspace::factory()->count(2)->create(['app_id' => $app->id]);

        $workspaceCount = DB::table('workspaces')->count();
        $runCount = DB::table('workspace_runs')->count();

        $this->artisan('workspace:list')->assertSuccessful();

        expect(DB::table('workspaces')->count())->toBe($workspaceCount)
            ->and(DB::table('workspace_runs')->count())->toBe($runCount);
        Process::assertNothingRan();
    });
});
