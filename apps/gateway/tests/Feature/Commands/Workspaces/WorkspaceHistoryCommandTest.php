<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Workspaces\ShowWorkspaceHistoryRequest;
use App\Models\App;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Workspace;
use App\Models\WorkspaceRun;
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

function createWorkspaceHistoryLocalNode(string $role = 'gateway'): Node
{
    config(['orbit.is_gateway' => $role === 'gateway']);

    $node = Node::factory()->create([
        'name' => "local-{$role}",
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
    ]);

    if ($role === 'gateway') {
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'gateway',
            'status' => 'active',
            'settings' => [],
        ]);
    }

    return $node;
}

describe('workspace:history base contract', function (): void {
    it('shows workspace run history for gateway callers', function (): void {
        createWorkspaceHistoryLocalNode('gateway');
        $node = Node::factory()->appDev()->create();
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        $workspace = Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);
        WorkspaceRun::factory()->create([
            'workspace_id' => $workspace->id,
            'status' => 'completed',
            'started_at' => '2026-05-02 10:00:00',
            'completed_at' => '2026-05-02 10:00:45',
        ]);

        $exitCode = Artisan::call('workspace:history', [
            'name' => 'feature-docs',
            '--app' => 'docs',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['runs'][0]['workspace'])->toBe('feature-docs')
            ->and($payload['success']['data']['runs'][0]['action'])->toBe('setup')
            ->and($payload['success']['meta']['pagination']['total'])->toBe(1);
    });

    it('validates limit before querying history', function (): void {
        $exitCode = Artisan::call('workspace:history', [
            'name' => 'feature-docs',
            '--limit' => 'nope',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('limit');
    });

    it('fails when a workspace name is ambiguous without app disambiguation', function (): void {
        createWorkspaceHistoryLocalNode('gateway');
        $docs = App::factory()->create(['name' => 'docs']);
        $api = App::factory()->create(['name' => 'api']);
        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $docs->id]);
        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $api->id]);

        $exitCode = Artisan::call('workspace:history', ['name' => 'feature-docs', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('workspace.ambiguous_name')
            ->and($payload['error']['meta']['apps'])->toBe(['docs', 'api']);
    });

    it('forwards non-gateway callers through the typed gateway request', function (): void {
        createWorkspaceHistoryLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            ShowWorkspaceHistoryRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'runs' => [
                            [
                                'id' => 12,
                                'workspace' => 'feature-docs',
                                'app' => 'docs',
                                'action' => 'setup',
                                'status' => 'completed',
                                'triggered_by' => 'unknown',
                                'started_at' => '2026-05-02T10:00:00+00:00',
                                'finished_at' => '2026-05-02T10:01:00+00:00',
                                'lifecycle_phase' => 'setup',
                                'error_summary' => null,
                            ],
                        ],
                    ],
                    'meta' => [
                        'pagination' => [
                            'total' => 1,
                            'limit' => 50,
                            'since' => null,
                            'until' => null,
                            'limit_capped' => false,
                        ],
                    ],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('workspace:history', [
            'name' => 'feature-docs',
            '--app' => 'docs',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['runs'][0]['id'])->toBe(12);
    });

    it('forwards non-gateway cwd resolution through the typed gateway request', function (): void {
        createWorkspaceHistoryLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        $mock = MockClient::global([
            ShowWorkspaceHistoryRequest::class => MockResponse::make([
                'success' => [
                    'data' => ['runs' => []],
                    'meta' => ['pagination' => ['total' => 0, 'limit' => 50]],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('workspace:history', ['--json' => true]);

        expect($exitCode)->toBe(0);
        $mock->assertSent(fn (ShowWorkspaceHistoryRequest $request): bool => $request->resolveEndpoint() === '/api/workspaces/history/resolve-by-path'
            && is_string($request->path)
            && $request->path !== '');
    });

    it('does not mutate workspace registry state or run processes', function (): void {
        Process::fake();
        Process::preventStrayProcesses();

        createWorkspaceHistoryLocalNode('gateway');
        $node = Node::factory()->appDev()->create();
        $app = App::factory()->create(['node_id' => $node->id]);
        $workspace = Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);
        WorkspaceRun::factory()->create(['workspace_id' => $workspace->id]);

        $workspaceCount = DB::table('workspaces')->count();
        $runCount = DB::table('workspace_runs')->count();

        $this->artisan('workspace:history feature-docs')->assertSuccessful();

        expect(DB::table('workspaces')->count())->toBe($workspaceCount)
            ->and(DB::table('workspace_runs')->count())->toBe($runCount);
        Process::assertNothingRan();
    });
});
