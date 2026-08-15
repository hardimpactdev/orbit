<?php

declare(strict_types=1);

use App\Contracts\ConvergesAppRuntimeContainers;
use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppRuntimeContainerApplyOutcome;
use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;
use App\Models\DeploymentRun;
use App\Models\DeployStep;
use App\Models\Instance;
use App\Models\Node;
use App\Services\Deploy\DeployManager;
use App\Services\RemoteShell\RemoteLocalExecutorTransportFailed;
use App\Services\RemoteShell\RunsInternalCommands;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orbit\Core\Enums\InternalCommand;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Sdk\Laravel\GatewayApiException;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/**
 * @mago-expect lint:cyclomatic-complexity
 */
final class DeployManagerRecordingShell implements RunsInternalCommands
{
    public array $runs = [];

    public array $results = [];

    public ?array $sourcePathProbe = null;

    public ?Throwable $failure = null;

    /**
     * @mago-expect lint:halstead
     */
    public function runInternal(
        Node $node,
        string $commandName,
        array $arguments = [],
        array $commandOptions = [],
        array $transportOptions = [],
    ): RemoteShellResult {
        if ($commandName === 'internal:app-source-path:probe') {
            $this->runs[] = [
                'node' => $node,
                'binary' => $commandName,
                'arguments' => $arguments,
                'script' => null,
                'options' => $commandOptions,
                'command_name' => $commandName,
                'transport_options' => $transportOptions,
            ];

            return new RemoteShellResult(
                exitCode: 0,
                stdout: json_encode(
                    JsonEnvelope::success(
                        $this->sourcePathProbe ?? [
                            'path' => $arguments[0] ?? null,
                            'exists' => false,
                            'resolved_path' => null,
                            'within_boundary' => false,
                        ],
                    ),
                    JSON_THROW_ON_ERROR,
                ),
                stderr: '',
                durationMs: 25,
            );
        }

        if ($commandName === 'internal:process-docker-container') {
            $payload = json_decode(
                (string) ($transportOptions['input'] ?? ''),
                associative: true,
                flags: JSON_THROW_ON_ERROR,
            );
            assert(is_array($payload), description: 'Process Docker payload must decode to an array.');
            $this->runs[] = [
                'node' => $node,
                'binary' => $commandName,
                'arguments' => [],
                'script' => null,
                'options' => $payload,
                'command_name' => $commandName,
                'transport_options' => $transportOptions,
            ];

            return new RemoteShellResult(
                exitCode: 0,
                stdout: json_encode(JsonEnvelope::success([
                    'action' => $payload['action'] ?? null,
                    'container' => $payload['container'] ?? null,
                ]), JSON_THROW_ON_ERROR),
                stderr: '',
                durationMs: 25,
            );
        }

        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }

        $payload = json_decode(
            (string) ($transportOptions['input'] ?? ''),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );
        assert(is_array($payload), description: 'Deploy command payload must decode to an array.');
        $argumentsPayload = $payload['arguments'] ?? [];
        assert(is_array($argumentsPayload), description: 'Deploy command arguments must be an array.');
        $result = $this->results !== []
            ? array_shift($this->results)
            : new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 25);

        if ($result instanceof RemoteLocalExecutorTransportFailed) {
            throw $result;
        }

        $this->runs[] = [
            'node' => $node,
            'binary' => $payload['binary'] ?? null,
            'arguments' => $argumentsPayload,
            'script' => $argumentsPayload[1] ?? null,
            'options' => [
                'cwd' => $payload['cwd'] ?? null,
                'timeout' => $payload['timeout'] ?? null,
                'environment' => $payload['environment'] ?? null,
            ],
            'command_name' => $commandName,
            'transport_options' => $transportOptions,
        ];

        return new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode(JsonEnvelope::success([
                'exit_code' => $result->exitCode,
                'stdout' => $result->stdout,
                'stderr' => $result->stderr,
                'duration_ms' => $result->durationMs,
            ]), JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: $result->durationMs,
        );
    }
}

