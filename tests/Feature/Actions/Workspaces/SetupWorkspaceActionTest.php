<?php

declare(strict_types=1);

use App\Actions\Workspaces\SetupWorkspace;
use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\WorkspaceLifecyclePhase;
use App\Enums\WorkspaceLifecycleStatus;
use App\Models\App;
use App\Models\Node;
use App\Models\Process as OrbitProcess;
use App\Models\Workspace;
use App\Models\WorkspaceRun;
use App\Models\WorkspaceStep;
use App\Services\Workspaces\WorkspaceFpmPoolRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    DB::table('nodes')->insert([
        [
            'name' => 'gateway',
            'role' => 'gateway',
            'host' => 'gateway',
            'user' => 'gateway',
            'orbit_path' => '/home/gateway/orbit',
            'status' => 'active',
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
    app()->instance(SiteCertificateInstaller::class, new SetupWorkspaceActionTestCertificateInstaller);
});

it('sets up a workspace and marks it active', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
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
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    $app = App::query()->with('node')->first();
    $node = $app->node;
    $shell = new SetupWorkspaceActionTestShell;
    $certificates = new SetupWorkspaceActionTestCertificateInstaller;
    app()->instance(RemoteShell::class, $shell);
    app()->instance(SiteCertificateInstaller::class, $certificates);

    app(SetupWorkspace::class)->handle($app, $workspace, $node);

    $fpmPool = base64_decode((string) str($shell->scripts[1])->match("/printf %s\\s+'([^']+)'/")->toString(), true);

    expect($shell->scripts[1])->toContain('/etc/php/8.5/fpm/pool.d/orbit-demo-feature-a.conf')
        ->and($shell->scripts[1])->toContain('/home/gateway/.config/orbit/php')
        ->and($shell->scripts[1])->toContain('/home/gateway/.config/orbit/logs')
        ->and($fpmPool)->toBe(app(WorkspaceFpmPoolRenderer::class)->content($workspace))
        ->and($shell->scripts[1])->toContain("PHP_FPM_SERVICE='php8.5-fpm'")
        ->and($shell->scripts[1])->toContain('sudo rm -f "$ORBIT_STALE_POOL"')
        ->and($shell->scripts[1])->toContain('sudo systemctl restart "$PHP_FPM_SERVICE"');
});

it('registers workspace proxy routes against the rendered workspace PHP-FPM socket', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    $app = App::query()->with('node')->first();
    $node = $app->node;
    $shell = new SetupWorkspaceActionTestShell;
    $certificates = new SetupWorkspaceActionTestCertificateInstaller;
    app()->instance(RemoteShell::class, $shell);
    app()->instance(SiteCertificateInstaller::class, $certificates);

    app(SetupWorkspace::class)->handle($app, $workspace, $node);

    $caddySite = base64_decode((string) str($shell->scripts[0])->match("/printf %s\\s+'([^']+)'/")->toString(), true);
    $route = $workspace->proxyRoutes()->first();

    expect($caddySite)->toContain('tls /home/gateway/.config/orbit/certs/feature-a.demo.crt /home/gateway/.config/orbit/certs/feature-a.demo.key')
        ->and($caddySite)->toContain('php_fastcgi unix//home/gateway/.config/orbit/php/orbit-demo-feature-a.sock')
        ->and($route?->config['php_socket'])->toBe('/home/gateway/.config/orbit/php/orbit-demo-feature-a.sock')
        ->and($route?->config['tls'])->toBe([
            'cert_path' => '/home/gateway/.config/orbit/certs/feature-a.demo.crt',
            'key_path' => '/home/gateway/.config/orbit/certs/feature-a.demo.key',
        ])
        ->and($certificates->hosts)->toBe(['feature-a.demo'])
        ->and($route?->source_hash)->toBe(hash('sha256', $caddySite));
});

