<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\WorkspaceRun;
use App\Models\WorkspaceStep;
use App\Services\ActivityLogCorrelation;
use App\Services\ActivityLogger;
use App\Services\NodeCommandTransport\NodeTransportPreference;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationTokenFactory;
use App\Services\RemoteShell\ExplicitRemoteShellFallback;
use App\Services\RemoteShell\LocalExecutorCommandBuilder;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\Workspaces\WorkspaceSetupStepRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Tests\Fakes\WorkspaceSetupStepRunnerExecutorTransport;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    request()->headers->remove(ExplicitRemoteShellFallback::HEADER);

    DB::table('nodes')->insert([
        'name' => 'app-1',
        'host' => 'app-1',
        'orbit_path' => '/home/orbit/orbit',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

afterEach(function (): void {
    request()->headers->remove(ExplicitRemoteShellFallback::HEADER);
});

function allow_workspace_setup_remote_shell_fallback(): void
{
    request()->headers->set(ExplicitRemoteShellFallback::HEADER, ExplicitRemoteShellFallback::REQUIRED);
}

function workspace_setup_step_runner_local_executor(
    WorkspaceSetupStepRunnerExecutorTransport $transport,
): RemoteLocalExecutor {
    return new RemoteLocalExecutor(
        transport: $transport,
        commands: new LocalExecutorCommandBuilder,
        operationTokens: new OperationTokenFactory(
            signer: new OperationTokenSigner,
            secret: workspace_setup_step_runner_signing_key(),
            ttlSeconds: 120,
            clock: static fn (): int => 1_798_105_200,
        ),
        activityLogger: new ActivityLogger(new ActivityLogCorrelation),
        operationRuns: app(OperationRunRecorder::class),
        applicationKey: workspace_setup_step_runner_signing_key(),
        defaultTransportPreference: NodeTransportPreference::TransitionalSshFallback,
    );
}

function workspace_setup_step_runner_signing_key(): string
{
    return hash('sha256', WorkspaceSetupStepRunner::class);
}

it('executes setup steps sequentially on the host by default', function (): void {
    allow_workspace_setup_remote_shell_fallback();
    $run = WorkspaceRun::factory()->create(['status' => 'pending']);
    $node = Node::query()->firstOrFail();
    $shell = new WorkspaceSetupStepRunnerTestShell;

    $runner = new WorkspaceSetupStepRunner($shell);

    $steps = [
        new WorkspaceStep([
            'id' => 1,
            'command' => 'echo first',
            'timeout_seconds' => 60,
        ]),
        new WorkspaceStep([
            'id' => 2,
            'command' => 'echo second',
            'timeout_seconds' => 60,
        ]),
    ];

    $result = $runner->run($run, $steps, '/app/path', ['ORBIT_APP' => 'demo'], $node);

    expect($result)
        ->toBeTrue()
        ->and($shell->runs)
        ->toHaveCount(2)
        ->and($shell->runs[0]['script'])
        ->toBe('echo first')
        ->and($shell->runs[0]['options']['cwd'])
        ->toBe('/app/path')
        ->and($shell->runs[1]['script'])
        ->toBe('echo second')
        ->and($shell->runs[1]['options']['cwd'])
        ->toBe('/app/path');

    $run->refresh();
    expect($run->status)->toBe('completed');
});

it('routes php and composer commands through the workspace container when given a container name', function (): void {
    allow_workspace_setup_remote_shell_fallback();
    $run = WorkspaceRun::factory()->create(['status' => 'pending']);
    $node = Node::query()->firstOrFail();
    $shell = new WorkspaceSetupStepRunnerTestShell;

    $runner = new WorkspaceSetupStepRunner($shell);

    $steps = [
        new WorkspaceStep([
            'id' => 1,
            'command' => 'composer install',
            'timeout_seconds' => 120,
        ]),
        new WorkspaceStep([
            'id' => 2,
            'command' => 'php artisan migrate',
            'timeout_seconds' => 60,
        ]),
        new WorkspaceStep([
            'id' => 3,
            'command' => 'npm ci',
            'timeout_seconds' => 300,
        ]),
    ];

    $env = ['ORBIT_APP' => 'demo', 'ORBIT_WORKSPACE_NAME' => 'feature'];
    $result = $runner->run($run, $steps, '/app/path', $env, $node, 'orbit-ws-demo-feature');

    expect($result)->toBeTrue();

    $composerRun = $shell->runs[0];
    expect($composerRun['script'])
        ->toContain("'docker'")
        ->toContain("'exec'")
        ->toContain("'orbit-ws-demo-feature'")
        ->toContain("'composer install'")
        ->toContain("'-w'")
        ->toContain("'/app'");
    expect($composerRun['options']['cwd'] ?? null)->toBeNull();

    $artisanRun = $shell->runs[1];
    expect($artisanRun['script'])
        ->toContain("'docker'")
        ->toContain("'exec'")
        ->toContain("'orbit-ws-demo-feature'")
        ->toContain("'php artisan migrate'")
        ->toContain("'-w'")
        ->toContain("'/app'");
    expect($artisanRun['options']['cwd'] ?? null)->toBeNull();

    $npmRun = $shell->runs[2];
    expect($npmRun['script'])->toBe('npm ci');
    expect($npmRun['options']['cwd'])->toBe('/app/path');
});

it('passes lifecycle environment into containerized commands via docker exec -e', function (): void {
    allow_workspace_setup_remote_shell_fallback();
    $run = WorkspaceRun::factory()->create(['status' => 'pending']);
    $node = Node::query()->firstOrFail();
    $shell = new WorkspaceSetupStepRunnerTestShell;

    $runner = new WorkspaceSetupStepRunner($shell);

    $steps = [
        new WorkspaceStep([
            'id' => 1,
            'command' => 'composer install',
            'timeout_seconds' => 120,
        ]),
    ];

    $env = ['ORBIT_APP' => 'demo', 'VITE_APP_URL' => 'https://feature.demo.test'];
    $runner->run($run, $steps, '/app/path', $env, $node, 'orbit-ws-demo-feature');

    expect($shell->runs[0]['script'])
        ->toContain("'ORBIT_APP=demo'")
        ->toContain("'VITE_APP_URL=https://feature.demo.test'");
});

it('fails fast on first non-zero exit and records the failed step', function (): void {
    allow_workspace_setup_remote_shell_fallback();
    $run = WorkspaceRun::factory()->create(['status' => 'pending']);
    $node = Node::query()->firstOrFail();
    $shell = new WorkspaceSetupStepRunnerFailingShell(failAfter: 0);

    $runner = new WorkspaceSetupStepRunner($shell);

    $steps = [
        new WorkspaceStep([
            'id' => 1,
            'command' => 'exit 1',
            'timeout_seconds' => 60,
        ]),
        new WorkspaceStep([
            'id' => 2,
            'command' => 'echo second',
            'timeout_seconds' => 60,
        ]),
    ];

    $result = $runner->run($run, $steps, '/app/path', [], $node);

    expect($result)->toBeFalse()->and($shell->runs)->toHaveCount(1);

    $run->refresh();
    expect($run->status)->toBe('failed');

    $failedStep = $run->runSteps()->first();
    expect($failedStep)
        ->not
        ->toBeNull()
        ->and($failedStep->exit_code)
        ->toBe(1);
});

it('reports progress events for each step', function (): void {
    allow_workspace_setup_remote_shell_fallback();
    $run = WorkspaceRun::factory()->create(['status' => 'pending']);
    $node = Node::query()->firstOrFail();
    $shell = new WorkspaceSetupStepRunnerTestShell;

    $runner = new WorkspaceSetupStepRunner($shell);

    $steps = [
        new WorkspaceStep([
            'id' => 1,
            'command' => 'echo first',
            'timeout_seconds' => 60,
        ]),
    ];

    $events = [];
    $runner->run($run, $steps, '/app/path', [], $node, null, function (
        string $event,
        WorkspaceStep $step,
        int $index,
        int $count,
    ) use (&$events): void {
        $events[] = [$event, $step->command, $index, $count];
    });

    expect($events)->toBe([
        ['running',   'echo first', 1, 1],
        ['completed', 'echo first', 1, 1],
    ]);
});

it('reports failed progress event when a step fails', function (): void {
    allow_workspace_setup_remote_shell_fallback();
    $run = WorkspaceRun::factory()->create(['status' => 'pending']);
    $node = Node::query()->firstOrFail();
    $shell = new WorkspaceSetupStepRunnerFailingShell(failAfter: 0);

    $runner = new WorkspaceSetupStepRunner($shell);

    $steps = [
        new WorkspaceStep([
            'id' => 1,
            'command' => 'exit 1',
            'timeout_seconds' => 60,
        ]),
    ];

    $events = [];
    $runner->run($run, $steps, '/app/path', [], $node, null, function (
        string $event,
        WorkspaceStep $step,
        int $index,
        int $count,
    ) use (&$events): void {
        $events[] = [$event, $step->command, $index, $count];
    });

    expect($events)->toBe([
        ['running', 'exit 1', 1, 1],
        ['failed',  'exit 1', 1, 1],
    ]);
});

it('runs setup steps through the local executor by default for agent capable nodes', function (): void {
    $run = WorkspaceRun::factory()->create(['status' => 'pending']);
    $node = Node::factory()
        ->appDev()
        ->orbitAgentCapable()
        ->create([
            'name' => 'agent-node',
            'host' => 'agent-node',
        ]);
    $shell = new WorkspaceSetupStepRunnerTestShell;
    $transport = new WorkspaceSetupStepRunnerExecutorTransport(new RemoteShellResult(
        exitCode: 0,
        stdout: json_encode(JsonEnvelope::success([
            'exit_code' => 0,
            'stdout' => "created\n",
            'stderr' => '',
            'duration_ms' => 12,
        ]), JSON_THROW_ON_ERROR),
        stderr: '',
        durationMs: 14,
    ));

    $runner = new WorkspaceSetupStepRunner(
        remoteShell: $shell,
        localExecutor: workspace_setup_step_runner_local_executor($transport),
    );

    $steps = [
        new WorkspaceStep([
            'id' => 1,
            'command' => 'test -f .env || cp .env.example .env',
            'timeout_seconds' => 60,
        ]),
    ];

    $result = $runner->run($run, $steps, '/Users/nckrtl/apps/happie', ['ORBIT_APP' => 'happie'], $node);

    $runStep = $run->runSteps()->first();

    expect($result)
        ->toBeTrue()
        ->and($shell->runs)
        ->toBeEmpty()
        ->and($transport->runs)
        ->toHaveCount(1)
        ->and($transport->runs[0]['script'])
        ->toContain('internal:workspace-setup-step')
        ->and($transport->runs[0]['script'])
        ->toContain('--operation-token=')
        ->and($runStep?->exit_code)
        ->toBe(0)
        ->and($runStep?->output)
        ->toBe("created\n");
});

it('requires a local executor or explicit transitional ssh fallback before running workspace setup commands', function (): void {
    $run = WorkspaceRun::factory()->create(['status' => 'pending']);
    $node = Node::query()->firstOrFail();
    $shell = new WorkspaceSetupStepRunnerTestShell;

    $runner = new WorkspaceSetupStepRunner($shell);

    $steps = [
        new WorkspaceStep([
            'id' => 1,
            'command' => 'echo first',
            'timeout_seconds' => 60,
        ]),
    ];

    $result = $runner->run($run, $steps, '/app/path', ['ORBIT_APP' => 'demo'], $node);

    $run->refresh();
    $runStep = $run->runSteps()->first();

    expect($result)
        ->toBeFalse()
        ->and($shell->runs)
        ->toBeEmpty()
        ->and($run->status)
        ->toBe('failed')
        ->and($runStep?->exit_code)
        ->toBe(1)
        ->and($runStep?->output)
        ->toContain('requires an Orbit Agent capable node or explicit --node-transport=transitional-ssh-fallback');
});

final class WorkspaceSetupStepRunnerTestShell implements RemoteShell
{
    /** @var list<array{script: string, options: array<string, mixed>}> */
    public array $runs = [];

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->runs[] = ['script' => $script, 'options' => $options];

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

final class WorkspaceSetupStepRunnerFailingShell implements RemoteShell
{
    public int $callCount = 0;

    /** @var list<array{script: string, options: array<string, mixed>}> */
    public array $runs = [];

    public function __construct(
        private int $failAfter,
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->callCount++;
        $this->runs[] = ['script' => $script, 'options' => $options];

        if ($this->callCount > $this->failAfter) {
            return new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'failed', durationMs: 1);
        }

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