function createDeployManagerTestApp(array $overrides = []): App
{
    $node = Node::factory()
        ->appProd()
        ->create([
            'name' => 'app-prod-1',
        ]);

    $attributes = array_merge([
        'name' => 'docs',
        'node_id' => $node->id,
        'environment' => 'production',
        'path' => '/srv/docs',
        'php_version' => '8.5',
        'runtime' => AppRuntimeKind::Php,
    ], $overrides);
    $warmupPaths = $attributes['deploy_warmup_paths'] ?? null;
    unset($attributes['deploy_warmup_paths']);

    $app = App::factory()->create($attributes);
    Instance::factory()->create([
        'app_id' => $app->id,
        'name' => 'production',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: $app->path,
            document_root: $app->document_root,
            domain: $app->domain,
        ),
        'deploy_warmup_paths' => $warmupPaths,
    ]);

    return $app;
}

function createDeployManagerTestStep(App $app, string $command, string $title = 'Test step'): DeployStep
{
    $instance = Instance::query()->where('app_id', $app->id)->sole();

    return DeployStep::query()->create([
        'instance_id' => $instance->id,
        'title' => $title,
        'command' => $command,
        'sort_order' => 1,
        'timeout_seconds' => 120,
    ]);
}

it('requires a concrete deployment instance when a logical app has multiple instances', function (): void {
    $app = createDeployManagerTestApp();
    Instance::factory()->create([
        'app_id' => $app->id,
        'name' => 'canary',
    ]);

    expect(fn () => app(DeployManager::class)->listSteps('docs'))
        ->toThrow(GatewayApiException::class, 'requires a concrete instance selector');
});

it('scopes deployment policy to the selected instance', function (): void {
    $app = createDeployManagerTestApp();
    $production = Instance::query()->where('app_id', $app->id)->sole();
    $canary = Instance::factory()->create([
        'app_id' => $app->id,
        'name' => 'canary',
    ]);
    DeployStep::query()->create([
        'instance_id' => $canary->id,
        'title' => 'Canary only',
        'command' => 'true',
        'sort_order' => 1,
        'timeout_seconds' => 30,
    ]);

    $result = app(DeployManager::class)->addStep(
        'docs.production',
        'php artisan migrate --force',
        'Production only',
        null,
        120,
        null,
    );

    expect($result['step'])
        ->toMatchArray([
            'app' => 'docs',
            'instance' => 'production',
            'title' => 'Production only',
        ])
        ->and(DeployStep::query()->where('instance_id', $production->id)->count())
        ->toBe(1)
        ->and(DeployStep::query()->where('instance_id', $canary->id)->count())
        ->toBe(1);
});

it('executes and records a deployment against the concrete instance target', function (): void {
    $app = createDeployManagerTestApp();
    $instance = Instance::query()->where('app_id', $app->id)->sole();
    $instanceNode = Node::factory()->appProd()->create(['name' => 'instance-prod']);
    $instance->forceFill([
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $instanceNode->id,
            node: $instanceNode->name,
            path: '/home/billing/releases',
            document_root: 'public',
            domain: 'billing.example.com',
        ),
    ])->save();
    createDeployManagerTestStep($app, command: 'git pull origin main');
    $shell = new DeployManagerRecordingShell;
    app()->instance(RunsInternalCommands::class, $shell);

    $result = app(DeployManager::class)->run('docs.production');

    expect($shell->runs[0]['node']->is($instanceNode))
        ->toBeTrue()
        ->and($shell->runs[0]['options']['cwd'])
        ->toBe('/home/billing/releases')
        ->and($result['run']['instance'])
        ->toBe('production')
        ->and($instance->refresh()->latestDeploymentRun->status)
        ->toBe('completed')
        ->and(DeploymentRun::query()->sole()->instance_id)
        ->toBe($instance->id);
});

