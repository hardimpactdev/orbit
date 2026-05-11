<?php

declare(strict_types=1);

use App\Actions\Workspaces\SetupWorkspace;
use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\WorkspaceLifecyclePhase;
use App\Enums\WorkspaceLifecycleStatus;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use App\Models\WorkspaceRun;
use App\Models\WorkspaceStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    DB::table('nodes')->insert([
        [
            'name' => 'gateway',
            'role' => 'gateway',
            'host' => 'gateway',
            'ssh_user' => 'gateway',
            'orbit_path' => '/home/gateway/orbit',
            'status' => 'active',
            'is_local' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    DB::table('apps')->insert([
        [
            'name' => 'demo',
            'domain' => 'demo.beast',
            'node_id' => 1,
            'path' => '/home/nckrtl/apps/demo',
            'php_version' => '8.5',
            'environment' => 'development',
            'document_root' => 'public',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    app()->instance(RemoteShell::class, new SetupWorkspaceActionTestShell);
});

it('sets up a workspace and marks it active', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    $app = App::query()->with('node')->first();
    $node = $app->node;

    $setup = app(SetupWorkspace::class);
    $result = $setup->handle($app, $workspace, $node);

    expect($result['action'])->toBe('set_up');
    expect($result['workspace'])->toBe('feature-a');
    expect($result['app'])->toBe('demo');

    $workspace->refresh();
    expect($workspace->lifecycle_status)->toBe(WorkspaceLifecycleStatus::Active);
});

it('enacts workspace PHP-FPM pools with runtime directories and reload-or-restart', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    $app = App::query()->with('node')->first();
    $node = $app->node;
    $shell = new SetupWorkspaceActionTestShell;
    app()->instance(RemoteShell::class, $shell);

    app(SetupWorkspace::class)->handle($app, $workspace, $node);

    expect($shell->scripts[1])->toContain('/etc/php/8.5/fpm/pool.d/orbit-demo-feature-a.conf')
        ->and($shell->scripts[1])->toContain('/home/gateway/.config/orbit/php')
        ->and($shell->scripts[1])->toContain('/home/gateway/.config/orbit/logs')
        ->and($shell->scripts[1])->toContain("PHP_FPM_SERVICE='php8.5-fpm'")
        ->and($shell->scripts[1])->toContain('sudo systemctl restart "$PHP_FPM_SERVICE"');
});

it('reports converged for already-active workspace', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::Active,
    ]);

    $app = App::query()->with('node')->first();
    $node = $app->node;

    $setup = app(SetupWorkspace::class);
    $result = $setup->handle($app, $workspace, $node);

    expect($result['action'])->toBe('converged');
});

it('reports adopted for new workspace with adoption flag', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    $app = App::query()->with('node')->first();
    $node = $app->node;

    $setup = app(SetupWorkspace::class);
    $result = $setup->handle($app, $workspace, $node, isAdoption: true);

    expect($result['action'])->toBe('adopted');
});

it('skips setup steps when none are configured', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    $app = App::query()->with('node')->first();
    $node = $app->node;

    $setup = app(SetupWorkspace::class);
    $result = $setup->handle($app, $workspace, $node);

    expect($result['setup_steps']['status'])->toBe('skipped');
    expect($result['setup_steps']['count'])->toBe(0);
});

it('runs setup steps when configured', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    WorkspaceStep::create([
        'app_id' => 1,
        'phase' => WorkspaceLifecyclePhase::Setup,
        'sort_order' => 1,
        'command' => 'echo "hello"',
        'timeout_seconds' => 60,
    ]);

    $app = App::query()->with('node')->first();
    $node = $app->node;

    $setup = app(SetupWorkspace::class);
    $result = $setup->handle($app, $workspace, $node);

    expect($result['setup_steps']['status'])->toBe('completed');
    expect($result['setup_steps']['count'])->toBe(1);

    $run = WorkspaceRun::query()
        ->where('workspace_id', $workspace->id)
        ->first();

    expect($run)->not->toBeNull();
    expect($run->status)->toBe('completed');
});

it('skips setup steps when hash matches previous successful run', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    WorkspaceStep::create([
        'app_id' => 1,
        'phase' => WorkspaceLifecyclePhase::Setup,
        'sort_order' => 1,
        'command' => 'echo "hello"',
        'timeout_seconds' => 60,
    ]);

    WorkspaceRun::create([
        'workspace_id' => $workspace->id,
        'phase' => WorkspaceLifecyclePhase::Setup,
        'status' => 'completed',
        'step_set_hash' => hash('sha256', json_encode([
            ['command' => 'echo "hello"', 'timeout' => 60],
        ])),
    ]);

    $app = App::query()->with('node')->first();
    $node = $app->node;

    $setup = app(SetupWorkspace::class);
    $result = $setup->handle($app, $workspace, $node);

    expect($result['setup_steps']['status'])->toBe('skipped');
    expect($result['setup_steps']['message'])->toBe('Already up to date');
});

it('throws when setup step fails', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    WorkspaceStep::create([
        'app_id' => 1,
        'phase' => WorkspaceLifecyclePhase::Setup,
        'sort_order' => 1,
        'command' => 'exit 1',
        'timeout_seconds' => 60,
    ]);

    $app = App::query()->with('node')->first();
    $node = $app->node;

    app()->instance(RemoteShell::class, new SetupWorkspaceActionFailingShell);

    $setup = app(SetupWorkspace::class);

    expect(fn () => $setup->handle($app, $workspace, $node))
        ->toThrow(RuntimeException::class, 'Setup step failed: exit 1');

    $workspace->refresh();
    expect($workspace->lifecycle_status)->toBe(WorkspaceLifecycleStatus::SettingUp);
});

final class SetupWorkspaceActionTestShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

final class SetupWorkspaceActionFailingShell implements RemoteShell
{
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'step failed', durationMs: 1);
    }
}
