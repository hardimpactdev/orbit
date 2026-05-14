<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\WorkspaceLifecyclePhase;
use App\Enums\WorkspaceLifecycleStatus;
use App\Http\Gateway\Requests\Workspaces\SetupWorkspaceRequest;
use App\Http\Gateway\WorkspaceSetupGatewayStreamClient;
use App\Models\Node;
use App\Models\Workspace;
use App\Models\WorkspaceStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['orbit.is_gateway' => true]);
});

beforeEach(function (): void {
    DB::table('nodes')->insert([
        [
            'name' => 'gateway',
            'role' => 'gateway',
            'host' => 'gateway',
            'orbit_path' => '/home/gateway/orbit',
            'status' => 'active',
            'tld' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'name' => 'app-1',
            'role' => 'app',
            'host' => 'app-1',
            'orbit_path' => '/home/nckrtl/orbit',
            'status' => 'active',
            'tld' => 'beast',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    DB::table('apps')->insert([
        [
            'name' => 'demo',
            'domain' => 'demo.beast',
            'node_id' => 2,
            'path' => '/home/nckrtl/apps/demo',
            'php_version' => '8.5',
            'environment' => 'development',
            'document_root' => 'public',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    MockClient::destroyGlobal();
});

afterEach(fn (): null => MockClient::destroyGlobal());

it('sets up a workspace for a gateway caller', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    $shell = new WorkspaceSetupTestShell;
    app()->instance(RemoteShell::class, $shell);

    $exitCode = Artisan::call('workspace:setup', [
        'name' => 'feature-a',
        '--app' => 'demo',
        '--json' => true,
    ]);

    $output = Artisan::output();
    $payload = json_decode($output, true);

    expect($exitCode)->toBe(0);
    expect($payload['success']['data']['workspace'])->toBe('feature-a');
    expect($payload['success']['data']['app'])->toBe('demo');
    expect($payload['success']['data']['action'])->toBe('set_up');
    expect($payload['success']['data']['setup_steps']['status'])->toBe('skipped');
    expect($payload['success']['data']['processes']['count'])->toBe(0);

    $workspace->refresh();
    expect($workspace->lifecycle_status)->toBe(WorkspaceLifecycleStatus::Active);
});

it('converges an already-active workspace', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::Active,
    ]);

    $shell = new WorkspaceSetupTestShell;
    app()->instance(RemoteShell::class, $shell);

    $exitCode = Artisan::call('workspace:setup', [
        'name' => 'feature-a',
        '--app' => 'demo',
        '--json' => true,
    ]);

    $output = Artisan::output();
    $payload = json_decode($output, true);

    expect($exitCode)->toBe(0);
    expect($payload['success']['data']['action'])->toBe('converged');
});

it('rejects non-absolute path', function (): void {
    $exitCode = Artisan::call('workspace:setup', [
        '--path' => 'relative/path',
        '--app' => 'demo',
        '--json' => true,
    ]);

    $output = Artisan::output();
    $payload = json_decode($output, true);

    expect($exitCode)->toBe(1);
    expect($payload['error']['code'])->toBe('validation_failed');
    expect($payload['error']['meta']['field'])->toBe('path');
});

it('rejects generic workspace paths outside the app worktrees directory', function (): void {
    $exitCode = Artisan::call('workspace:setup', [
        '--path' => '/home/nckrtl/apps/demo/feature-a',
        '--app' => 'demo',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(1);
    expect($payload['error']['code'])->toBe('workspace.path_outside_policy');
    expect($payload['error']['meta']['field'])->toBe('path');
});

it('forwards control callers to gateway', function (): void {
    config(['orbit.is_gateway' => false]);

    $mock = MockClient::global([
        SetupWorkspaceRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'app' => 'demo',
                    'workspace' => 'feature-a',
                    'node' => 'gateway',
                    'url' => 'https://feature-a.demo.beast',
                    'action' => 'set_up',
                    'setup_steps' => ['status' => 'skipped', 'count' => 0, 'message' => 'No setup steps configured'],
                    'processes' => ['status' => 'started', 'count' => 0, 'names' => []],
                    'http_probe' => ['reachable' => true, 'status' => '200'],
                ],
                'meta' => [],
            ],
        ], 200),
    ]);

    $exitCode = Artisan::call('workspace:setup', [
        'name' => 'feature-a',
        '--app' => 'demo',
        '--json' => true,
    ]);

    $output = Artisan::output();
    $payload = json_decode($output, true);

    expect($exitCode)->toBe(0);
    expect($payload['success']['data']['workspace'])->toBe('feature-a');
    expect($payload['success']['data']['action'])->toBe('set_up');
    $mock->assertSent(fn (SetupWorkspaceRequest $request): bool => is_string($request->callerCwd) && $request->callerCwd !== '');
});

it('streams progress for forwarded human setup calls', function (): void {
    config(['orbit.is_gateway' => false]);

    app()->instance(WorkspaceSetupGatewayStreamClient::class, new WorkspaceSetupTestStreamClient);

    $this->artisan('workspace:setup', [
        'name' => 'feature-a',
        '--app' => 'demo',
    ])
        ->expectsOutputToContain('┌  Setting Up Workspace')
        ->expectsOutputToContain('●  Applied and verified workspace registration')
        ->expectsOutputToContain("└  Workspace 'feature-a' converged")
        ->expectsOutputToContain("Workspace 'feature-a' is already converged on node 'app-1'. No changes were needed.")
        ->doesntExpectOutputToContain("\e[?25h")
        ->assertSuccessful();
});

