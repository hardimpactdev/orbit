<?php

declare(strict_types=1);

use App\Actions\Workspaces\CreateWorkspace;
use App\Actions\Workspaces\CreateWorkspacePlan;
use App\Actions\Workspaces\CreateWorkspaceProgress;
use App\Actions\Workspaces\CreateWorkspaceResult;
use App\Actions\Workspaces\SetupWorkspace;
use App\Actions\Workspaces\SetupWorkspacePlan;
use App\Actions\Workspaces\SetupWorkspaceProgress;
use App\Actions\Workspaces\SetupWorkspaceResult;
use App\Contracts\ProgressReporter;
use App\Contracts\RemoteShell;
use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppRuntimeKind;
use App\Enums\WorkspaceLifecyclePhase;
use App\Enums\WorkspaceLifecycleStatus;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Models\WorkspaceStep;
use App\Services\RemoteShell\RunsInternalCommands;
use App\Support\Streaming\NullProgressReporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $node = createTestAppHostNode([
        'name' => 'app-1',
        'wireguard_address' => '10.6.0.7',
    ]);

    $app = App::factory()->create([
        'name' => 'demo',
        'php_version' => '8.5',
        'runtime' => AppRuntimeKind::Php,
    ]);

    Instance::factory()->for($app)->create([
        'name' => 'development',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            path: '/home/orbit/apps/demo',
            document_root: 'public',
            domain: 'demo.test',
        ),
    ]);

    app()->instance(RemoteShell::class, new WorkspacePlanParityShell);
    app()->instance(RunsInternalCommands::class, new WorkspacePlanParityInternalCommands);
    Http::fake(['*' => Http::response('', 200)]);
});

it('executes one ordered setup plan with the same final result for JSON and SSE adapters', function (string $adapter): void {
    $workspace = workspace_plan_parity_workspace();
    $app = App::query()->where('name', 'demo')->firstOrFail();
    $node = Node::query()->where('name', 'app-1')->firstOrFail();
    $reporter = workspace_plan_parity_reporter($adapter);

    $plan = workspace_plan_parity_setup_plan($adapter, $app, $workspace, $node);
    $result = $plan->run($reporter);

    expect($plan)
        ->toBeInstanceOf(SetupWorkspacePlan::class)
        ->and($result)
        ->toBeInstanceOf(SetupWorkspaceResult::class)
        ->and($result->isSuccessful())
        ->toBeTrue()
        ->and($result->completedSteps())
        ->toBe([
            'apply_workspace_registration',
            'register_proxy_routes',
            'initialize_workspace_environment',
            'install_workspace_runtime_container',
            'check_workspace_readiness',
        ])
        ->and($result->data())
        ->toMatchArray([
            'app' => 'demo',
            'instance' => 'development',
            'workspace' => 'feature-a',
            'node' => 'app-1',
            'path' => '/home/orbit/apps/demo/.worktrees/feature-a',
            'action' => 'set_up',
        ]);

    workspace_plan_parity_expect_reporter($reporter, $result->completedSteps());
})->with(['json', 'sse']);

it('returns the same setup-step failure and retains completed phases for JSON and SSE adapters', function (string $adapter): void {
    $workspace = workspace_plan_parity_workspace();
    $app = App::query()->where('name', 'demo')->firstOrFail();
    $node = Node::query()->where('name', 'app-1')->firstOrFail();

    WorkspaceStep::create([
        'app_id' => $app->id,
        'instance_id' => $workspace->instance_id,
        'phase' => WorkspaceLifecyclePhase::Setup,
        'sort_order' => 1,
        'command' => 'exit 1',
        'timeout_seconds' => 60,
    ]);

    app()->instance(RunsInternalCommands::class, new WorkspacePlanParityInternalCommands(failSetupStep: true));

    $reporter = workspace_plan_parity_reporter($adapter);
    $result = workspace_plan_parity_setup_plan($adapter, $app, $workspace, $node)->run($reporter);

    expect($result->isSuccessful())
        ->toBeFalse()
        ->and($result->failure())
        ->toMatchArray([
            'code' => 'workspace.setup_step_failed',
            'meta' => [
                'step' => 'exit 1',
                'exit_code' => 1,
                'node' => 'app-1',
                'path' => '/home/orbit/apps/demo/.worktrees/feature-a',
                'phase' => 'setup_steps',
            ],
        ])
        ->and($result->completedSteps())
        ->toBe([
            'apply_workspace_registration',
            'register_proxy_routes',
            'initialize_workspace_environment',
            'install_workspace_runtime_container',
        ]);

    expect($workspace->refresh()->lifecycle_status)
        ->toBe(WorkspaceLifecycleStatus::SetupPending)
        ->and(ProxyRoute::query()->where('workspace_id', $workspace->id)->exists())
        ->toBeTrue();

    workspace_plan_parity_expect_reporter($reporter, [
        ...$result->completedSteps(),
        'run_workspace_setup_steps',
    ]);
})->with(['json', 'sse']);

