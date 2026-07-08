<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
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

function workspace_setup_step_runner_test_app(Node $node): App
{
    return App::factory()->create([
        'name' => 'demo',
        'node_id' => $node->id,
        'path' => '/home/orbit/apps/demo',
        'php_version' => '8.5',
    ]);
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

it('routes setup commands through the selected workspace host toolchain', function (): void {
    allow_workspace_setup_remote_shell_fallback();
    $run = WorkspaceRun::factory()->create(['status' => 'pending']);
    $node = Node::query()->firstOrFail();
    $app = workspace_setup_step_runner_test_app($node);
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
    $result = $runner->run($run, $steps, '/app/path', $env, $node, $app);

    expect($result)->toBeTrue();

    $composerRun = $shell->runs[0];
    expect($composerRun['script'])
        ->toContain("'sudo'")
        ->toContain("'-u'")
        ->toContain("'orbit'")
        ->toContain('/opt/orbit/php/')
        ->toContain("cd '\\''/app/path'\\''")
        ->toContain('composer install')
        ->and($composerRun['options']['cwd'])
        ->toBe('/app/path');

    $artisanRun = $shell->runs[1];
    expect($artisanRun['script'])
        ->toContain("'sudo'")
        ->toContain('/opt/orbit/php/')
        ->toContain("cd '\\''/app/path'\\''")
        ->toContain('php artisan migrate');
    expect($artisanRun['options']['cwd'])->toBe('/app/path');

    $npmRun = $shell->runs[2];
    expect($npmRun['script'])
        ->toContain("'sudo'")
        ->toContain('/home/orbit/.local/bin')
        ->toContain('/home/orbit/.vite-plus/bin')
        ->toContain("cd '\\''/app/path'\\''")
        ->toContain('npm ci');
    expect($npmRun['options']['cwd'])->toBe('/app/path');
});

it('routes workspace lifecycle commands through the selected node home when app canonical node differs', function (): void {
    allow_workspace_setup_remote_shell_fallback();
    $run = WorkspaceRun::factory()->create(['status' => 'pending']);
    assert($run instanceof WorkspaceRun, description: 'Workspace run factory must return a WorkspaceRun model.');

    $canonicalNode = new Node([
        'name' => 'beast',
        'host' => 'beast.test',
        'platform' => 'ubuntu_24-04',
        'user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
    ]);
    $canonicalNode->save();

    $selectedNode = new Node([
        'name' => 'NMBP',
        'host' => 'nmbp.test',
        'platform' => 'macos_26-5-1',
        'user' => 'nckrtl',
        'orbit_path' => '/Users/nckrtl/orbit',
        'status' => 'active',
    ]);
    $selectedNode->save();

    $app = new App([
        'name' => 'demo',
        'node_id' => $canonicalNode->id,
        'path' => '/home/nckrtl/apps/demo',
        'php_version' => '8.5',
    ]);
    $app->setRelation('node', $canonicalNode);
    $shell = new WorkspaceSetupStepRunnerTestShell;

    $runner = new WorkspaceSetupStepRunner($shell);

    $steps = [
        new WorkspaceStep([
            'id' => 1,
            'command' => 'vp install',
            'timeout_seconds' => 120,
        ]),
    ];

    $result = $runner->run(
        $run,
        $steps,
        '/Users/nckrtl/.codex/worktrees/a59f/happie',
        ['ORBIT_APP' => 'happie'],
        $selectedNode,
        $app,
    );

    expect($result)
        ->toBeTrue()
        ->and($shell->runs[0]['script'])
        ->toContain("'sudo'")
        ->toContain("'-u'")
        ->toContain("'nckrtl'")
        ->toContain('/Users/nckrtl/.vite-plus/bin')
        ->toContain('/Users/nckrtl/.codex/worktrees/a59f/happie')
        ->toContain('vp install');
    expect(str_contains($shell->runs[0]['script'], '/home/nckrtl/.vite-plus/bin'))->toBeFalse();
});

it('passes lifecycle environment into host-routed php setup commands', function (): void {
    allow_workspace_setup_remote_shell_fallback();
    $run = WorkspaceRun::factory()->create(['status' => 'pending']);
    $node = Node::query()->firstOrFail();
    $app = workspace_setup_step_runner_test_app($node);
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
    $runner->run($run, $steps, '/app/path', $env, $node, $app);

    expect($shell->runs[0]['script'])
        ->toContain('ORBIT_APP=')
        ->toContain('demo')
        ->toContain('VITE_APP_URL=')
        ->toContain('https://feature.demo.test');
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

it('routes php setup commands before dispatching through the local executor', function (): void {
    $run = WorkspaceRun::factory()->create(['status' => 'pending']);
    $node = Node::factory()
        ->appDev()
        ->orbitAgentCapable()
        ->create([
            'name' => 'agent-node',
            'host' => 'agent-node',
            'user' => 'orbit',
        ]);
    $app = workspace_setup_step_runner_test_app($node);
    $shell = new WorkspaceSetupStepRunnerTestShell;
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

    $runner = new WorkspaceSetupStepRunner(
        remoteShell: $shell,
        localExecutor: workspace_setup_step_runner_local_executor($transport),
    );

    $steps = [
        new WorkspaceStep([
            'id' => 1,
            'command' => 'composer install',
            'timeout_seconds' => 60,
        ]),
    ];

    $result = $runner->run(
        $run,
        $steps,
        '/Users/nckrtl/.codex/worktrees/a59f/happie',
        ['ORBIT_APP' => 'happie'],
        $node,
        $app,
    );

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
        ->not
        ->toContain('docker exec')
        ->and($payload['cwd'])
        ->toBe('/Users/nckrtl/.codex/worktrees/a59f/happie');
});

it('routes managed host tools before dispatching through the local executor', function (): void {
    $run = WorkspaceRun::factory()->create(['status' => 'pending']);
    $node = Node::factory()
        ->appDev()
        ->orbitAgentCapable()
        ->create([
            'name' => 'agent-node',
            'host' => 'agent-node',
            'user' => 'orbit',
        ]);
    $app = workspace_setup_step_runner_test_app($node);
    $shell = new WorkspaceSetupStepRunnerTestShell;
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

    $runner = new WorkspaceSetupStepRunner(
        remoteShell: $shell,
        localExecutor: workspace_setup_step_runner_local_executor($transport),
    );

    $steps = [
        new WorkspaceStep([
            'id' => 1,
            'command' => 'vp install',
            'timeout_seconds' => 60,
        ]),
    ];

    $result = $runner->run(
        $run,
        $steps,
        '/Users/nckrtl/.codex/worktrees/a59f/happie',
        ['ORBIT_APP' => 'happie'],
        $node,
        $app,
    );

    $payload = json_decode(
        (string) $transport->runs[0]['options']['input'],
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($result)
        ->toBeTrue()
        ->and($payload['command'])
        ->toContain("'sudo'")
        ->toContain('/home/orbit/.local/bin')
        ->toContain('/home/orbit/.vite-plus/bin')
        ->toContain('vp install')
        ->not
        ->toContain('docker exec')
        ->and($payload['cwd'])
        ->toBe('/Users/nckrtl/.codex/worktrees/a59f/happie');
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