it('dispatches deployment steps through the dedicated Agent-push command without an SSH fallback header', function (): void {
    $app = createDeployManagerTestApp();
    createDeployManagerTestStep($app, 'git pull origin main');

    $shell = new DeployManagerRecordingShell;
    app()->instance(RunsInternalCommands::class, $shell);

    app(DeployManager::class)->run('docs');

    expect($shell->runs)
        ->toHaveCount(3)
        ->and($shell->runs[0]['command_name'])
        ->toBe(InternalCommand::DeployRunStep->value)
        ->and($shell->runs[0]['transport_options'])
        ->not
        ->toHaveKey('transport')
        ->and($shell->runs[0]['binary'])
        ->toBe('/bin/sh')
        ->and($shell->runs[0]['arguments'][0])
        ->toBe('-lc')
        ->and(DeploymentRun::query()->count())
        ->toBe(1);
});

it('routes php commands through the host php toolchain for php apps', function (): void {
    $app = createDeployManagerTestApp();
    createDeployManagerTestStep($app, 'php artisan migrate --force');

    $shell = new DeployManagerRecordingShell;

    app()->instance(RunsInternalCommands::class, $shell);

    $manager = app(DeployManager::class);
    $manager->run('docs');

    // user step + composer install warmup + php artisan optimize warmup
    expect($shell->runs)
        ->toHaveCount(3)
        ->and($shell->runs[0]['script'])
        ->toContain("'sudo'")
        ->and($shell->runs[0]['script'])
        ->toContain("'bash'")
        ->and($shell->runs[0]['script'])
        ->toContain("'-lc'")
        ->and($shell->runs[0]['script'])
        ->toContain('/opt/orbit/php/')
        ->and($shell->runs[0]['script'])
        ->toContain('php artisan migrate --force');
});

it('runs routed php deploy commands as the path-derived production project user', function (): void {
    $app = createDeployManagerTestApp([
        'path' => '/home/docs/app',
    ]);
    createDeployManagerTestStep($app, 'php artisan migrate --force');

    $shell = new DeployManagerRecordingShell;

    app()->instance(RunsInternalCommands::class, $shell);

    $manager = app(DeployManager::class);
    $manager->run('docs');

    expect($shell->runs[0]['script'])
        ->toContain("'sudo' '-u' 'docs'")
        ->and($shell->runs[0]['script'])
        ->not
        ->toContain("'sudo' '-u' 'orbit'")
        ->and($shell->runs[0]['script'])
        ->toContain('/home/docs/app');
});

it('routes composer commands through the host php toolchain for php apps', function (): void {
    $app = createDeployManagerTestApp();
    createDeployManagerTestStep($app, 'composer install --no-interaction');

    $shell = new DeployManagerRecordingShell;

    app()->instance(RunsInternalCommands::class, $shell);

    $manager = app(DeployManager::class);
    $manager->run('docs');

    expect($shell->runs[0]['script'])
        ->toContain("'sudo'")
        ->and($shell->runs[0]['script'])
        ->toContain('/opt/orbit/php/')
        ->and($shell->runs[0]['script'])
        ->toContain('composer install --no-interaction');
});

it('routes artisan commands through the host php toolchain for php apps', function (): void {
    $app = createDeployManagerTestApp();
    createDeployManagerTestStep($app, 'php artisan optimize');

    $shell = new DeployManagerRecordingShell;

    app()->instance(RunsInternalCommands::class, $shell);

    $manager = app(DeployManager::class);
    $manager->run('docs');

    expect($shell->runs[0]['script'])
        ->toContain("'sudo'")
        ->and($shell->runs[0]['script'])
        ->toContain('/opt/orbit/php/')
        ->and($shell->runs[0]['script'])
        ->toContain('php artisan optimize');
});

it('runs non-php commands on the host for php apps', function (): void {
    $app = createDeployManagerTestApp();
    createDeployManagerTestStep($app, 'git pull origin main');

    $shell = new DeployManagerRecordingShell;
    app()->instance(RunsInternalCommands::class, $shell);

    $manager = app(DeployManager::class);
    $manager->run('docs');

    expect($shell->runs[0]['script'])
        ->not
        ->toContain('docker exec')
        ->and($shell->runs[0]['script'])
        ->toBe('git pull origin main')
        ->and($shell->runs[0]['options']['cwd'])
        ->toBe('/srv/docs');
});