it('resolves workspace by cwd for gateway callers', function (): void {
    $workspacePath = sys_get_temp_dir().'/orbit-test-workspace-'.uniqid();
    mkdir($workspacePath, 0755, true);

    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => $workspacePath,
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    $shell = new WorkspaceSetupTestShell;
    app()->instance(RemoteShell::class, $shell);

    // Simulate running from the workspace directory
    $originalCwd = getcwd();
    chdir($workspacePath);

    $exitCode = Artisan::call('workspace:setup', [
        '--json' => true,
    ]);

    chdir($originalCwd);

    $output = Artisan::output();
    $payload = json_decode($output, true);

    expect($exitCode)->toBe(0);
    expect($payload['success']['data']['workspace'])->toBe('feature-a');
});

it('renders human output without json', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    $shell = new WorkspaceSetupTestShell;
    app()->instance(RemoteShell::class, $shell);

    $this->artisan('workspace:setup', [
        'name' => 'feature-a',
        '--app' => 'demo',
    ])
        ->expectsOutputToContain("Workspace 'feature-a' is set up under app 'demo' on node 'app-1'.")
        ->expectsOutputToContain('URL: https://feature-a.demo.beast')
        ->assertSuccessful();
});

it('renders the documented progress tree and final tree state for human output', function (): void {
    Workspace::create([
        'app_id' => 1,
        'name' => 'feature-tree',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-tree',
        'lifecycle_status' => WorkspaceLifecycleStatus::Active,
    ]);

    app()->instance(RemoteShell::class, new WorkspaceSetupTestShell);

    $this->artisan('workspace:setup', [
        'name' => 'feature-tree',
        '--app' => 'demo',
    ])
        ->expectsOutputToContain('┌  Setting Up Workspace')
        ->expectsOutputToContain('○  Apply and verify workspace registration')
        ->expectsOutputToContain('●  Applied and verified workspace registration')
        ->expectsOutputToContain('●  Registered proxy routes')
        ->expectsOutputToContain('●  Installed PHP-FPM artifacts')
        ->expectsOutputToContain('●  Checked workspace readiness')
        ->expectsOutputToContain("└  Workspace 'feature-tree' converged")
        ->expectsOutputToContain("Workspace 'feature-tree' is already converged on node 'app-1'. No changes were needed.")
        ->assertSuccessful();
});

it('renders the active workspace setup step in the human progress tree', function (): void {
    Workspace::create([
        'app_id' => 1,
        'name' => 'feature-steps',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-steps',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    WorkspaceStep::create([
        'app_id' => 1,
        'phase' => WorkspaceLifecyclePhase::Setup,
        'sort_order' => 1,
        'command' => 'composer install --no-interaction',
        'timeout_seconds' => 1200,
    ]);

    WorkspaceStep::create([
        'app_id' => 1,
        'phase' => WorkspaceLifecyclePhase::Setup,
        'sort_order' => 2,
        'command' => 'npm ci',
        'timeout_seconds' => 900,
    ]);

    app()->instance(RemoteShell::class, new WorkspaceSetupTestShell);

    $this->artisan('workspace:setup', [
        'name' => 'feature-steps',
        '--app' => 'demo',
    ])
        ->expectsOutputToContain('Running setup step 1/2: composer install --no-interaction')
        ->expectsOutputToContain('Running setup step 2/2: npm ci')
        ->expectsOutputToContain('●  Ran workspace setup steps')
        ->assertSuccessful();
});

it('reports workspace not found', function (): void {
    $exitCode = Artisan::call('workspace:setup', [
        'name' => 'missing',
        '--app' => 'demo',
        '--json' => true,
    ]);

    $output = Artisan::output();
    $payload = json_decode($output, true);

    expect($exitCode)->toBe(1);
    expect($payload['error']['code'])->toBe('workspace.not_found');
});

final class WorkspaceSetupTestShell implements RemoteShell
{
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

final class WorkspaceSetupTestStreamClient extends WorkspaceSetupGatewayStreamClient
{
    public function run(?string $name, ?string $app, ?string $path, ?string $callerCwd, callable $onEvent): int
    {
        $onEvent('tree', [
            'title' => 'Setting Up Workspace',
            'steps' => [
                [
                    'key' => 'apply_workspace_registration',
                    'label' => 'Apply and verify workspace registration',
                    'doneLabel' => 'Applied and verified workspace registration',
                ],
            ],
        ]);
        $onEvent('step', [
            'key' => 'apply_workspace_registration',
            'status' => 'start',
        ]);
        $onEvent('step', [
            'key' => 'apply_workspace_registration',
            'status' => 'done',
            'message' => 'feature-a',
        ]);
        $onEvent('complete', [
            'exit_code' => 0,
            'data' => [
                'footer' => "Workspace 'feature-a' converged",
                'result' => [
                    'app' => 'demo',
                    'workspace' => 'feature-a',
                    'node' => 'app-1',
                    'url' => 'https://feature-a.demo.beast',
                    'action' => 'converged',
                    'warnings' => [],
                    'setup_steps' => ['status' => 'skipped', 'count' => 0, 'message' => 'No setup steps configured'],
                    'processes' => ['status' => 'started', 'count' => 0, 'names' => [], 'message' => 'No processes'],
                    'http_probe' => ['reachable' => true, 'status' => '200'],
                ],
            ],
        ]);

        return 0;
    }
}