it('executes one ordered create plan with the same final result for JSON and SSE adapters', function (string $adapter): void {
    $app = App::query()->where('name', 'demo')->firstOrFail();
    $instance = Instance::query()->where('app_id', $app->id)->firstOrFail();
    $reporter = workspace_plan_parity_reporter($adapter);

    $plan = workspace_plan_parity_create_plan($adapter, $app, $instance, name: 'feature-a');
    $result = $plan->run($reporter);

    expect($plan)
        ->toBeInstanceOf(CreateWorkspacePlan::class)
        ->and($result)
        ->toBeInstanceOf(CreateWorkspaceResult::class)
        ->and($result->isSuccessful())
        ->toBeTrue()
        ->and($result->completedSteps())
        ->toBe([
            'provision_workspace_source',
            'apply_workspace_registration',
            'register_proxy_routes',
            'initialize_workspace_environment',
            'install_workspace_runtime_container',
            'run_workspace_setup_steps',
            'render_inherited_runtime_units',
            'check_workspace_readiness',
        ])
        ->and($result->data()['result'])
        ->toBe(['action' => 'created'])
        ->and($result->data()['workspace']['name'])
        ->toBe('feature-a')
        ->and($result->data()['workspace']['app'])
        ->toBe('demo')
        ->and($result->data()['workspace']['instance'])
        ->toBe('development')
        ->and($result->data()['workspace']['node'])
        ->toBe('app-1')
        ->and($result->data()['workspace']['path'])
        ->toBe('/home/orbit/apps/demo/.worktrees/feature-a')
        ->and($result->data()['meta']['node'])
        ->toBe('app-1')
        ->and($result->data()['meta']['base'])
        ->toBe('main');

    workspace_plan_parity_expect_reporter($reporter, $result->completedSteps());
})->with(['json', 'sse']);

it('returns the same create failure and retains source plus intent for JSON and SSE adapters', function (string $adapter): void {
    $app = App::query()->where('name', 'demo')->firstOrFail();
    $instance = Instance::query()->where('app_id', $app->id)->firstOrFail();

    WorkspaceStep::create([
        'app_id' => $app->id,
        'instance_id' => $instance->id,
        'phase' => WorkspaceLifecyclePhase::Setup,
        'sort_order' => 1,
        'command' => 'exit 1',
        'timeout_seconds' => 60,
    ]);

    app()->instance(RunsInternalCommands::class, new WorkspacePlanParityInternalCommands(failSetupStep: true));

    $result = workspace_plan_parity_create_plan($adapter, $app, $instance, name: 'feature-a')
        ->run(workspace_plan_parity_reporter($adapter));

    expect($result->isSuccessful())
        ->toBeFalse()
        ->and($result->failure()['code'])
        ->toBe('workspace.enactment_failed')
        ->and($result->failure()['meta']['step'])
        ->toBe('setup_pipeline')
        ->and($result->failure()['meta']['node'])
        ->toBe('app-1');

    $workspace = Workspace::query()->where('name', 'feature-a')->firstOrFail();

    expect($workspace->lifecycle_status)
        ->toBe(WorkspaceLifecycleStatus::SetupPending)
        ->and(ProxyRoute::query()->where('workspace_id', $workspace->id)->exists())
        ->toBeTrue();
})->with(['json', 'sse']);

