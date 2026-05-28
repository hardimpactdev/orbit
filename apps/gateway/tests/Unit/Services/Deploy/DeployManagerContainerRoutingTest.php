<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppRuntimeKind;
use App\Http\Gateway\GatewayApiException;
use App\Models\App;
use App\Models\DeploymentRun;
use App\Models\DeployStep;
use App\Models\Node;
use App\Services\Deploy\DeployManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

final class DeployManagerRecordingShell implements RemoteShell
{
    public array $runs = [];

    public array $results = [];

    public bool $containerRunning = true;

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->runs[] = compact('node', 'script', 'options');

        if ($this->results !== []) {
            return array_shift($this->results);
        }

        // Auto-detect container preflight and return configured state so
        // tests do not need to manually seed every preflight result.
        if (str_starts_with($script, 'docker container inspect --format')) {
            return new RemoteShellResult(
                exitCode: $this->containerRunning ? 0 : 1,
                stdout: $this->containerRunning ? "true\n" : "false\n",
                stderr: '',
                durationMs: 25,
            );
        }

        return new RemoteShellResult(
            exitCode: 0,
            stdout: '',
            stderr: '',
            durationMs: 25,
        );
    }
}

function createDeployManagerTestApp(array $overrides = []): App
{
    $node = Node::factory()->create([
        'name' => 'app-prod-1',

    ]);

    return App::factory()->create(array_merge([
        'name' => 'docs',
        'node_id' => $node->id,
        'path' => '/srv/docs',
        'runtime_kind' => AppRuntimeKind::Php,
    ], $overrides));
}

function createDeployManagerTestStep(App $app, string $command, string $title = 'Test step'): DeployStep
{
    return DeployStep::query()->create([
        'app_id' => $app->id,
        'title' => $title,
        'command' => $command,
        'sort_order' => 1,
        'timeout_seconds' => 120,
    ]);
}

it('routes php commands through the app container for php apps', function (): void {
    $app = createDeployManagerTestApp();
    createDeployManagerTestStep($app, 'php artisan migrate --force');

    $shell = new DeployManagerRecordingShell;

    app()->instance(RemoteShell::class, $shell);

    $manager = app(DeployManager::class);
    $manager->run('docs');

    // preflight (routeCommand) + step + preflight (warmup) + composer optimize + artisan optimize
    expect($shell->runs)->toHaveCount(5)
        ->and($shell->runs[1]['script'])->toContain("'docker'")
        ->and($shell->runs[1]['script'])->toContain("'exec'")
        ->and($shell->runs[1]['script'])->toContain("'orbit-app-docs'")
        ->and($shell->runs[1]['script'])->toContain("'php artisan migrate --force'");
});

it('routes composer commands through the app container for php apps', function (): void {
    $app = createDeployManagerTestApp();
    createDeployManagerTestStep($app, 'composer install --no-interaction');

    $shell = new DeployManagerRecordingShell;

    app()->instance(RemoteShell::class, $shell);

    $manager = app(DeployManager::class);
    $manager->run('docs');

    expect($shell->runs[1]['script'])->toContain("'docker'")
        ->and($shell->runs[1]['script'])->toContain("'exec'")
        ->and($shell->runs[1]['script'])->toContain("'orbit-app-docs'")
        ->and($shell->runs[1]['script'])->toContain("'composer install --no-interaction'");
});

it('routes artisan commands through the app container for php apps', function (): void {
    $app = createDeployManagerTestApp();
    createDeployManagerTestStep($app, 'php artisan optimize');

    $shell = new DeployManagerRecordingShell;

    app()->instance(RemoteShell::class, $shell);

    $manager = app(DeployManager::class);
    $manager->run('docs');

    expect($shell->runs[1]['script'])->toContain("'docker'")
        ->and($shell->runs[1]['script'])->toContain("'exec'")
        ->and($shell->runs[1]['script'])->toContain("'orbit-app-docs'")
        ->and($shell->runs[1]['script'])->toContain("'php artisan optimize'");
});

it('runs non-php commands on the host for php apps', function (): void {
    $app = createDeployManagerTestApp();
    createDeployManagerTestStep($app, 'git pull origin main');

    $shell = new DeployManagerRecordingShell;
    app()->instance(RemoteShell::class, $shell);

    $manager = app(DeployManager::class);
    $manager->run('docs');

    expect($shell->runs[0]['script'])->not->toContain('docker exec')
        ->and($shell->runs[0]['script'])->toBe('git pull origin main')
        ->and($shell->runs[0]['options']['cwd'])->toBe('/srv/docs');
});

