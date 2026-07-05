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
use App\Services\NodeCommandTransport\NodeTransportPreference;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationTokenFactory;
use App\Services\RemoteShell\ExplicitRemoteShellFallback;
use App\Services\RemoteShell\LocalExecutorCommandBuilder;
use App\Services\RemoteShell\RemoteExecutor;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\Tools\ToolInstaller;
use App\Services\Tools\ToolUpdater;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orbit\Core\Security\OperationTokenSigner;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    request()->headers->set(ExplicitRemoteShellFallback::HEADER, ExplicitRemoteShellFallback::REQUIRED);
});

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

    $this->app->instance(RemoteShell::class, $shell);
    $this->app->instance(RemoteLocalExecutor::class, toolInstallerGitHubAuthLocalExecutor($shell));

    $result = app(ToolInstaller::class)->install('laravel-installer', node: 'app-dev-1');

    expect($result)
        ->toMatchArray([
            'name' => 'laravel-installer',
            'node' => 'app-dev-1',
            'state' => 'installed',
        ])
        ->and(json_decode($shell->options[0]['input'], true)['content_base64'] ?? null)
        ->toBe(base64_encode('ghp_unit_secret'))
        ->and($shell->scripts[0])
        ->toContain('internal:secret-file')
        ->and($shell->scripts[1])
        ->toContain("GITHUB_TOKEN_FILE='/tmp/orbit-secret.github'")
        ->and($shell->scripts[1])
        ->toContain('composer config --global github-oauth.github.com')
        ->and($shell->scripts[1])
        ->toContain('gh auth login --hostname github.com --with-token')
        ->and($shell->scripts[2])
        ->toContain("internal:secret-file 'remove'");

    foreach ($shell->scripts as $script) {
        expect($script)
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

    $this->app->instance(RemoteShell::class, $shell);
    $this->app->instance(RemoteLocalExecutor::class, toolInstallerGitHubAuthLocalExecutor($shell));

    $result = app(ToolUpdater::class)->update('laravel-installer', node: 'app-dev-1');

    expect($result)
        ->toMatchArray([
            'name' => 'laravel-installer',
            'node' => 'app-dev-1',
        ])
        ->and(json_decode($shell->options[0]['input'], true)['content_base64'] ?? null)
        ->toBe(base64_encode('ghp_update_secret'))
        ->and($shell->scripts[1])
        ->toContain("GITHUB_TOKEN_FILE='/tmp/orbit-secret.github'")
        ->and($shell->scripts[1])
        ->toContain('composer config --global github-oauth.github.com')
        ->and($shell->scripts[1])
        ->toContain('composer global update laravel/installer')
        ->and($shell->scripts[1])
        ->toContain('gh auth login --hostname github.com --with-token')
        ->and($shell->scripts[2])
        ->toContain("internal:secret-file 'remove'");

    foreach ($shell->scripts as $script) {
        expect($script)
            ->not->toContain('ghp_update_secret')
            ->not->toContain('php -r');
    }
});

function toolInstallerGitHubAuthLocalExecutor(RemoteShell $remoteShell): RemoteLocalExecutor
{
    return new RemoteLocalExecutor(
        transport: new ToolInstallerGitHubAuthRemoteExecutor($remoteShell),
        commands: new LocalExecutorCommandBuilder,
        operationTokens: new OperationTokenFactory(
            signer: new OperationTokenSigner,
            secret: 'gateway-secret',
            ttlSeconds: 120,
            clock: static fn (): int => 1_798_105_200,
        ),
        activityLogger: new ActivityLogger(new ActivityLogCorrelation),
        operationRuns: app(OperationRunRecorder::class),
        operationTokenSecret: 'gateway-secret',
        defaultTransportPreference: NodeTransportPreference::TransitionalSshFallback,
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

final class ToolInstallerGitHubAuthRecordingShell implements RemoteShell
{
    /** @var list<string> */
    public array $scripts = [];

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
        $this->scripts[] = $script;
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

        return $result;
    }
}
