<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppRuntimeKind;
use App\Enums\Workspaces\WorkspaceRuntimeArtifactRemovalOutcome;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\ActivityLogCorrelation;
use App\Services\ActivityLogger;
use App\Services\Ca\OrbitCaService;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationTokenFactory;
use App\Services\Php\PhpRuntimeCatalog;
use App\Services\Php\PhpRuntimePolicy;
use App\Services\RemoteShell\LocalExecutorCommandBuilder;
use App\Services\RemoteShell\RemoteExecutor;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\RemoteShell\RunsInternalCommands;
use App\Services\Runtime\DockerCommandBuilder;
use App\Services\Runtime\OrbitContainerNames;
use App\Services\Tools\ToolScriptDispatcher;
use App\Services\Workspaces\WorkspaceRuntimeContainer;
use App\Services\Workspaces\WorkspaceRuntimeContainerApplyException;
use App\Services\Workspaces\WorkspaceRuntimeContainerManager;
use App\Services\Workspaces\WorkspaceRuntimeContainerRenderer;
use App\Services\Workspaces\WorkspaceRuntimeImageUnavailableException;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orbit\Core\Security\OperationTokenSigner;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {});

afterEach(function (): void {});

function workspaceAndNodeForManagerTest(): array
{
    $node = createTestAppHostNode(['user' => 'orbit'], 'app-dev');
    $app = App::factory()->for($node, 'node')->create([
        'name' => 'demo',
        'path' => '/home/orbit/apps/demo',
        'php_version' => '8.5',
        'runtime' => AppRuntimeKind::Php,
    ]);
    $workspace = Workspace::factory()->for($app, 'app')->create([
        'name' => 'feature-a',
        'path' => '/home/orbit/apps/demo/.worktrees/feature-a',
        'php_version' => null,
    ]);
    $workspace->setRelation('app', $app);

    return [$workspace, $node];
}

function renderTestWorkspaceContainer(Workspace $workspace): WorkspaceRuntimeContainer
{
    return new WorkspaceRuntimeContainerRenderer(
        new PhpRuntimePolicy(new PhpRuntimeCatalog),
        new OrbitContainerNames,
    )->render($workspace);
}

function fake_orbit_ca_service_for_workspace_manager_test(): OrbitCaService
{
    return new readonly class extends OrbitCaService {
        public function rootCert(): string
        {
            return "-----BEGIN CERTIFICATE-----\ntest-root-cert\n-----END CERTIFICATE-----\n";
        }
    };
}

function workspace_runtime_manager_for_test(RemoteShell $shell): WorkspaceRuntimeContainerManager
{
    return new WorkspaceRuntimeContainerManager(
        commands: new DockerCommandBuilder,
        ca: fake_orbit_ca_service_for_workspace_manager_test(),
        scripts: new ToolScriptDispatcher(new WorkspaceRuntimeScriptExecutor($shell)),
    );
}

function workspace_runtime_manager_local_executor_for_test(): RemoteLocalExecutor
{
    return new RemoteLocalExecutor(
        commands: new LocalExecutorCommandBuilder,
        operationTokens: new OperationTokenFactory(
            signer: new OperationTokenSigner,
            secret: workspace_runtime_manager_operation_secret_for_test(),
            ttlSeconds: 120,
            clock: static fn (): int => 1_798_105_200,
        ),
        activityLogger: new ActivityLogger(new ActivityLogCorrelation),
        operationRuns: app(OperationRunRecorder::class),
        outputRedactor: app(\App\Services\RemoteShell\RemoteExecutorOutputRedactor::class),
        agentPush: app(\App\Services\NodeCommandTransport\NodeAgentPushDispatcher::class),
        gatewayLocal: app(\App\Services\RemoteShell\GatewayLocalCommandDispatcher::class),
        applicationKey: workspace_runtime_manager_operation_secret_for_test(),
    );
}

function workspace_runtime_manager_operation_secret_for_test(): string
{
    return 'workspace-runtime-manager-secret';
}