it('runs all commands on the host for static apps', function (): void {
    $app = createDeployManagerTestApp(['runtime_kind' => AppRuntimeKind::Static]);
    createDeployManagerTestStep($app, 'php artisan migrate --force');

    $shell = new DeployManagerRecordingShell;
    app()->instance(RemoteShell::class, $shell);

    $manager = app(DeployManager::class);
    $manager->run('docs');

    expect($shell->runs[0]['script'])->not->toContain('docker exec')
        ->and($shell->runs[0]['script'])->toBe('php artisan migrate --force');
});

it('transforms host paths to container paths when routing through container', function (): void {
    $app = createDeployManagerTestApp();
    createDeployManagerTestStep($app, 'cd "{{ app_path }}" && php artisan migrate');

    $shell = new DeployManagerRecordingShell;

    app()->instance(RemoteShell::class, $shell);

    $manager = app(DeployManager::class);
    $manager->run('docs');

    $script = $shell->runs[1]['script'];
    expect($script)->toContain("'docker'")
        ->and($script)->toContain("'exec'")
        ->and($script)->toContain("'cd \"/app\" && php artisan migrate'")
        ->and($script)->toContain("'bash'")
        ->and($script)->toContain("'-lc'");
});

it('passes deploy environment variables to the container', function (): void {
    $app = createDeployManagerTestApp();
    createDeployManagerTestStep($app, 'php artisan migrate');

    $shell = new DeployManagerRecordingShell;

    app()->instance(RemoteShell::class, $shell);

    $manager = app(DeployManager::class);
    $manager->run('docs');

    $script = $shell->runs[1]['script'];
    expect($script)->toContain("'-e'")
        ->and($script)->toContain('ORBIT_DEPLOY_APP_NAME');
});

it('sets container workdir to app source mount', function (): void {
    $app = createDeployManagerTestApp();
    createDeployManagerTestStep($app, 'php artisan migrate');

    $shell = new DeployManagerRecordingShell;

    app()->instance(RemoteShell::class, $shell);

    $manager = app(DeployManager::class);
    $manager->run('docs');

    expect($shell->runs[1]['script'])->toContain("'--workdir'");
    expect($shell->runs[1]['script'])->toContain("'/app'");
});

it('does not route php-fpm systemctl commands through container', function (): void {
    $app = createDeployManagerTestApp();
    createDeployManagerTestStep($app, 'sudo systemctl reload php8.5-fpm');

    $shell = new DeployManagerRecordingShell;
    app()->instance(RemoteShell::class, $shell);

    $manager = app(DeployManager::class);
    $manager->run('docs');

    expect($shell->runs[0]['script'])->not->toContain('docker exec')
        ->and($shell->runs[0]['script'])->toBe('sudo systemctl reload php8.5-fpm');
});

it('runs built-in warmup steps after user steps for php apps', function (): void {
    $app = createDeployManagerTestApp();
    createDeployManagerTestStep($app, 'git pull origin main');

    $shell = new DeployManagerRecordingShell;

    app()->instance(RemoteShell::class, $shell);

    $manager = app(DeployManager::class);
    $manager->run('docs');

    expect($shell->runs)->toHaveCount(4)
        ->and($shell->runs[2]['script'])->toContain('composer install --no-dev --optimize-autoloader')
        ->and($shell->runs[3]['script'])->toContain('php artisan optimize')
        ->and($shell->runs[2]['script'])->toContain("'docker'")
        ->and($shell->runs[3]['script'])->toContain("'docker'");
});

it('skips warmup steps when a user step fails', function (): void {
    $app = createDeployManagerTestApp();
    createDeployManagerTestStep($app, 'git pull origin main');

    $shell = new DeployManagerRecordingShell;
    $shell->results = [
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'fail', durationMs: 25),
    ];
    app()->instance(RemoteShell::class, $shell);

    $manager = app(DeployManager::class);

    try {
        $manager->run('docs');
    } catch (GatewayApiException) {
        // Expected
    }

    expect($shell->runs)->toHaveCount(1)
        ->and($shell->runs[0]['script'])->toBe('git pull origin main');
});

it('does not run warmup steps for static apps', function (): void {
    $app = createDeployManagerTestApp(['runtime_kind' => AppRuntimeKind::Static]);
    createDeployManagerTestStep($app, 'git pull origin main');

    $shell = new DeployManagerRecordingShell;
    app()->instance(RemoteShell::class, $shell);

    $manager = app(DeployManager::class);
    $manager->run('docs');

    expect($shell->runs)->toHaveCount(1)
        ->and($shell->runs[0]['script'])->toBe('git pull origin main');
});

