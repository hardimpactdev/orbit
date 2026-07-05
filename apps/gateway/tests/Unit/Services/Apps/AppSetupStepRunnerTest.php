<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\AppSetupRun;
use App\Models\AppSetupStep;
use App\Models\Node;
use App\Services\Apps\AppCommandRouter;
use App\Services\Apps\AppSetupStepRunner;
use App\Services\RemoteShell\ExplicitRemoteShellFallback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

afterEach(function (): void {
    request()->headers->remove(ExplicitRemoteShellFallback::HEADER);
});

function allow_app_setup_remote_shell_fallback(): void
{
    request()->headers->set(ExplicitRemoteShellFallback::HEADER, ExplicitRemoteShellFallback::REQUIRED);
}

final class AppSetupStepRunnerTestShell implements RemoteShell
{
    public array $runs = [];

    public array $results = [];

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->runs[] = compact('node', 'script', 'options');

        if ($this->results !== []) {
            return array_shift($this->results);
        }

        return new RemoteShellResult(
            exitCode: 0,
            stdout: '',
            stderr: '',
            durationMs: 25,
        );
    }
}

function createAppSetupRunnerTestApp(array $overrides = []): App
{
    $node = Node::factory()
        ->appDev()
        ->create([
            'name' => 'app-1',
            'user' => 'orbit',
        ]);

    return App::factory()->create(array_merge([
        'name' => 'docs',
        'node_id' => $node->id,
        'path' => '/home/orbit/apps/docs',
        'php_version' => '8.5',
    ], $overrides));
}

it('runs app setup steps sequentially in the app path', function (): void {
    allow_app_setup_remote_shell_fallback();
    $app = createAppSetupRunnerTestApp();
    $run = AppSetupRun::factory()->create(['app_id' => $app->id, 'status' => 'pending']);
    $shell = new AppSetupStepRunnerTestShell;
    $runner = new AppSetupStepRunner($shell, app(AppCommandRouter::class));

    $steps = [
        AppSetupStep::factory()->create(['app_id' => $app->id, 'command' => 'npm install', 'sort_order' => 1]),
        AppSetupStep::factory()->create(['app_id' => $app->id, 'command' => 'npm run build', 'sort_order' => 2]),
    ];

    $result = $runner->run($run, $steps, $app, $app->node, ['ORBIT_APP' => 'docs']);

    expect($result)
        ->toBeTrue()
        ->and($shell->runs)
        ->toHaveCount(2)
        ->and($shell->runs[0]['script'])
        ->toBe('npm install')
        ->and($shell->runs[0]['options']['cwd'])
        ->toBe('/home/orbit/apps/docs')
        ->and($shell->runs[1]['script'])
        ->toBe('npm run build')
        ->and($shell->runs[1]['options']['cwd'])
        ->toBe('/home/orbit/apps/docs');

    $run->refresh();
    expect($run->status)->toBe('completed');
});

it('routes php and composer setup steps through the app host php toolchain', function (): void {
    allow_app_setup_remote_shell_fallback();
    $app = createAppSetupRunnerTestApp();
    $run = AppSetupRun::factory()->create(['app_id' => $app->id, 'status' => 'pending']);
    $shell = new AppSetupStepRunnerTestShell;
    $runner = new AppSetupStepRunner($shell, app(AppCommandRouter::class));

    $steps = [
        AppSetupStep::factory()->create(['app_id' => $app->id, 'command' => 'composer install', 'sort_order' => 1]),
        AppSetupStep::factory()->create([
            'app_id' => $app->id,
            'command' => 'php artisan migrate --force',
            'sort_order' => 2,
        ]),
    ];

    $runner->run($run, $steps, $app, $app->node, ['ORBIT_APP' => 'docs']);

    expect($shell->runs[0]['script'])
        ->toContain("'sudo'")
        ->toContain('/opt/orbit/php/')
        ->toContain('composer install')
        ->and($shell->runs[1]['script'])
        ->toContain("'sudo'")
        ->toContain('/opt/orbit/php/')
        ->toContain('php artisan migrate --force');
});

it('fails fast on the first failed setup step and records output', function (): void {
    allow_app_setup_remote_shell_fallback();
    $app = createAppSetupRunnerTestApp();
    $run = AppSetupRun::factory()->create(['app_id' => $app->id, 'status' => 'pending']);
    $shell = new AppSetupStepRunnerTestShell;
    $shell->results = [
        new RemoteShellResult(exitCode: 1, stdout: 'failed', stderr: 'boom', durationMs: 25),
    ];
    $runner = new AppSetupStepRunner($shell, app(AppCommandRouter::class));

    $steps = [
        AppSetupStep::factory()->create(['app_id' => $app->id, 'command' => 'exit 1', 'sort_order' => 1]),
        AppSetupStep::factory()->create(['app_id' => $app->id, 'command' => 'echo skipped', 'sort_order' => 2]),
    ];

    $result = $runner->run($run, $steps, $app, $app->node, []);

    expect($result)->toBeFalse()->and($shell->runs)->toHaveCount(1);

    $run->refresh();
    $runStep = $run->runSteps()->first();

    expect($run->status)
        ->toBe('failed')
        ->and($runStep?->exit_code)
        ->toBe(1)
        ->and($runStep?->output)
        ->toContain('failed')
        ->and($runStep?->output)
        ->toContain('boom');
});

it('requires explicit transitional ssh fallback before running app setup commands', function (): void {
    $app = createAppSetupRunnerTestApp();
    $run = AppSetupRun::factory()->create(['app_id' => $app->id, 'status' => 'pending']);
    $shell = new AppSetupStepRunnerTestShell;
    $runner = new AppSetupStepRunner($shell, app(AppCommandRouter::class));

    $steps = [
        AppSetupStep::factory()->create(['app_id' => $app->id, 'command' => 'npm install', 'sort_order' => 1]),
    ];

    $result = $runner->run($run, $steps, $app, $app->node, ['ORBIT_APP' => 'docs']);

    $run->refresh();
    $runStep = $run->runSteps()->first();

    expect($result)
        ->toBeFalse()
        ->and($shell->runs)
        ->toBe([])
        ->and($run->status)
        ->toBe('failed')
        ->and($runStep?->exit_code)
        ->toBe(1)
        ->and($runStep?->output)
        ->toContain('requires explicit --node-transport=transitional-ssh-fallback');
});
