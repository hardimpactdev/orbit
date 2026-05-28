<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Workspaces\ShowWorkspaceLogRequest;
use App\Models\App;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Workspace;
use App\Models\WorkspaceRun;
use App\Models\WorkspaceRunStep;
use App\Models\WorkspaceStep;
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

function createWorkspaceLogLocalNode(string $role = 'gateway'): Node
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

function createWorkspaceLogRun(array $runOverrides = [], array $stepOverrides = []): WorkspaceRun
{
    $node = Node::factory()->appDev()->create(['name' => 'app-1']);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
    $workspace = Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);
    $step = WorkspaceStep::factory()->create([
        'app_id' => $app->id,
        'command' => 'Install dependencies',
    ]);

    $run = WorkspaceRun::factory()->create(array_merge([
        'workspace_id' => $workspace->id,
        'status' => 'failed',
        'started_at' => '2026-05-02 10:00:00',
        'completed_at' => '2026-05-02 10:00:12',
    ], $runOverrides));

    WorkspaceRunStep::factory()->create(array_merge([
        'workspace_run_id' => $run->id,
        'workspace_step_id' => $step->id,
        'command' => 'composer install',
        'exit_code' => 1,
        'output' => "Loading repositories\nYour requirements could not be resolved. [TRUNCATED]",
        'started_at' => '2026-05-02 10:00:03',
        'completed_at' => '2026-05-02 10:00:11',
    ], $stepOverrides));

    return $run;
}

describe('workspace:log base contract', function (): void {
    it('shows captured run output for gateway callers', function (): void {
        createWorkspaceLogLocalNode('gateway');
        $run = createWorkspaceLogRun();

        $exitCode = Artisan::call('workspace:log', [
            'run' => (string) $run->id,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['run']['id'])->toBe($run->id)
            ->and($payload['success']['data']['run']['workspace'])->toBe('feature-docs')
            ->and($payload['success']['data']['run']['app'])->toBe('docs')
            ->and($payload['success']['data']['run']['node'])->toBe('app-1')
            ->and($payload['success']['data']['run']['duration_ms'])->toBe(12000)
            ->and($payload['success']['data']['run']['steps'][0]['status'])->toBe('failure')
            ->and($payload['success']['data']['run']['steps'][0]['stdout_truncated'])->toBeTrue()
            ->and($payload['success']['data']['run']['steps'][0])->not->toHaveKey('env');
    });

    it('validates the run id before querying logs', function (): void {
        $exitCode = Artisan::call('workspace:log', [
            'run' => 'nope',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('run');
    });

    it('returns a stable run-not-found error', function (): void {
        createWorkspaceLogLocalNode('gateway');

        $exitCode = Artisan::call('workspace:log', [
            'run' => '999',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('workspace.run_not_found')
            ->and($payload['error']['meta']['id'])->toBe(999);
    });

    it('forwards non-gateway callers through the typed gateway request', function (): void {
        createWorkspaceLogLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        $mock = MockClient::global([
            ShowWorkspaceLogRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'run' => [
                            'id' => 12,
                            'workspace' => 'feature-docs',
                            'app' => 'docs',
                            'node' => 'app-1',
                            'type' => 'setup',
                            'status' => 'completed',
                            'started_at' => '2026-05-02T10:00:00+00:00',
                            'finished_at' => '2026-05-02T10:00:12+00:00',
                            'duration_ms' => 12000,
                            'steps' => [],
                        ],
                    ],
                    'meta' => ['registry_only' => true],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('workspace:log', [
            'run' => '12',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['run']['id'])->toBe(12);
        $mock->assertSent(fn (ShowWorkspaceLogRequest $request): bool => $request->resolveEndpoint() === '/api/workspaces/runs/12/log');
    });

    it('preserves structured gateway API errors for forwarded callers', function (): void {
        createWorkspaceLogLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            ShowWorkspaceLogRequest::class => MockResponse::make([
                'error' => [
                    'code' => 'authorization_failed',
                    'message' => "This caller is not authorized to read logs for workspace 'feature-docs'.",
                    'meta' => [
                        'workspace' => 'feature-docs',
                        'app' => 'docs',
                    ],
                ],
            ], 403),
        ]);

        $exitCode = Artisan::call('workspace:log', [
            'run' => '12',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('authorization_failed')
            ->and($payload['error']['meta']['workspace'])->toBe('feature-docs');
    });

    it('does not mutate workspace registry state or run processes', function (): void {
        Process::fake();
        Process::preventStrayProcesses();

        createWorkspaceLogLocalNode('gateway');
        $run = createWorkspaceLogRun();

        $workspaceCount = DB::table('workspaces')->count();
        $runCount = DB::table('workspace_runs')->count();
        $stepCount = DB::table('workspace_run_steps')->count();

        $this->artisan("workspace:log {$run->id}")->assertSuccessful();

        expect(DB::table('workspaces')->count())->toBe($workspaceCount)
            ->and(DB::table('workspace_runs')->count())->toBe($runCount)
            ->and(DB::table('workspace_run_steps')->count())->toBe($stepCount);
        Process::assertNothingRan();
    });
});
