<?php

declare(strict_types=1);

use App\Contracts\AppRuntimeUserResolver;
use App\Contracts\WorkspaceRuntimeUserResolver;
use App\Enums\Apps\AppRuntimeKind;
use App\Enums\Processes\ProcessRuntime;
use App\Models\App;
use App\Models\Node;
use App\Models\Process as OrbitProcess;
use App\Models\Workspace;
use App\Services\Apps\AppRuntimeUser;
use App\Services\Processes\SupervisorProgramRenderer;
use App\Services\Workspaces\WorkspaceRuntimeUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('renders app supervisor programs as the resolved app runtime user in the app directory', function (): void {
    $node = supervisorRendererNode();
    $app = supervisorRendererApp($node, [
        'name' => 'docs',
        'environment' => 'production',
        'path' => '/home/docs/app',
    ]);
    $process = supervisorRendererProcess($app);

    $rendered = (new SupervisorProgramRenderer)->render($app, $process);
    $runtimeUser = (new AppRuntimeUser)->forApp($app);

    expect($runtimeUser)->not->toBe($node->user)
        ->and($rendered)->toContain('directory=/home/docs/app')
        ->and($rendered)->toContain("user={$runtimeUser}")
        ->and($rendered)->toContain("stdout_logfile=/home/{$runtimeUser}/.config/orbit/logs/orbit_docs_main_worker.log")
        ->and($rendered)->toContain("HOME=\"/home/{$runtimeUser}\"");
});

it('renders workspace supervisor programs as the resolved workspace runtime user in the workspace directory', function (): void {
    $node = supervisorRendererNode();
    $app = supervisorRendererApp($node, [
        'name' => 'docs',
        'path' => '/home/docs/app',
    ]);
    $workspace = Workspace::factory()->for($app, 'app')->create([
        'name' => 'feature',
        'path' => '/home/docs/workspaces/feature',
    ]);
    $process = supervisorRendererProcess($app);

    $rendered = (new SupervisorProgramRenderer)->render($app, $process, $workspace);
    $runtimeUser = (new WorkspaceRuntimeUser)->forWorkspace($workspace);

    expect($runtimeUser)->not->toBe($node->user)
        ->and($rendered)->toContain('directory=/home/docs/workspaces/feature')
        ->and($rendered)->toContain("user={$runtimeUser}")
        ->and($rendered)->toContain("stdout_logfile=/home/{$runtimeUser}/.config/orbit/logs/orbit_docs_feature_worker.log")
        ->and($rendered)->toContain("HOME=\"/home/{$runtimeUser}\"");
});

it('renders static app supervisor programs through the app runtime user resolver', function (): void {
    $node = supervisorRendererNode();
    $app = supervisorRendererApp($node, [
        'name' => 'marketing',
        'environment' => 'production',
        'path' => '/srv/marketing/current',
        'runtime_kind' => AppRuntimeKind::Static,
    ]);
    $process = supervisorRendererProcess($app, [
        'name' => 'watch',
        'command' => './watch.sh',
    ]);

    $rendered = (new SupervisorProgramRenderer)->render($app, $process);
    $runtimeUser = (new AppRuntimeUser)->forApp($app);

    expect($rendered)->toContain('directory=/srv/marketing/current')
        ->and($rendered)->toContain("user={$runtimeUser}")
        ->and($rendered)->toContain("HOME=\"/home/{$runtimeUser}\"");
});

it('fails explicitly when an app supervisor program has no source path', function (): void {
    $node = supervisorRendererNode();
    $app = supervisorRendererApp($node, [
        'name' => 'docs',
        'path' => '',
    ]);
    $process = supervisorRendererProcess($app);

    expect(fn (): string => (new SupervisorProgramRenderer)->render($app, $process))
        ->toThrow(InvalidArgumentException::class, "App 'docs' has no source path; cannot render Supervisor program.");
});

it('fails explicitly when a workspace supervisor program has no source path', function (): void {
    $node = supervisorRendererNode();
    $app = supervisorRendererApp($node, [
        'name' => 'docs',
        'path' => '/home/docs/app',
    ]);
    $workspace = Workspace::factory()->for($app, 'app')->create([
        'name' => 'feature',
        'path' => '',
    ]);
    $process = supervisorRendererProcess($app);

    expect(fn (): string => (new SupervisorProgramRenderer)->render($app, $process, $workspace))
        ->toThrow(InvalidArgumentException::class, "Workspace 'feature' has no source path; cannot render Supervisor program.");
});

it('fails explicitly when a runtime user resolver returns an empty user', function (): void {
    $node = supervisorRendererNode();
    $app = supervisorRendererApp($node, [
        'name' => 'docs',
        'path' => '/home/docs/app',
    ]);
    $process = supervisorRendererProcess($app);
    $renderer = new SupervisorProgramRenderer(
        appRuntimeUser: new EmptyAppRuntimeUserResolver,
    );

    expect(fn (): string => $renderer->render($app, $process))
        ->toThrow(InvalidArgumentException::class, "App 'docs' has no runtime user; cannot render Supervisor program.");
});

it('fails explicitly when a workspace runtime user resolver returns an empty user', function (): void {
    $node = supervisorRendererNode();
    $app = supervisorRendererApp($node, [
        'name' => 'docs',
        'path' => '/home/docs/app',
    ]);
    $workspace = Workspace::factory()->for($app, 'app')->create([
        'name' => 'feature',
        'path' => '/home/docs/workspaces/feature',
    ]);
    $process = supervisorRendererProcess($app);
    $renderer = new SupervisorProgramRenderer(
        workspaceRuntimeUser: new EmptyWorkspaceRuntimeUserResolver,
    );

    expect(fn (): string => $renderer->render($app, $process, $workspace))
        ->toThrow(InvalidArgumentException::class, "Workspace 'feature' has no runtime user; cannot render Supervisor program.");
});

function supervisorRendererNode(): Node
{
    return Node::factory()->create([
        'name' => 'app-1',
        'tld' => 'test',
        'status' => 'active',
        'user' => 'deployer',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function supervisorRendererApp(Node $node, array $overrides = []): App
{
    $app = App::factory()->for($node, 'node')->create([
        'name' => 'docs',
        'path' => '/home/docs/app',
        ...$overrides,
    ]);
    $app->setRelation('node', $node);

    return $app;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function supervisorRendererProcess(App $app, array $overrides = []): OrbitProcess
{
    return OrbitProcess::factory()->forOwner($app)->create([
        'name' => 'worker',
        'command' => 'php artisan queue:work',
        'restart_policy' => 'on_failure',
        'crash_notification' => 'none',
        'runtime' => ProcessRuntime::Supervisor,
        'sort_order' => 1,
        ...$overrides,
    ]);
}

final readonly class EmptyAppRuntimeUserResolver implements AppRuntimeUserResolver
{
    public function forApp(App $app): string
    {
        return '';
    }
}

final readonly class EmptyWorkspaceRuntimeUserResolver implements WorkspaceRuntimeUserResolver
{
    public function forWorkspace(Workspace $workspace): string
    {
        return '';
    }
}