it('runs all commands on the host for static apps', function (): void {
    $app = createDeployManagerTestApp(['runtime' => AppRuntimeKind::Static]);
    createDeployManagerTestStep($app, 'php artisan migrate --force');

    $shell = new DeployManagerRecordingShell;
    app()->instance(RunsInternalCommands::class, $shell);

    $manager = app(DeployManager::class);
    $manager->run('docs');

    expect($shell->runs[0]['script'])
        ->not
        ->toContain('docker exec')
        ->and($shell->runs[0]['script'])
        ->toBe('php artisan migrate --force');
});

it('does not transform host paths to container paths when routing through host', function (): void {
    $app = createDeployManagerTestApp();
    createDeployManagerTestStep($app, command: 'cd "{{ app_path }}" && php artisan migrate');

    $shell = new DeployManagerRecordingShell;

    app()->instance(RunsInternalCommands::class, $shell);

    $manager = app(DeployManager::class);
    $manager->run('docs');

    $script = $shell->runs[0]['script'];
    expect($script)
        ->toContain("'sudo'")
        ->and($script)
        ->toContain("'-lc'")
        ->and($script)
        ->not->toContain("'/app'");
});

it('preserves documented app context aliases for existing deployment steps', function (): void {
    $app = createDeployManagerTestApp();
    createDeployManagerTestStep($app, command: 'mkdir -p "{{ app_path }}/database"');
    app()->instance(RunsInternalCommands::class, new DeployManagerRecordingShell);

    app(DeployManager::class)->run('docs.production');

    $context = DeploymentRun::query()->sole()->context;

    expect($context)
        ->toMatchArray([
            'app_name' => 'docs',
            'instance' => 'production',
            'app_path' => '/srv/docs',
            'app_user' => 'orbit',
            'app_name' => 'docs',
            'instance' => 'production',
            'app_path' => '/srv/docs',
            'app_user' => 'orbit',
            'app' => [
                'name' => 'docs',
                'instance' => 'production',
                'path' => '/srv/docs',
                'user' => 'orbit',
                'domain' => null,
                'repository' => null,
            ],
        ]);
});

it('passes deploy environment variables to the host command', function (): void {
    $app = createDeployManagerTestApp();
    createDeployManagerTestStep($app, 'php artisan migrate');

    $shell = new DeployManagerRecordingShell;

    app()->instance(RunsInternalCommands::class, $shell);

    $manager = app(DeployManager::class);
    $manager->run('docs');

    $script = $shell->runs[0]['script'];
    expect($script)
        ->toContain('ORBIT_DEPLOY_APP_NAME=')
        ->toContain('docs')
        ->not->toContain("'ORBIT_DEPLOY_APP_NAME=docs'");
});

it('sets the working directory to the app source path for host commands', function (): void {
    $app = createDeployManagerTestApp();
    createDeployManagerTestStep($app, 'php artisan migrate');

    $shell = new DeployManagerRecordingShell;

    app()->instance(RunsInternalCommands::class, $shell);

    $manager = app(DeployManager::class);
    $manager->run('docs');

    expect($shell->runs[0]['script'])->toContain("'sudo'")->and($shell->runs[0]['script'])->toContain('/srv/docs');
});

it('does not route php-fpm systemctl commands through host php toolchain', function (): void {
    $app = createDeployManagerTestApp();
    createDeployManagerTestStep($app, 'sudo systemctl reload php8.5-fpm');

    $shell = new DeployManagerRecordingShell;
    app()->instance(RunsInternalCommands::class, $shell);

    $manager = app(DeployManager::class);
    $manager->run('docs');

    expect($shell->runs[0]['script'])
        ->not
        ->toContain('docker exec')
        ->and($shell->runs[0]['script'])
        ->toBe('sudo systemctl reload php8.5-fpm');
});