function inspectPayloadForWorkspace(
    WorkspaceRuntimeContainer $container,
    bool $running = true,
    ?string $specHash = null,
): string {
    return json_encode([
        'State' => [
            'Running' => $running,
        ],
        'Config' => [
            'Labels' => [
                WorkspaceRuntimeContainer::SpecHashLabel => $specHash ?? $container->specHash(),
            ],
        ],
    ], JSON_THROW_ON_ERROR);
}

final readonly class WorkspaceRuntimeScriptExecutor implements RunsInternalCommands
{
    public function __construct(
        private RemoteExecutor $shell,
    ) {}

    public function runInternal(
        Node $node,
        string $commandName,
        array $arguments = [],
        array $commandOptions = [],
        array $transportOptions = [],
    ): RemoteShellResult {
        $payload = json_decode((string) $transportOptions['input'], true, flags: JSON_THROW_ON_ERROR);
        $result = $this->shell->run($node, (string) $payload['script'], $transportOptions);

        if (! $result->successful()) {
            return $result;
        }

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

final class WorkspaceRuntimeRecordingShell implements RemoteExecutor
{
    /** @var list<array{node: Node, script: string, options: array<string, mixed>}> */
    public array $calls = [];

    /** @var list<RemoteShellResult> */
    public array $responses = [];

    private bool $failOnRun = false;

    public function __construct(RemoteShellResult ...$responses)
    {
        $this->responses = $responses;
    }

    public function failOnRun(): self
    {
        $this->failOnRun = true;

        return $this;
    }

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        if ($this->failOnRun) {
            throw new RuntimeException(
                'Workspace runtime manager fallback test should not use RemoteLocalExecutor transport.',
            );
        }

        $this->calls[] = ['node' => $node, 'script' => $script, 'options' => $options];

        return (
            array_shift($this->responses) ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1)
        );
    }

    public function start(Node $node, string $script, array $options = []): InvokedProcess
    {
        throw new RuntimeException('Workspace runtime manager tests do not start long-running transports.');
    }
}

it('returns AlreadyAbsent when removing a workspace container that does not exist on the node', function (): void {
    [$workspace, $node] = workspaceAndNodeForManagerTest();

    $shell = new WorkspaceRuntimeRecordingShell(
        new RemoteShellResult(
            exitCode: 1,
            stdout: '',
            stderr: 'Error: No such object: orbit-ws-demo-feature-a',
            durationMs: 1,
        ),
    );

    $outcome = workspace_runtime_manager_for_test($shell)->remove(
        $node,
        'demo',
        'feature-a',
    );

    expect($outcome)
        ->toBe(WorkspaceRuntimeArtifactRemovalOutcome::AlreadyAbsent)
        ->and(count($shell->calls))
        ->toBe(1)
        ->and($shell->calls[0]['script'])
        ->toContain('docker container inspect');
});

it('returns Removed when an existing workspace container is removed', function (): void {
    [$workspace, $node] = workspaceAndNodeForManagerTest();
    $container = renderTestWorkspaceContainer($workspace);

    $shell = new WorkspaceRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 0, stdout: inspectPayloadForWorkspace($container), stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    );

    $outcome = workspace_runtime_manager_for_test($shell)->remove(
        $node,
        'demo',
        'feature-a',
    );

    $scripts = array_map(fn (array $call): string => $call['script'], $shell->calls);

    expect($outcome)
        ->toBe(WorkspaceRuntimeArtifactRemovalOutcome::Removed)
        ->and($scripts[1])
        ->toContain('docker rm -f')
        ->and($scripts[1])
        ->toContain("'orbit-ws-demo-feature-a'");
});

it('returns FailedRemaining when an existing workspace container cannot be removed', function (): void {
    [$workspace, $node] = workspaceAndNodeForManagerTest();
    $container = renderTestWorkspaceContainer($workspace);

    $shell = new WorkspaceRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 0, stdout: inspectPayloadForWorkspace($container), stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'container in use', durationMs: 1),
    );

    $outcome = workspace_runtime_manager_for_test($shell)->remove(
        $node,
        'demo',
        'feature-a',
    );

    expect($outcome)->toBe(WorkspaceRuntimeArtifactRemovalOutcome::FailedRemaining);
});