it('rolls back create intent consistently when source provisioning fails for JSON and SSE adapters', function (string $adapter): void {
    $app = App::query()->where('name', 'demo')->firstOrFail();
    $instance = Instance::query()->where('app_id', $app->id)->firstOrFail();
    app()->instance(RunsInternalCommands::class, new WorkspacePlanParityInternalCommands(failSource: true));

    $result = workspace_plan_parity_create_plan($adapter, $app, $instance, name: 'feature-a')
        ->run(workspace_plan_parity_reporter($adapter));

    expect($result->isSuccessful())
        ->toBeFalse()
        ->and($result->failure()['code'])
        ->toBe('workspace.source_create_failed')
        ->and($result->failure()['meta']['workspace'])
        ->toBe('feature-a')
        ->and(Workspace::query()->where('name', 'feature-a')->exists())
        ->toBeFalse();
})->with(['json', 'sse']);

function workspace_plan_parity_workspace(): Workspace
{
    $app = App::query()->where('name', 'demo')->firstOrFail();
    $instance = Instance::query()->where('app_id', $app->id)->firstOrFail();

    return Workspace::create([
        'app_id' => $app->id,
        'instance_id' => $instance->id,
        'name' => 'feature-a',
        'path' => '/home/orbit/apps/demo/.worktrees/feature-a',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);
}

function workspace_plan_parity_setup_plan(
    string $adapter,
    App $app,
    Workspace $workspace,
    Node $node,
): SetupWorkspacePlan {
    if ($adapter === 'sse') {
        return app(SetupWorkspaceProgress::class)->for($workspace, $app, $node, false);
    }

    return app(SetupWorkspace::class)->plan($app, $workspace, $node);
}

function workspace_plan_parity_create_plan(
    string $adapter,
    App $app,
    Instance $instance,
    string $name,
): CreateWorkspacePlan {
    if ($adapter === 'sse') {
        return app(CreateWorkspaceProgress::class)->for($app, $name, 'main', null, $instance);
    }

    return app(CreateWorkspace::class)->plan($app, $name, $instance, 'main');
}

function workspace_plan_parity_reporter(string $adapter): ProgressReporter
{
    return $adapter === 'sse'
        ? new WorkspacePlanParityReporter
        : new NullProgressReporter;
}

/** @param list<string> $expectedTerminalSteps */
function workspace_plan_parity_expect_reporter(ProgressReporter $reporter, array $expectedTerminalSteps): void
{
    if (! $reporter instanceof WorkspacePlanParityReporter) {
        return;
    }

    expect(array_slice($reporter->plannedSteps, offset: 0, length: count($expectedTerminalSteps)))
        ->toBe($expectedTerminalSteps)
        ->and($reporter->terminalSteps)
        ->toBe($expectedTerminalSteps);
}

final class WorkspacePlanParityReporter implements ProgressReporter
{
    /** @var list<string> */
    public array $plannedSteps = [];

    /** @var list<string> */
    public array $terminalSteps = [];

    public function tree(string $title, array $steps): void
    {
        $this->plannedSteps = array_column($steps, 'key');
    }

    public function stepStart(string $key): void {}

    public function stepProgress(string $key, string $status, ?string $message = null): void {}

    public function stepDone(string $key, ?string $message = null): void
    {
        $this->terminalSteps[] = $key;
    }

    public function stepFail(string $key, string $message): void
    {
        $this->terminalSteps[] = $key;
    }

    public function stepSkip(string $key, ?string $message = null): void
    {
        $this->terminalSteps[] = $key;
    }
}

/** @mago-expect lint:single-class-per-file */
final class WorkspacePlanParityShell implements RemoteShell
{
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

/** @mago-expect lint:single-class-per-file */
final readonly class WorkspacePlanParityInternalCommands implements RunsInternalCommands
{
    public function __construct(
        private bool $failSetupStep = false,
        private bool $failSource = false,
    ) {}

    public function runInternal(
        Node $node,
        string $commandName,
        array $arguments = [],
        array $commandOptions = [],
        array $transportOptions = [],
    ): RemoteShellResult {
        if ($this->failSource && $commandName === 'internal:workspace-source:create') {
            return new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'source failed', durationMs: 1);
        }

        if ($this->failSetupStep && $commandName === 'internal:workspace-setup-step') {
            return new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'step failed', durationMs: 1);
        }

        if ($commandName === 'internal:workspace-setup-step') {
            return new RemoteShellResult(
                exitCode: 0,
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
                ], JSON_THROW_ON_ERROR)
                    ."\n",
                stderr: '',
                durationMs: 1,
            );
        }

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
