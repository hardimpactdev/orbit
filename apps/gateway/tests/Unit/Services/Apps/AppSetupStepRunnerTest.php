<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\AppSetupRun;
use App\Models\AppSetupStep;
use App\Models\Instance;
use App\Models\Node;
use App\Services\Apps\AppCommandRouter;
use App\Services\Apps\AppSetupStepRunner;
use App\Services\RemoteShell\RunsInternalCommands;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orbit\Core\Http\JsonEnvelope;
use Tests\Fakes\WorkspaceSetupStepRunnerExecutorTransport;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

afterEach(function (): void {});

function allow_app_setup_remote_shell_fallback(): void {}

final class AppSetupStepRunnerTestShell implements RemoteShell, RunsInternalCommands
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

    public function runInternal(
        Node $node,
        string $commandName,
        array $arguments = [],
        array $commandOptions = [],
        array $transportOptions = [],
    ): RemoteShellResult {
        $payload = json_decode((string) $transportOptions['input'], true, flags: JSON_THROW_ON_ERROR);
        $result = $this->run($node, (string) $payload['command'], [
            'cwd' => $payload['cwd'],
            'timeout' => $payload['timeout'],
            'environment' => $payload['environment'],
        ]);

        return new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode([
                'success' => ['data' => [
                    'exit_code' => $result->exitCode,
                    'stdout' => $result->stdout,
                    'stderr' => $result->stderr,
                    'duration_ms' => $result->durationMs,
                ]],
            ], JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: $result->durationMs,
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

function appSetupRunnerInstance(App $app): Instance
{
    $instance = Instance::query()->where('app_id', $app->id)->first();

    if ($instance instanceof Instance) {
        return $instance;
    }

    return Instance::factory()->for($app)->create([
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $app->node_id,
            node: $app->node?->name,
            path: $app->path,
            document_root: $app->document_root,
        ),
    ]);
}

it('runs app setup steps sequentially in the app path', function (): void {
    allow_app_setup_remote_shell_fallback();
    $app = createAppSetupRunnerTestApp();
    $run = AppSetupRun::factory()->create([
        'instance_id' => appSetupRunnerInstance($app)->id,
        'status' => 'pending',
    ]);
    $shell = new AppSetupStepRunnerTestShell;
    $runner = new AppSetupStepRunner(app(AppCommandRouter::class), $shell);

    $steps = [
        AppSetupStep::factory()->create([
            'instance_id' => appSetupRunnerInstance($app)->id,
            'command' => 'npm install',
            'sort_order' => 1,
        ]),
        AppSetupStep::factory()->create([
            'instance_id' => appSetupRunnerInstance($app)->id,
            'command' => 'npm run build',
            'sort_order' => 2,
        ]),
    ];

    $result = $runner->run($run, $steps, $app, $app->node, ['ORBIT_APP' => 'docs']);

    expect($result)
        ->toBeTrue()
        ->and($shell->runs)
        ->toHaveCount(2)
        ->and($shell->runs[0]['script'])
        ->toContain("'sudo'")
        ->toContain('/home/orbit/.local/bin')
        ->toContain('/home/orbit/.vite-plus/bin')
        ->toContain('npm install')
        ->and($shell->runs[0]['options']['cwd'])
        ->toBe('/home/orbit/apps/docs')
        ->and($shell->runs[1]['script'])
        ->toContain("'sudo'")
        ->toContain('/home/orbit/.local/bin')
        ->toContain('/home/orbit/.vite-plus/bin')
        ->toContain('npm run build')
        ->and($shell->runs[1]['options']['cwd'])
        ->toBe('/home/orbit/apps/docs');

    $run->refresh();
    expect($run->status)->toBe('completed');
});

it('routes php and composer setup steps through the app host php toolchain', function (): void {
    allow_app_setup_remote_shell_fallback();
    $app = createAppSetupRunnerTestApp();
    $run = AppSetupRun::factory()->create([
        'instance_id' => appSetupRunnerInstance($app)->id,
        'status' => 'pending',
    ]);
    $shell = new AppSetupStepRunnerTestShell;
    $runner = new AppSetupStepRunner(app(AppCommandRouter::class), $shell);

    $steps = [
        AppSetupStep::factory()->create([
            'instance_id' => appSetupRunnerInstance($app)->id,
            'command' => 'composer install',
            'sort_order' => 1,
        ]),
        AppSetupStep::factory()->create([
            'instance_id' => appSetupRunnerInstance($app)->id,
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
    $run = AppSetupRun::factory()->create([
        'instance_id' => appSetupRunnerInstance($app)->id,
        'status' => 'pending',
    ]);
    $shell = new AppSetupStepRunnerTestShell;
    $shell->results = [
        new RemoteShellResult(exitCode: 1, stdout: 'failed', stderr: 'boom', durationMs: 25),
    ];
    $runner = new AppSetupStepRunner(app(AppCommandRouter::class), $shell);

    $steps = [
        AppSetupStep::factory()->create([
            'instance_id' => appSetupRunnerInstance($app)->id,
            'command' => 'exit 1',
            'sort_order' => 1,
        ]),
        AppSetupStep::factory()->create([
            'instance_id' => appSetupRunnerInstance($app)->id,
            'command' => 'echo skipped',
            'sort_order' => 2,
        ]),
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

function app_setup_step_runner_local_executor(
    WorkspaceSetupStepRunnerExecutorTransport $transport,
): RunsInternalCommands {
    return $transport;
}

function app_setup_step_runner_signing_key(): string
{
    return hash('sha256', AppSetupStepRunner::class);
}

it('runs setup steps through the local executor by default for agent capable nodes', function (): void {
    $app = createAppSetupRunnerTestApp();
    $node = Node::factory()
        ->appDev()
        ->managed()
        ->create([
            'name' => 'agent-node',
            'host' => 'agent-node',
        ]);
    $app->setRelation('node', $node);
    $run = AppSetupRun::factory()->create([
        'instance_id' => appSetupRunnerInstance($app)->id,
        'status' => 'pending',
    ]);
    $shell = new AppSetupStepRunnerTestShell;
    $transport = new WorkspaceSetupStepRunnerExecutorTransport(new RemoteShellResult(
        exitCode: 0,
        stdout: json_encode(JsonEnvelope::success([
            'exit_code' => 0,
            'stdout' => "installed\n",
            'stderr' => '',
            'duration_ms' => 12,
        ]), JSON_THROW_ON_ERROR),
        stderr: '',
        durationMs: 14,
    ));

    $runner = new AppSetupStepRunner(
        commandRouter: app(AppCommandRouter::class),
        localExecutor: app_setup_step_runner_local_executor($transport),
    );

    $steps = [
        AppSetupStep::factory()->create([
            'instance_id' => appSetupRunnerInstance($app)->id,
            'command' => 'npm install',
            'sort_order' => 1,
        ]),
    ];

    $result = $runner->run($run, $steps, $app, $node, ['ORBIT_APP' => 'docs']);

    $runStep = $run->runSteps()->first();

    expect($result)
        ->toBeTrue()
        ->and($shell->runs)
        ->toBeEmpty()
        ->and($transport->runs)
        ->toHaveCount(1)
        ->and($transport->runs[0]['script'])
        ->toContain('internal:app-setup-step')
        ->and($runStep?->exit_code)
        ->toBe(0)
        ->and($runStep?->output)
        ->toBe("installed\n");
});

it('routes php setup commands before dispatching through the local executor', function (): void {
    $app = createAppSetupRunnerTestApp();
    $node = Node::factory()
        ->appDev()
        ->managed()
        ->create([
            'name' => 'agent-node',
            'host' => 'agent-node',
            'user' => 'orbit',
        ]);
    $app->setRelation('node', $node);
    $run = AppSetupRun::factory()->create([
        'instance_id' => appSetupRunnerInstance($app)->id,
        'status' => 'pending',
    ]);
    $shell = new AppSetupStepRunnerTestShell;
    $transport = new WorkspaceSetupStepRunnerExecutorTransport(new RemoteShellResult(
        exitCode: 0,
        stdout: json_encode(JsonEnvelope::success([
            'exit_code' => 0,
            'stdout' => "installed\n",
            'stderr' => '',
            'duration_ms' => 12,
        ]), JSON_THROW_ON_ERROR),
        stderr: '',
        durationMs: 14,
    ));

    $runner = new AppSetupStepRunner(
        commandRouter: app(AppCommandRouter::class),
        localExecutor: app_setup_step_runner_local_executor($transport),
    );

    $steps = [
        AppSetupStep::factory()->create([
            'instance_id' => appSetupRunnerInstance($app)->id,
            'command' => 'composer install',
            'sort_order' => 1,
        ]),
    ];

    $result = $runner->run($run, $steps, $app, $node, ['ORBIT_APP' => 'docs']);

    $payload = json_decode(
        (string) $transport->runs[0]['options']['input'],
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($result)
        ->toBeTrue()
        ->and($payload['command'])
        ->toContain("'sudo'")
        ->toContain('/opt/orbit/php/')
        ->toContain('/home/orbit/.local/bin')
        ->toContain('/home/orbit/.vite-plus/bin')
        ->toContain('composer install')
        ->and($payload['cwd'])
        ->toBe('/home/orbit/apps/docs');
});

it('fails fast on a non-zero local executor setup step and records output', function (): void {
    $app = createAppSetupRunnerTestApp();
    $node = Node::factory()
        ->appDev()
        ->managed()
        ->create([
            'name' => 'agent-node',
            'host' => 'agent-node',
            'user' => 'orbit',
        ]);
    $app->setRelation('node', $node);
    $run = AppSetupRun::factory()->create([
        'instance_id' => appSetupRunnerInstance($app)->id,
        'status' => 'pending',
    ]);
    $shell = new AppSetupStepRunnerTestShell;
    $transport = new WorkspaceSetupStepRunnerExecutorTransport(
        new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode(JsonEnvelope::success([
                'exit_code' => 7,
                'stdout' => 'failed',
                'stderr' => 'boom',
                'duration_ms' => 12,
            ]), JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: 14,
        ),
        new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode(JsonEnvelope::success([
                'exit_code' => 0,
                'stdout' => 'skipped',
                'stderr' => '',
                'duration_ms' => 12,
            ]), JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: 14,
        ),
    );

    $runner = new AppSetupStepRunner(
        commandRouter: app(AppCommandRouter::class),
        localExecutor: app_setup_step_runner_local_executor($transport),
    );

    $steps = [
        AppSetupStep::factory()->create([
            'instance_id' => appSetupRunnerInstance($app)->id,
            'command' => 'exit 7',
            'sort_order' => 1,
        ]),
        AppSetupStep::factory()->create([
            'instance_id' => appSetupRunnerInstance($app)->id,
            'command' => 'echo skipped',
            'sort_order' => 2,
        ]),
    ];

    $result = $runner->run($run, $steps, $app, $node, []);

    $run->refresh();
    $runStep = $run->runSteps()->first();

    expect($result)
        ->toBeFalse()
        ->and($shell->runs)
        ->toBeEmpty()
        ->and($transport->runs)
        ->toHaveCount(1)
        ->and($run->status)
        ->toBe('failed')
        ->and($runStep?->exit_code)
        ->toBe(7)
        ->and($runStep?->output)
        ->toContain('failed')
        ->and($runStep?->output)
        ->toContain('boom');
});

it('does not place setup environment values in transport metadata for agent-push dispatch', function (): void {
    $app = createAppSetupRunnerTestApp();
    $node = Node::factory()
        ->appDev()
        ->managed()
        ->create([
            'name' => 'agent-node',
            'host' => 'agent-node',
        ]);
    $app->setRelation('node', $node);
    $run = AppSetupRun::factory()->create([
        'instance_id' => appSetupRunnerInstance($app)->id,
        'status' => 'pending',
    ]);
    $shell = new AppSetupStepRunnerTestShell;
    $transport = new WorkspaceSetupStepRunnerExecutorTransport(new RemoteShellResult(
        exitCode: 0,
        stdout: json_encode(JsonEnvelope::success([
            'exit_code' => 0,
            'stdout' => "ok\n",
            'stderr' => '',
            'duration_ms' => 12,
        ]), JSON_THROW_ON_ERROR),
        stderr: '',
        durationMs: 14,
    ));

    $runner = new AppSetupStepRunner(
        commandRouter: app(AppCommandRouter::class),
        localExecutor: app_setup_step_runner_local_executor($transport),
    );

    $steps = [
        AppSetupStep::factory()->create([
            'instance_id' => appSetupRunnerInstance($app)->id,
            'command' => 'npm install',
            'sort_order' => 1,
        ]),
    ];

    $environment = [
        'ORBIT_APP' => 'docs',
        'VITE_APP_URL' => 'https://docs.test',
    ];

    $runner->run($run, $steps, $app, $node, $environment);

    $metadata = $transport->runs[0]['options']['metadata'] ?? [];
    $payload = json_decode(
        (string) ($transport->runs[0]['options']['input'] ?? ''),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($metadata)
        ->toBe(['ORBIT_OPERATION_ID' => 'app-setup-step'])
        ->and($payload['environment']['VITE_APP_URL'] ?? null)
        ->toBe('https://docs.test')
        ->and($transport->runs[0]['script'])
        ->not->toContain('https://docs.test')->and($transport->runs[0]['script'])
        ->not->toContain(app_setup_step_runner_signing_key());
});