it('runs built-in warmup steps on the host after user steps for php apps', function (): void {
    $app = createDeployManagerTestApp();
    createDeployManagerTestStep($app, command: 'git pull origin main');

    $shell = new DeployManagerRecordingShell;

    app()->instance(RunsInternalCommands::class, $shell);

    $manager = app(DeployManager::class);
    $manager->run('docs');

    // user step (git) + composer install warmup + php artisan optimize warmup
    expect($shell->runs)
        ->toHaveCount(3)
        ->and($shell->runs[1]['script'])
        ->toContain('composer install --no-dev --optimize-autoloader')
        ->and($shell->runs[2]['script'])
        ->toContain('php artisan optimize')
        ->and($shell->runs[1]['script'])
        ->toContain("'sudo'")
        ->and($shell->runs[2]['script'])
        ->toContain("'sudo'");
});

it('activates a safe live release runtime before warming the application', function (): void {
    $app = createDeployManagerTestApp();
    createDeployManagerTestStep(
        $app,
        command: 'ln -sfn "{{ release_path }}" "{{ live_path }}"',
        title: 'Activate release',
    );

    $shell = new DeployManagerRecordingShell;
    $shell->sourcePathProbe = [
        'path' => '/srv/docs/live',
        'exists' => true,
        'resolved_path' => '/srv/docs/releases/20260729_100713_219',
        'within_boundary' => true,
    ];
    app()->instance(RunsInternalCommands::class, $shell);
    $runtimeConverger = Mockery::mock(ConvergesAppRuntimeContainers::class);
    $runtimeConverger->shouldReceive('apply')->once()->andReturn(AppRuntimeContainerApplyOutcome::Recreated);
    app()->instance(ConvergesAppRuntimeContainers::class, $runtimeConverger);

    app(DeployManager::class)->run('docs');

    $instance = Instance::query()->where('app_id', $app->id)->sole();
    $config = $instance->driver_config;
    assert(
        $config instanceof OrbitInstanceDriverConfigData,
        description: 'Instance must retain Orbit driver config.',
    );
    $warmupRuns = array_values(array_filter(
        $shell->runs,
        static fn (array $run): bool => (
            is_string($run['script'] ?? null)
            && (
                str_contains($run['script'], 'composer install --no-dev')
                || str_contains($run['script'], 'php artisan optimize')
            )
        ),
    ));
    $probeRuns = array_values(array_filter(
        $shell->runs,
        static fn (array $run): bool => ($run['command_name'] ?? null) === 'internal:app-source-path:probe',
    ));

    expect($config->document_root)
        ->toBe('live/public')
        ->and($probeRuns)
        ->toHaveCount(1)
        ->and($probeRuns[0])
        ->toMatchArray([
            'node' => $app->node,
            'binary' => 'internal:app-source-path:probe',
            'arguments' => ['/srv/docs/live'],
            'script' => null,
            'options' => ['boundary' => '/srv/docs'],
            'command_name' => 'internal:app-source-path:probe',
            'transport_options' => [
                'metadata' => ['ORBIT_OPERATION_ID' => 'app-source-path.probe'],
                'strict' => true,
                'timeout' => 15,
            ],
        ])
        ->and($warmupRuns)
        ->toHaveCount(2)
        ->and(array_column($warmupRuns, 'options'))
        ->each(
            fn ($options) => $options->toMatchArray(['cwd' => '/srv/docs/live']),
        )
        ->and(array_column($warmupRuns, 'script'))
        ->each(
            fn ($script) => $script
                ->toContain("cd '\\''/srv/docs/live'\\'' &&")
                ->not->toContain("cd '\\''/srv/docs'\\'' &&"),
        );
});

it('restarts an unchanged runtime after activating a new live release', function (): void {
    $app = createDeployManagerTestApp();
    createDeployManagerTestStep($app, command: 'ln -sfn "{{ release_path }}" "{{ live_path }}"');

    $shell = new DeployManagerRecordingShell;
    $shell->sourcePathProbe = [
        'path' => '/srv/docs/live',
        'exists' => true,
        'resolved_path' => '/srv/docs/releases/20260729_100713_219',
        'within_boundary' => true,
    ];
    app()->instance(RunsInternalCommands::class, $shell);
    $runtimeConverger = Mockery::mock(ConvergesAppRuntimeContainers::class);
    $runtimeConverger->shouldReceive('apply')->once()->andReturn(AppRuntimeContainerApplyOutcome::Unchanged);
    app()->instance(ConvergesAppRuntimeContainers::class, $runtimeConverger);

    app(DeployManager::class)->run('docs');

    $restartRuns = array_values(array_filter(
        $shell->runs,
        static fn (array $run): bool => ($run['command_name'] ?? null) === 'internal:process-docker-container',
    ));

    expect($restartRuns)
        ->toHaveCount(1)
        ->and($restartRuns[0]['options'])
        ->toMatchArray([
            'action' => 'restart',
            'container' => 'orbit-app-docs-production',
        ]);
});