it('returns tri-state outcomes for managed workspace runtime config file removal', function (): void {
    [$workspace, $node] = workspaceAndNodeForManagerTest();

    $absentShell = new WorkspaceRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 0, stdout: "orbit-container-config-probe:absent\n", stderr: '', durationMs: 1),
    );
    $absentOutcome = workspace_runtime_manager_for_test($absentShell)->removeRuntimeConfigFile(
        $node,
        'demo',
        'feature-a',
    );

    $removedShell = new WorkspaceRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 0, stdout: "orbit-container-config-probe:present\n", stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: "orbit-container-config-probe:absent\n", stderr: '', durationMs: 1),
    );
    $removedOutcome = workspace_runtime_manager_for_test($removedShell)->removeRuntimeConfigFile(
        $node,
        'demo',
        'feature-a',
    );

    $failedShell = new WorkspaceRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 0, stdout: "orbit-container-config-probe:present\n", stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'permission denied', durationMs: 1),
    );
    $failedOutcome = workspace_runtime_manager_for_test($failedShell)->removeRuntimeConfigFile(
        $node,
        'demo',
        'feature-a',
    );

    expect($absentOutcome)
        ->toBe(WorkspaceRuntimeArtifactRemovalOutcome::AlreadyAbsent)
        ->and($absentShell->calls[0]['script'])
        ->toContain("[ -e '/home/orbit/.config/orbit/workspaces/demo-feature-a.ini' ]")
        ->and($removedOutcome)
        ->toBe(WorkspaceRuntimeArtifactRemovalOutcome::Removed)
        ->and($removedShell->calls[1]['script'])
        ->toContain("rm -f '/home/orbit/.config/orbit/workspaces/demo-feature-a.ini'")
        ->and($failedOutcome)
        ->toBe(WorkspaceRuntimeArtifactRemovalOutcome::FailedRemaining);
});

it(
    'returns FailedRemaining when the docker container inspect probe fails for an unknown reason instead of reporting AlreadyAbsent',
    function (): void {
        [$workspace, $node] = workspaceAndNodeForManagerTest();

        $shell = new WorkspaceRuntimeRecordingShell(
            new RemoteShellResult(
                exitCode: 1,
                stdout: '',
                stderr: 'Cannot connect to the Docker daemon',
                durationMs: 1,
            ),
        );

        $outcome = workspace_runtime_manager_for_test($shell)->remove(
            $node,
            'demo',
            'feature-a',
        );

        expect($outcome)->toBe(WorkspaceRuntimeArtifactRemovalOutcome::FailedRemaining);
    },
);

it('writes the managed workspace runtime config file via writeRuntimeConfigFile', function (): void {
    [$workspace, $node] = workspaceAndNodeForManagerTest();
    $container = renderTestWorkspaceContainer($workspace);

    $shell = new WorkspaceRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    );

    workspace_runtime_manager_for_test($shell)->writeRuntimeConfigFile($node, $container);

    expect($shell->calls)
        ->toHaveCount(1)
        ->and($shell->calls[0]['script'])
        ->toContain('/home/orbit/.config/orbit/workspaces/demo-feature-a.ini')
        ->and($shell->calls[0]['script'])
        ->toContain('base64 -d');
});

it(
    'throws WorkspaceRuntimeImageUnavailableException when the selected FrankenPHP image is not on the node before creating a new container',
    function (): void {
        [$workspace, $node] = workspaceAndNodeForManagerTest();
        $container = renderTestWorkspaceContainer($workspace);

        $shell = new WorkspaceRuntimeRecordingShell(
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(
                exitCode: 1,
                stdout: '',
                stderr: 'Error: No such image: ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm',
                durationMs: 1,
            ),
        );

        expect(
            fn () => workspace_runtime_manager_for_test($shell)->apply($node, $container),
        )
            ->toThrow(WorkspaceRuntimeImageUnavailableException::class);
    },
);

