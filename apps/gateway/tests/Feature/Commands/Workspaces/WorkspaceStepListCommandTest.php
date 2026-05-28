<?php

declare(strict_types=1);

use App\Enums\WorkspaceLifecyclePhase;
use App\Http\Gateway\Requests\Workspaces\ListWorkspaceStepsRequest;
use App\Models\App;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Workspace;
use App\Models\WorkspaceStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

function createWorkspaceStepListLocalNode(string $role = 'gateway'): Node
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

function createWorkspaceStepListApp(): App
{
    $node = Node::factory()->appDev()->create(['name' => 'app-1']);

    return App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'path' => '/srv/docs',
    ]);
}

describe('workspace step list commands', function (): void {
    it('lists setup steps in execution order for gateway callers', function (): void {
        createWorkspaceStepListLocalNode('gateway');
        $app = createWorkspaceStepListApp();
        WorkspaceStep::factory()->create([
            'app_id' => $app->id,
            'phase' => WorkspaceLifecyclePhase::Setup,
            'sort_order' => 2,
            'command' => 'npm install',
            'timeout_seconds' => 300,
        ]);
        WorkspaceStep::factory()->create([
            'app_id' => $app->id,
            'phase' => WorkspaceLifecyclePhase::Setup,
            'sort_order' => 1,
            'command' => 'composer install',
            'timeout_seconds' => 600,
        ]);
        WorkspaceStep::factory()->create([
            'app_id' => $app->id,
            'phase' => WorkspaceLifecyclePhase::Teardown,
            'sort_order' => 1,
            'command' => 'dropdb docs',
        ]);

        $exitCode = Artisan::call('workspace-setup-step:list', [
            '--app' => 'docs',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['steps'])->toHaveCount(2)
            ->and($payload['success']['data']['steps'][0])->toMatchArray([
                'app' => 'docs',
                'phase' => 'setup',
                'order' => 1,
                'command' => 'composer install',
                'timeout_seconds' => 600,
            ])
            ->and($payload['success']['data']['steps'][1]['order'])->toBe(2);
    });

    it('lists teardown steps without reversing their configured order', function (): void {
        createWorkspaceStepListLocalNode('gateway');
        $app = createWorkspaceStepListApp();
        WorkspaceStep::factory()->create([
            'app_id' => $app->id,
            'phase' => WorkspaceLifecyclePhase::Teardown,
            'sort_order' => 1,
            'command' => 'dropdb docs',
        ]);
        WorkspaceStep::factory()->create([
            'app_id' => $app->id,
            'phase' => WorkspaceLifecyclePhase::Teardown,
            'sort_order' => 2,
            'command' => 'rm -rf storage/logs',
        ]);

        $exitCode = Artisan::call('workspace-teardown-step:list', [
            '--app' => 'docs',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and(array_column($payload['success']['data']['steps'], 'command'))->toBe([
                'dropdb docs',
                'rm -rf storage/logs',
            ])
            ->and($payload['success']['data']['steps'][0]['phase'])->toBe('teardown');
    });

    it('returns an empty list for authorized apps with no steps', function (): void {
        createWorkspaceStepListLocalNode('gateway');
        createWorkspaceStepListApp();

        $exitCode = Artisan::call('workspace-setup-step:list', [
            '--app' => 'docs',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['steps'])->toBe([]);
    });

    it('resolves parent app from a workspace path on gateway callers', function (): void {
        createWorkspaceStepListLocalNode('gateway');
        $app = createWorkspaceStepListApp();
        $workspace = Workspace::factory()->create([
            'app_id' => $app->id,
            'name' => 'feature-docs',
            'path' => '/tmp/orbit-workspace-step-list/docs/.worktrees/feature-docs',
        ]);
        WorkspaceStep::factory()->create([
            'app_id' => $app->id,
            'phase' => WorkspaceLifecyclePhase::Setup,
            'command' => 'composer install',
        ]);

        File::ensureDirectoryExists($workspace->path);
        $previousCwd = getcwd();
        chdir($workspace->path);

        try {
            $exitCode = Artisan::call('workspace-setup-step:list', ['--json' => true]);
        } finally {
            chdir((string) $previousCwd);
        }

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['steps'][0]['app'])->toBe('docs');
    });

    it('returns app-not-found for unknown explicit apps', function (): void {
        createWorkspaceStepListLocalNode('gateway');

        $exitCode = Artisan::call('workspace-setup-step:list', [
            '--app' => 'missing',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('workspace.app_not_found')
            ->and($payload['error']['meta']['app'])->toBe('missing');
    });

    it('forwards non-gateway callers through the typed gateway request', function (): void {
        createWorkspaceStepListLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        $mock = MockClient::global([
            ListWorkspaceStepsRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'steps' => [
                            [
                                'id' => 10,
                                'app' => 'docs',
                                'phase' => 'setup',
                                'order' => 1,
                                'command' => 'composer install',
                                'timeout_seconds' => 600,
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('workspace-setup-step:list', [
            '--app' => 'docs',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['steps'][0]['id'])->toBe(10);
        $mock->assertSent(fn (ListWorkspaceStepsRequest $request): bool => $request->phase === WorkspaceLifecyclePhase::Setup
            && $request->app === 'docs'
            && $request->resolveEndpoint() === '/api/workspaces/steps/setup');
    });

    it('forwards non-gateway cwd resolution as a gateway path lookup', function (): void {
        createWorkspaceStepListLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        $mock = MockClient::global([
            ListWorkspaceStepsRequest::class => MockResponse::make([
                'success' => ['data' => ['steps' => []]],
            ], 200),
        ]);

        $exitCode = Artisan::call('workspace-teardown-step:list', ['--json' => true]);

        expect($exitCode)->toBe(0);
        $mock->assertSent(fn (ListWorkspaceStepsRequest $request): bool => $request->phase === WorkspaceLifecyclePhase::Teardown
            && $request->app === null
            && is_string($request->path)
            && $request->path !== '');
    });

    it('preserves structured gateway errors for forwarded callers', function (): void {
        createWorkspaceStepListLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            ListWorkspaceStepsRequest::class => MockResponse::make([
                'error' => [
                    'code' => 'authorization_failed',
                    'message' => "You are not authorized to read setup-step policy for 'docs'.",
                    'meta' => ['app' => 'docs'],
                ],
            ], 403),
        ]);

        $exitCode = Artisan::call('workspace-setup-step:list', [
            '--app' => 'docs',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('authorization_failed')
            ->and($payload['error']['meta']['app'])->toBe('docs');
    });

    it('does not mutate workspace step policy or run processes', function (): void {
        Process::fake();
        Process::preventStrayProcesses();

        createWorkspaceStepListLocalNode('gateway');
        $app = createWorkspaceStepListApp();
        WorkspaceStep::factory()->create(['app_id' => $app->id]);

        $stepCount = DB::table('workspace_steps')->count();

        $this->artisan('workspace-setup-step:list --app=docs')->assertSuccessful();

        expect(DB::table('workspace_steps')->count())->toBe($stepCount);
        Process::assertNothingRan();
    });
});