it('fails closed when an active release escapes the app source boundary', function (): void {
    $app = createDeployManagerTestApp();
    createDeployManagerTestStep($app, command: 'ln -sfn "{{ release_path }}" "{{ live_path }}"');

    $shell = new DeployManagerRecordingShell;
    $shell->sourcePathProbe = [
        'path' => '/srv/docs/live',
        'exists' => true,
        'resolved_path' => '/tmp/escaped-release',
        'within_boundary' => false,
    ];
    app()->instance(RunsInternalCommands::class, $shell);

    try {
        app(DeployManager::class)->run('docs');
        $this->fail('Expected GatewayApiException');
    } catch (GatewayApiException $exception) {
        expect($exception->errorCode())->toBe('deploy.active_release_unsafe');
    }

    $instance = Instance::query()->where('app_id', $app->id)->sole();
    $config = $instance->driver_config;
    assert(
        $config instanceof OrbitInstanceDriverConfigData,
        description: 'Instance must retain Orbit driver config.',
    );

    expect($config->document_root)
        ->toBe('public')
        ->and(DeploymentRun::query()->sole()->status)
        ->toBe('failed')
        ->and(array_filter(
            $shell->runs,
            static fn (array $run): bool => ($run['command_name'] ?? null) === 'internal:app-runtime-container',
        ))
        ->toBeEmpty();
});

it('skips warmup steps when a user step fails', function (): void {
    $app = createDeployManagerTestApp();
    createDeployManagerTestStep($app, 'git pull origin main');

    $shell = new DeployManagerRecordingShell;
    $shell->results = [
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'fail', durationMs: 25),
    ];
    app()->instance(RunsInternalCommands::class, $shell);

    $manager = app(DeployManager::class);

    try {
        $manager->run('docs');
    } catch (GatewayApiException) {
        // Expected
    }

    expect($shell->runs)->toHaveCount(1)->and($shell->runs[0]['script'])->toBe('git pull origin main');
});

it('does not run warmup steps for static apps', function (): void {
    $app = createDeployManagerTestApp(['runtime' => AppRuntimeKind::Static]);
    createDeployManagerTestStep($app, 'git pull origin main');

    $shell = new DeployManagerRecordingShell;
    app()->instance(RunsInternalCommands::class, $shell);

    $manager = app(DeployManager::class);
    $manager->run('docs');

    expect($shell->runs)->toHaveCount(1)->and($shell->runs[0]['script'])->toBe('git pull origin main');
});

it('runs http warmup when deploy_warmup_paths is configured', function (): void {
    $app = createDeployManagerTestApp(['deploy_warmup_paths' => ['/api/health', '/']]);
    createDeployManagerTestStep($app, 'git pull origin main');

    $shell = new DeployManagerRecordingShell;

    app()->instance(RunsInternalCommands::class, $shell);

    $manager = app(DeployManager::class);
    $manager->run('docs');

    // user step + composer optimize + artisan optimize + 2 HTTP warmups
    expect($shell->runs)
        ->toHaveCount(5)
        ->and($shell->runs[3]['script'])
        ->toContain('curl')
        ->and($shell->runs[3]['script'])
        ->toContain('/api/health')
        ->and($shell->runs[4]['script'])
        ->toContain('curl')
        ->and($shell->runs[4]['script'])
        ->toContain('/');
});

it('skips http warmup when deploy_warmup_paths is empty', function (): void {
    $app = createDeployManagerTestApp(['deploy_warmup_paths' => []]);
    createDeployManagerTestStep($app, 'git pull origin main');

    $shell = new DeployManagerRecordingShell;

    app()->instance(RunsInternalCommands::class, $shell);

    $manager = app(DeployManager::class);
    $manager->run('docs');

    // user step + composer optimize + artisan optimize, no HTTP warmups
    expect($shell->runs)->toHaveCount(3);
});