it('starts configured app processes for the workspace after rendering runtime units', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    OrbitProcess::query()->create([
        'app_id' => 1,
        'name' => 'vite',
        'command' => 'npm run dev -- --host=0.0.0.0',
        'restart_policy' => 'always',
        'crash_notification' => 'none',
        'sort_order' => 1,
    ]);

    $app = App::query()->with('node')->first();
    $node = $app->node;
    $shell = new SetupWorkspaceActionTestShell;
    $certificates = new SetupWorkspaceActionTestCertificateInstaller;
    app()->instance(RemoteShell::class, $shell);
    app()->instance(SiteCertificateInstaller::class, $certificates);

    $result = app(SetupWorkspace::class)->handle($app, $workspace, $node);

    expect($result['processes'])->toMatchArray([
        'status' => 'started',
        'count' => 1,
        'names' => ['vite'],
    ])
        ->and($certificates->hosts)->toBe(['feature-a.demo', 'feature-a.demo.beast'])
        ->and(collect($shell->scripts)->contains(
            fn (string $script): bool => str_contains($script, '/etc/supervisor/conf.d/orbit_demo_feature-a_vite.conf')
        ))->toBeTrue()
        ->and($shell->scripts)->toContain("sudo supervisorctl start 'orbit_demo_feature-a_vite' || sudo supervisorctl restart 'orbit_demo_feature-a_vite'");
});

it('reports converged for already-active workspace', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
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
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
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
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
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
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
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

it('reports progress while setup steps are running', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
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

    $app = App::query()->with('node')->first();
    $node = $app->node;
    $events = [];

    app(SetupWorkspace::class)->runSetupSteps(
        $workspace,
        $app,
        $node,
        function (string $event, WorkspaceStep $step, int $index, int $count) use (&$events): void {
            $events[] = [$event, $step->command, $index, $count];
        },
    );

    expect($events)->toBe([
        ['running', 'composer install --no-interaction', 1, 2],
        ['completed', 'composer install --no-interaction', 1, 2],
        ['running', 'npm ci', 2, 2],
        ['completed', 'npm ci', 2, 2],
    ]);
});

it('reuses app dependencies for adapter-managed workspace setup steps', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/.polyscope/clones/demo/feature-a',
        'agent_ide' => 'polyscope',
        'agent_ide_workspace_id' => 'adapter-workspace-id',
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

    $shell = new SetupWorkspaceActionDependencyLinkShell;
    app()->instance(RemoteShell::class, $shell);

    $app = App::query()->with('node')->first();
    $node = $app->node;

    $result = app(SetupWorkspace::class)->runSetupSteps($workspace, $app, $node);

    expect($result['status'])->toBe('completed')
        ->and($shell->scripts)->toHaveCount(1)
        ->and($shell->scripts[0])->toContain('ORBIT_WORKSPACE_DEPENDENCY_LINKS');

    $runSteps = WorkspaceRun::query()
        ->where('workspace_id', $workspace->id)
        ->first()
        ?->runSteps()
        ->orderBy('id')
        ->get();

    expect($runSteps)->not->toBeNull()
        ->and($runSteps)->toHaveCount(2)
        ->and($runSteps[0]->output)->toBe('Skipped because the workspace uses the app vendor directory.')
        ->and($runSteps[1]->output)->toBe('Skipped because the workspace uses the app node_modules directory.');
});

it('skips setup steps when hash matches previous successful run', function (): void {
    $workspace = Workspace::create([
        'app_id' => 1,
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
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
        'path' => '/home/nckrtl/apps/demo/.worktrees/feature-a',
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

final class SetupWorkspaceActionDependencyLinkShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        if (str_contains($script, 'ORBIT_WORKSPACE_DEPENDENCY_LINKS')) {
            return new RemoteShellResult(exitCode: 0, stdout: "ORBIT_WORKSPACE_DEPENDENCY_LINKS\nvendor=linked\nnode_modules=linked\n", stderr: '', durationMs: 1);
        }

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

final class SetupWorkspaceActionTestCertificateInstaller implements SiteCertificateInstaller
{
    /**
     * @var list<string>
     */
    public array $hosts = [];

    public function ensureFor(Node $node, string $host): array
    {
        $this->hosts[] = $host;

        return $this->expectedPathsFor($node, $host);
    }

    public function expectedPathsFor(Node $node, string $host): array
    {
        return [
            'cert' => "/home/gateway/.config/orbit/certs/{$host}.crt",
            'key' => "/home/gateway/.config/orbit/certs/{$host}.key",
        ];
    }
}
