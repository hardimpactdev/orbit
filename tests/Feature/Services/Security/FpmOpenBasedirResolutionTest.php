<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Workspace;
use App\Services\Apps\AppFpmPoolRenderer;
use App\Services\Php\PhpFpmSystemdHardening;
use App\Services\Workspaces\WorkspaceFpmPoolRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders production app pools with filesystem and function isolation', function (): void {
    $node = createTestAppHostNode(role: 'app-production');
    $app = App::factory()
        ->for($node, 'node')
        ->create([
            'name' => 'docs',
            'environment' => 'production',
            'path' => '/home/orbit/apps/docs',
        ]);

    $renderer = new AppFpmPoolRenderer;
    $content = $renderer->content($app);

    expect($renderer->runtimeUser($app))->toStartWith('orbit-docs-')
        ->and($content)->toContain('user = '.$renderer->runtimeUser($app))
        ->and($content)->toContain('group = '.$renderer->runtimeUser($app))
        ->and($content)->toContain('chdir = /home/orbit/apps/docs')
        ->and($content)->toContain('clear_env = yes')
        ->and($content)->toContain('php_admin_value[open_basedir] = /home/orbit/apps/docs:/home/orbit/apps/docs/storage:/home/orbit/apps/docs/bootstrap/cache:/home/orbit/apps/docs/public/uploads:/home/orbit/apps/docs/vendor:/tmp')
        ->and($content)->toContain('php_admin_value[disable_functions] = exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec')
        ->and($renderer->openBasedir($app))->not->toContain('/home/orbit/.composer');
});

it('renders workspace pools with per-workspace users and constrained filesystem access', function (): void {
    $app = App::factory()
        ->for(createTestAppHostNode(), 'node')
        ->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
        ]);
    $workspace = Workspace::factory()
        ->for($app, 'app')
        ->create([
            'name' => 'feature',
            'path' => '/home/orbit/apps/docs/.worktrees/feature',
        ]);

    $renderer = new WorkspaceFpmPoolRenderer;
    $content = $renderer->content($workspace);
    $runtimeUser = $renderer->runtimeUser($workspace);

    expect($runtimeUser)->toStartWith('orbit-ws-docs-feature')
        ->and(strlen($runtimeUser))->toBeLessThanOrEqual(32)
        ->and($content)->toContain("user = {$runtimeUser}")
        ->and($content)->toContain("group = {$runtimeUser}")
        ->and($content)->toContain('chdir = /home/orbit/apps/docs/.worktrees/feature')
        ->and($content)->toContain('clear_env = yes')
        ->and($content)->toContain('php_admin_value[open_basedir] = /home/orbit/apps/docs/.worktrees/feature:/home/orbit/apps/docs/.worktrees/feature/storage:/home/orbit/apps/docs/.worktrees/feature/bootstrap/cache:/home/orbit/apps/docs/.worktrees/feature/public/uploads:/home/orbit/apps/docs/.worktrees/feature/vendor:/tmp')
        ->and($renderer->openBasedir($workspace))->not->toContain('/home/orbit/.composer');
});

it('renders the PHP-FPM systemd hardening drop-in with explicit writable paths', function (): void {
    $hardening = new PhpFpmSystemdHardening;
    $content = $hardening->content([
        '/home/orbit/apps/docs',
        '/home/orbit/apps/docs/storage',
    ]);

    expect($hardening->path('8.5'))->toBe('/etc/systemd/system/php8.5-fpm.service.d/10-orbit-hardening.conf')
        ->and($content)->toContain('NoNewPrivileges=true')
        ->and($content)->toContain('PrivateTmp=true')
        ->and($content)->toContain('ProtectSystem=strict')
        ->and($content)->toContain('RestrictSUIDSGID=true')
        ->and($content)->toContain('ReadWritePaths=')
        ->and($content)->toContain('/home/orbit/apps/docs')
        ->and($content)->toContain('/home/orbit/apps/docs/storage')
        ->and($content)->toContain('/var/lib/php/sessions');
});

it('aggregates PHP-FPM writable paths across apps and workspaces on the same node', function (): void {
    $node = createTestAppHostNode();
    $firstApp = App::factory()
        ->for($node, 'node')
        ->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
            'php_version' => '8.5',
        ]);
    App::factory()
        ->for($node, 'node')
        ->create([
            'name' => 'site',
            'path' => '/home/orbit/apps/site',
            'php_version' => '8.5',
        ]);
    App::factory()
        ->for($node, 'node')
        ->create([
            'name' => 'old',
            'path' => '/home/orbit/apps/old',
            'php_version' => '8.4',
        ]);
    Workspace::factory()
        ->for($firstApp, 'app')
        ->create([
            'name' => 'feature',
            'path' => '/home/orbit/apps/docs/.worktrees/feature',
        ]);

    $content = (new PhpFpmSystemdHardening)->contentForNode($node, '8.5');

    expect($content)->toContain('/home/orbit/apps/docs')
        ->and($content)->toContain('/home/orbit/apps/site')
        ->and($content)->toContain('/home/orbit/apps/docs/.worktrees/feature')
        ->and($content)->not->toContain('/home/orbit/apps/old');
});