it(
    'throws WorkspaceRuntimeContainerApplyException — NOT WorkspaceRuntimeImageUnavailableException — when the image probe fails for an unknown Docker error',
    function (): void {
        [$workspace, $node] = workspaceAndNodeForManagerTest();
        $container = renderTestWorkspaceContainer($workspace);

        $shell = new WorkspaceRuntimeRecordingShell(
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(
                exitCode: 1,
                stdout: '',
                stderr: 'Cannot connect to the Docker daemon at unix:///var/run/docker.sock.',
                durationMs: 1,
            ),
        );

        $manager = workspace_runtime_manager_for_test($shell);

        $caught = null;
        try {
            $manager->apply($node, $container);
        } catch (Throwable $e) {
            $caught = $e;
        }

        expect($caught)
            ->toBeInstanceOf(WorkspaceRuntimeContainerApplyException::class)
            ->and($caught)
            ->not
            ->toBeInstanceOf(WorkspaceRuntimeImageUnavailableException::class)
            ->and($caught->hadExistingContainer)
            ->toBeFalse();

        $scripts = array_map(fn (array $call): string => $call['script'], $shell->calls);

        expect(collect($scripts)->contains(fn (string $s): bool => str_contains($s, 'docker run -d')))
            ->toBeFalse()
            ->and(collect($scripts)->contains(fn (string $s): bool => str_contains($s, 'docker rm -f')))
            ->toBeFalse()
            ->and(collect($scripts)->contains(fn (string $s): bool => str_contains($s, 'docker start')))
            ->toBeFalse();
    },
);

it('returns Unchanged for a matching running workspace runtime by default', function (): void {
    [$workspace, $node] = workspaceAndNodeForManagerTest();
    $container = renderTestWorkspaceContainer($workspace);

    $shell = new WorkspaceRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(
            exitCode: 0,
            stdout: inspectPayloadForWorkspace($container, running: true),
            stderr: '',
            durationMs: 1,
        ),
        new RemoteShellResult(exitCode: 0, stdout: '[]', stderr: '', durationMs: 1),
    );

    $outcome = workspace_runtime_manager_for_test($shell)->apply($node, $container);
    $scripts = array_map(static fn (array $call): string => $call['script'], $shell->calls);

    expect($outcome->value)
        ->toBe('unchanged')
        ->and(collect($scripts)->contains(static fn (string $script): bool => str_contains($script, 'docker restart')))
        ->toBeFalse()
        ->and(collect($scripts)->contains(static fn (string $script): bool => str_contains($script, 'docker run -d')))
        ->toBeFalse();
});

it('restarts a matching running workspace runtime only when restartIfRunning is opted in', function (): void {
    [$workspace, $node] = workspaceAndNodeForManagerTest();
    $container = renderTestWorkspaceContainer($workspace);

    $shell = new WorkspaceRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(
            exitCode: 0,
            stdout: inspectPayloadForWorkspace($container, running: true),
            stderr: '',
            durationMs: 1,
        ),
        new RemoteShellResult(exitCode: 0, stdout: '[]', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: $container->name(), stderr: '', durationMs: 1),
    );

    $outcome = workspace_runtime_manager_for_test($shell)->apply($node, $container, restartIfRunning: true);
    $scripts = array_map(static fn (array $call): string => $call['script'], $shell->calls);

    expect($outcome->value)
        ->toBe('restarted')
        ->and(collect($scripts)->contains(static fn (string $script): bool => str_contains($script, 'docker restart')))
        ->toBeTrue()
        ->and(collect($scripts)->contains(static fn (string $script): bool => str_contains($script, 'docker run -d')))
        ->toBeFalse()
        ->and(collect($scripts)->contains(static fn (string $script): bool => str_contains($script, 'docker rm -f')))
        ->toBeFalse();
});
