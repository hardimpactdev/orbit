<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Services\ActivityLogCorrelation;
use App\Services\ActivityLogger;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationTokenFactory;
use App\Services\RemoteShell\LocalExecutorCommandBuilder;
use App\Services\RemoteShell\RemoteExecutor;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\RemoteShell\RunsInternalCommands;
use App\Services\Tools\ToolInstaller;
use App\Services\Tools\ToolScriptDispatcher;
use App\Services\Tools\ToolUpdater;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orbit\Core\Enums\InternalCommand;
use Orbit\Core\Security\OperationTokenSigner;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {});

afterEach(function (): void {
    putenv('GH_TOKEN');
    putenv('GITHUB_TOKEN');
});

it('stages GitHub auth for laravel installer repairs without embedding the token in scripts', function (): void {
    putenv('GH_TOKEN=ghp_unit_secret');
    putenv('GITHUB_TOKEN');

    $node = Node::factory()->create([
        'name' => 'app-dev-1',
        'status' => 'active',
    ]);

    NodeRoleAssignment::factory()->for($node)->create([
        'role' => NodeRoleName::AppDevelopment->value,
        'status' => NodeRoleStatus::Active->value,
    ]);

    $shell = new ToolInstallerGitHubAuthRecordingShell([
        new RemoteShellResult(exitCode: 0, stdout: "/tmp/orbit-secret.github\n", stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);

    $toolExecutor = new ToolInstallerGitHubAuthToolRunExecutor($shell);

    $this->app->instance(RemoteShell::class, $shell);
    $this->app->instance(RemoteLocalExecutor::class, toolInstallerGitHubAuthLocalExecutor($shell));
    $this->app->instance(RunsInternalCommands::class, $toolExecutor);
    $this->app->instance(ToolScriptDispatcher::class, new ToolScriptDispatcher($toolExecutor));

    $result = app(ToolInstaller::class)->install('laravel-installer', node: 'app-dev-1');

    $installPayload = toolInstallerGitHubAuthPayload($shell, InternalCommand::ToolRunScript->value);

    expect($result)
        ->toMatchArray([
            'name' => 'laravel-installer',
            'node' => 'app-dev-1',
            'state' => 'installed',
        ])
        ->and(json_decode($shell->options[0]['input'], true)['content_base64'] ?? null)
        ->toBe(base64_encode('ghp_unit_secret'))
        ->and($shell->commands[0])
        ->toContain('internal:secret-file')
        ->and($shell->commands[1])
        ->toBe(InternalCommand::ToolRunScript->value)
        ->and($installPayload['action'] ?? null)
        ->toBe('install')
        ->and($installPayload['script'] ?? null)
        ->toContain("GITHUB_TOKEN_FILE='/tmp/orbit-secret.github'")
        ->and($installPayload['script'] ?? null)
        ->toContain('composer config --global github-oauth.github.com')
        ->and($installPayload['script'] ?? null)
        ->toContain('gh auth login --hostname github.com --with-token')
        ->and($shell->commands[2])
        ->toBe('internal:secret-file remove');

    foreach ([$installPayload['script'] ?? '', ...$shell->commands] as $script) {
        expect((string) $script)
            ->not->toContain('ghp_unit_secret')
            ->not->toContain('php -r');
    }
});

it('stages GitHub auth for laravel installer updates without embedding the token in scripts', function (): void {
    putenv('GH_TOKEN=ghp_update_secret');
    putenv('GITHUB_TOKEN');

    $node = Node::factory()->create([
        'name' => 'app-dev-1',
        'status' => 'active',
    ]);

    NodeRoleAssignment::factory()->for($node)->create([
        'role' => NodeRoleName::AppDevelopment->value,
        'status' => NodeRoleStatus::Active->value,
    ]);

    NodeTool::factory()->for($node)->create([
        'name' => 'laravel-installer',
        'expected_state' => 'installed',
        'config' => null,
    ]);

    $shell = new ToolInstallerGitHubAuthRecordingShell([
        new RemoteShellResult(exitCode: 0, stdout: "/tmp/orbit-secret.github\n", stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);

    $toolExecutor = new ToolInstallerGitHubAuthToolRunExecutor($shell);

    $this->app->instance(RemoteShell::class, $shell);
    $this->app->instance(RemoteLocalExecutor::class, toolInstallerGitHubAuthLocalExecutor($shell));
    $this->app->instance(RunsInternalCommands::class, $toolExecutor);
    $this->app->instance(ToolScriptDispatcher::class, new ToolScriptDispatcher($toolExecutor));

    $result = app(ToolUpdater::class)->update('laravel-installer', node: 'app-dev-1');

    $updatePayload = toolInstallerGitHubAuthPayload($shell, InternalCommand::ToolRunScript->value);

    expect($result)
        ->toMatchArray([
            'name' => 'laravel-installer',
            'node' => 'app-dev-1',
        ])
        ->and(json_decode($shell->options[0]['input'], true)['content_base64'] ?? null)
        ->toBe(base64_encode('ghp_update_secret'))
        ->and($updatePayload['script'] ?? null)
        ->toContain("GITHUB_TOKEN_FILE='/tmp/orbit-secret.github'")
        ->and($updatePayload['script'] ?? null)
        ->toContain('composer config --global github-oauth.github.com')
        ->and($updatePayload['script'] ?? null)
        ->toContain('composer global update laravel/installer')
        ->and($updatePayload['script'] ?? null)
        ->toContain('gh auth login --hostname github.com --with-token')
        ->and($shell->commands[2])
        ->toBe('internal:secret-file remove');

    foreach ([$updatePayload['script'] ?? '', ...$shell->commands] as $script) {
        expect((string) $script)
            ->not->toContain('ghp_update_secret')
            ->not->toContain('php -r');
    }
});

/**
 * @return array<string, mixed>
 */
function toolInstallerGitHubAuthPayload(ToolInstallerGitHubAuthRecordingShell $shell, string $command): array
{
    foreach ($shell->commands as $index => $recordedCommand) {
        if ($recordedCommand !== $command && ! str_contains($recordedCommand, $command)) {
            continue;
        }

        $input = $shell->options[$index]['input'] ?? null;

        if (! is_string($input)) {
            return [];
        }

        /** @var mixed $payload */
        $payload = json_decode($input, associative: true, flags: JSON_THROW_ON_ERROR);

        return is_array($payload) ? $payload : [];
    }

    return [];
}

function toolInstallerGitHubAuthLocalExecutor(RemoteShell $remoteShell): RemoteLocalExecutor
{
    return new RemoteLocalExecutor(
        commands: new LocalExecutorCommandBuilder,
        operationTokens: new OperationTokenFactory(
            signer: new OperationTokenSigner,
            secret: 'gateway-secret',
            ttlSeconds: 120,
            clock: static fn (): int => 1_798_105_200,
        ),
        activityLogger: new ActivityLogger(new ActivityLogCorrelation),
        operationRuns: app(OperationRunRecorder::class),
        outputRedactor: app(\App\Services\RemoteShell\RemoteExecutorOutputRedactor::class),
        agentPush: app(\App\Services\NodeCommandTransport\NodeAgentPushDispatcher::class),
        gatewayLocal: app(\App\Services\RemoteShell\GatewayLocalCommandDispatcher::class),
        applicationKey: 'gateway-secret',
    );
}

final readonly class ToolInstallerGitHubAuthRemoteExecutor implements RemoteExecutor
{
    public function __construct(
        private RemoteShell $remoteShell,
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return $this->remoteShell->run($node, $script, $options);
    }

    public function start(Node $node, string $script, array $options = []): InvokedProcess
    {
        throw new RuntimeException('ToolInstallerGitHubAuthRemoteExecutor does not support start().');
    }
}

final class ToolInstallerGitHubAuthToolRunExecutor implements RunsInternalCommands
{
    public function __construct(
        private ToolInstallerGitHubAuthRecordingShell $shell,
    ) {}

    /**
     * @param  array<int|string, mixed>  $arguments
     * @param  array<int|string, mixed>  $commandOptions
     * @param  array<string, mixed>  $transportOptions
     */
    public function runInternal(
        Node $node,
        string $commandName,
        array $arguments = [],
        array $commandOptions = [],
        array $transportOptions = [],
    ): RemoteShellResult {
        if ($commandName === 'internal:secret-file') {
            return $this->shell->run(
                $node,
                implode(' ', [$commandName, ...array_map(strval(...), $arguments)]),
                [
                    'input' => $transportOptions['input'] ?? null,
                ],
            );
        }

        if ($commandName !== InternalCommand::ToolRunScript->value) {
            throw new RuntimeException("Unexpected internal command {$commandName}.");
        }

        return $this->shell->run(
            $node,
            $commandName,
            [
                'input' => $transportOptions['input'] ?? null,
            ],
        );
    }
}

final class ToolInstallerGitHubAuthRecordingShell implements RemoteShell
{
    /** @var list<string> */
    public array $commands = [];

    /** @var list<array<string, mixed>> */
    public array $options = [];

    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(
        private array $results,
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->commands[] = $script;
        $this->options[] = $options;

        $result = array_shift($this->results) ?? new RemoteShellResult(1, '', 'unexpected call', 1);

        if ($result->successful() && str_contains($script, 'internal:secret-file')) {
            return new RemoteShellResult(
                exitCode: $result->exitCode,
                stdout: json_encode([
                    'success' => [
                        'data' => trim($result->stdout) !== ''
                            ? ['path' => trim($result->stdout)]
                            : ['status' => 'removed'],
                        'meta' => [],
                    ],
                ], JSON_THROW_ON_ERROR),
                stderr: $result->stderr,
                durationMs: $result->durationMs,
            );
        }

        if ($result->successful() && $script === InternalCommand::ToolRunScript->value) {
            return new RemoteShellResult(
                exitCode: $result->exitCode,
                stdout: json_encode([
                    'success' => [
                        'data' => [
                            'exit_code' => 0,
                            'stdout' => '',
                            'stderr' => '',
                            'duration_ms' => 1,
                        ],
                        'meta' => [],
                    ],
                ], JSON_THROW_ON_ERROR),
                stderr: $result->stderr,
                durationMs: $result->durationMs,
            );
        }

        return $result;
    }
}