it('uses version-matched php path in host commands', function (): void {
    $app = createDeployManagerTestApp(['php_version' => '8.4']);
    createDeployManagerTestStep($app, 'php artisan migrate');

    $shell = new DeployManagerRecordingShell;
    app()->instance(RunsInternalCommands::class, $shell);

    $manager = app(DeployManager::class);
    $manager->run('docs');

    $script = $shell->runs[0]['script'];

    expect($script)->toContain('/opt/orbit/php/')->and($script)->toContain("'8.4'");
});

it('marks run failed when built-in warmup step fails', function (): void {
    $app = createDeployManagerTestApp();
    createDeployManagerTestStep($app, 'git pull origin main');

    $shell = new DeployManagerRecordingShell;
    $shell->results = [
        // User step succeeds
        new RemoteShellResult(exitCode: 0, stdout: "ok\n", stderr: '', durationMs: 25),
        // composer install succeeds
        new RemoteShellResult(exitCode: 0, stdout: "composer ok\n", stderr: '', durationMs: 25),
        // php artisan optimize fails
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'optimize failed', durationMs: 25),
    ];
    app()->instance(RunsInternalCommands::class, $shell);

    $manager = app(DeployManager::class);

    try {
        $manager->run('docs');
        $this->fail('Expected GatewayApiException');
    } catch (GatewayApiException $e) {
        expect($e->errorCode())
            ->toBe('deploy.warmup_failed')
            ->and($e->errorMeta()['warmup_command'])
            ->toBe('php artisan optimize');
    }

    $run = DeploymentRun::query()->sole();
    expect($run->status)->toBe('failed')->and($run->exit_code)->toBe(1);
});

it('reports Agent reachability when remote deploy execution cannot start', function (): void {
    $app = createDeployManagerTestApp();
    createDeployManagerTestStep($app, command: 'git pull origin main');
    $instance = Instance::query()->where('app_id', $app->id)->sole();

    $shell = new DeployManagerRecordingShell;
    $shell->results = [
        new RemoteLocalExecutorTransportFailed('agent-push transport is unavailable'),
    ];
    app()->instance(RunsInternalCommands::class, $shell);

    expect(fn (): array => app(DeployManager::class)->run('docs'))
        ->toThrow(function (GatewayApiException $exception): void {
            expect($exception->errorCode())
                ->toBe('node.agent_unreachable')
                ->and($exception->errorMeta()['reason'])
                ->toBe('agent_push_unavailable')
                ->and($exception->errorMeta()['node'])
                ->toBe('app-prod-1');
        });

    $run = DeploymentRun::query()->sole();
    $latestRun = $instance->refresh()->latestDeploymentRun;

    expect([
        $run->status,
        $run->exit_code,
        $latestRun?->id,
        $latestRun?->status,
    ])
        ->toBe(['failed', 1, $run->id, 'failed'])
        ->and($run->finished_at)
        ->not->toBeNull()->and($run->duration_ms)
        ->not->toBeNull();
});

it('records an unexpected deploy execution error as a failed run', function (): void {
    $app = createDeployManagerTestApp();
    createDeployManagerTestStep($app, command: 'git pull origin main');
    $instance = Instance::query()->where('app_id', $app->id)->sole();

    $shell = new DeployManagerRecordingShell;
    $shell->failure = new RuntimeException('unexpected deploy failure');
    app()->instance(RunsInternalCommands::class, $shell);

    expect(fn (): array => app(DeployManager::class)->run('docs'))
        ->toThrow(RuntimeException::class, 'unexpected deploy failure');

    $run = DeploymentRun::query()->sole();

    expect($run->status)
        ->toBe('failed')
        ->and($run->exit_code)
        ->toBe(1)
        ->and($instance->refresh()->latestDeploymentRun->id)
        ->toBe($run->id)
        ->and($instance->latestDeploymentRun->status)
        ->toBe('failed');
});