it('runs http warmup when deploy_warmup_paths is configured', function (): void {
    $app = createDeployManagerTestApp(['deploy_warmup_paths' => ['/api/health', '/']]);
    createDeployManagerTestStep($app, 'git pull origin main');

    $shell = new DeployManagerRecordingShell;

    app()->instance(RemoteShell::class, $shell);

    $manager = app(DeployManager::class);
    $manager->run('docs');

    // Preflight + user step + composer optimize + artisan optimize + 2 HTTP warmups
    expect($shell->runs)->toHaveCount(6)
        ->and($shell->runs[4]['script'])->toContain('curl')
        ->and($shell->runs[4]['script'])->toContain('/api/health')
        ->and($shell->runs[5]['script'])->toContain('curl')
        ->and($shell->runs[5]['script'])->toContain('/');
});

it('skips http warmup when deploy_warmup_paths is empty', function (): void {
    $app = createDeployManagerTestApp(['deploy_warmup_paths' => []]);
    createDeployManagerTestStep($app, 'git pull origin main');

    $shell = new DeployManagerRecordingShell;

    app()->instance(RemoteShell::class, $shell);

    $manager = app(DeployManager::class);
    $manager->run('docs');

    // Preflight + user step + composer optimize + artisan optimize, no HTTP warmups
    expect($shell->runs)->toHaveCount(4);
});

it('passes env vars as separate docker exec argv tokens', function (): void {
    $app = createDeployManagerTestApp();
    createDeployManagerTestStep($app, 'php artisan migrate');

    $shell = new DeployManagerRecordingShell;
    app()->instance(RemoteShell::class, $shell);

    $manager = app(DeployManager::class);
    $manager->run('docs');

    $script = $shell->runs[1]['script'];

    // Reconstruct argv by shell-splitting the rendered command line
    $argv = preg_split('/\s+(?=(?:[^\'"]*[\'"][^\'"]*[\'"])*[^\'"]*$)/', $script);

    expect($argv)->toContain("'-e'")
        ->and($argv)->toContain("'ORBIT_DEPLOY_APP_NAME=docs'")
        ->and($script)->not->toMatch("/'-e '.*ORBIT_DEPLOY/");
});

it('falls back to host execution when php runtime container is not running', function (): void {
    $app = createDeployManagerTestApp();
    createDeployManagerTestStep($app, 'php artisan migrate --force');

    $shell = new DeployManagerRecordingShell;
    $shell->containerRunning = false;
    app()->instance(RemoteShell::class, $shell);

    $manager = app(DeployManager::class);
    $manager->run('docs');

    // Preflight (routeCommand) + user step (host) + preflight (warmup skip)
    expect($shell->runs)->toHaveCount(3)
        ->and($shell->runs[1]['script'])->toBe('php artisan migrate --force')
        ->and($shell->runs[1]['script'])->not->toContain('docker exec');
});

it('falls back to host execution when php runtime container is missing for user step', function (): void {
    $app = createDeployManagerTestApp();
    createDeployManagerTestStep($app, 'php artisan migrate --force');

    $shell = new DeployManagerRecordingShell;
    $shell->containerRunning = false;
    app()->instance(RemoteShell::class, $shell);

    $manager = app(DeployManager::class);
    $manager->run('docs');

    // Preflight (routeCommand) + user step (host) + preflight (warmup skip)
    expect($shell->runs)->toHaveCount(3)
        ->and($shell->runs[1]['script'])->toBe('php artisan migrate --force')
        ->and($shell->runs[1]['script'])->not->toContain('docker exec');
});

it('marks run failed when built-in warmup step fails', function (): void {
    $app = createDeployManagerTestApp();
    createDeployManagerTestStep($app, 'git pull origin main');

    $shell = new DeployManagerRecordingShell;
    $shell->results = [
        // User step succeeds (no preflight needed since git is not a PHP tool)
        new RemoteShellResult(exitCode: 0, stdout: "ok\n", stderr: '', durationMs: 25),
        // runWarmupSteps preflight: container is running
        new RemoteShellResult(exitCode: 0, stdout: "true\n", stderr: '', durationMs: 25),
        // composer install succeeds
        new RemoteShellResult(exitCode: 0, stdout: "composer ok\n", stderr: '', durationMs: 25),
        // php artisan optimize fails
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'optimize failed', durationMs: 25),
    ];
    app()->instance(RemoteShell::class, $shell);

    $manager = app(DeployManager::class);

    try {
        $manager->run('docs');
        $this->fail('Expected GatewayApiException');
    } catch (GatewayApiException $e) {
        expect($e->errorCode())->toBe('deploy.warmup_failed')
            ->and($e->errorMeta()['warmup_command'])->toBe('php artisan optimize');
    }

    $run = DeploymentRun::query()->sole();
    expect($run->status)->toBe('failed')
        ->and($run->exit_code)->toBe(1);
});
