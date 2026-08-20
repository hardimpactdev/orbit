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
use App\Contracts\SiteCertificateInstaller;
use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppRuntimeKind;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\WorkspaceLifecyclePhase;
use App\Enums\WorkspaceLifecycleStatus;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Models\WorkspaceRunStep;
use App\Models\WorkspaceStep;
use App\Services\RemoteShell\RunsInternalCommands;
use App\Services\Workspaces\WorkspaceReadinessProbe;
use App\Support\Streaming\NullProgressReporter;
use App\Support\Streaming\ProgressEventStreamResponseFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const WORKSPACE_PLAN_PARITY_CALLER_WG_IP = '10.6.0.99';

beforeEach(function (): void {
    createTestGatewayNode([
        'name' => 'gateway',
        'wireguard_address' => WORKSPACE_PLAN_PARITY_CALLER_WG_IP,
    ]);

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
        'php_version' => '8.5',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            path: '/home/orbit/apps/demo',
            document_root: 'public',
            domain: 'demo.test',
        ),
    ]);

    app()->instance(RemoteShell::class, new WorkspacePlanParityShell);
    app()->instance(RunsInternalCommands::class, new WorkspacePlanParityInternalCommands);
    app()->instance(SiteCertificateInstaller::class, new WorkspacePlanParityCertificateInstaller);
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
        ->and($result->data()['result'])
        ->toBe(['action' => 'set_up'])
        ->and($result->data()['workspace'])
        ->toMatchArray([
            'name' => 'feature-a',
            'app' => 'demo',
            'instance' => 'development',
            'node' => 'app-1',
            'path' => '/home/orbit/apps/demo/.worktrees/feature-a',
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
                'reason' => 'setup_step_failed',
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

it('returns matching create success envelopes and ordered state transitions through JSON and SSE endpoints', function (string $adapter): void {
    $name = "feature-{$adapter}";
    $response = workspace_plan_parity_endpoint_request($adapter, uri: '/api/workspaces', payload: [
        'name' => $name,
        'instance' => 'demo.development',
        'base' => 'main',
    ]);

    if ($adapter === 'json') {
        $response
            ->assertCreated()
            ->assertJsonPath('success.data.result.action', 'created')
            ->assertJsonPath('success.data.workspace.name', $name)
            ->assertJsonPath('success.data.workspace.lifecycle_status', 'expected')
            ->assertJsonPath('success.meta.base', 'main');
    }

    if ($adapter === 'sse') {
        $response->assertSuccessful();
        $terminal = workspace_plan_parity_expect_sse_envelope(
            $response,
            [
                'provision_workspace_source',
                'apply_workspace_registration',
                'register_proxy_routes',
                'initialize_workspace_environment',
                'install_workspace_runtime_container',
                'run_workspace_setup_steps',
                'render_inherited_runtime_units',
                'check_workspace_readiness',
            ],
            [
                'provision_workspace_source',
                'apply_workspace_registration',
                'register_proxy_routes',
                'initialize_workspace_environment',
                'install_workspace_runtime_container',
                'run_workspace_setup_steps',
                'render_inherited_runtime_units',
                'check_workspace_readiness',
            ],
            expectedTerminalEvent: 'complete',
        );

        expect($terminal['data']['success']['data']['result']['action'] ?? null)
            ->toBe('created')
            ->and($terminal['data']['success']['data']['workspace']['name'] ?? null)
            ->toBe($name)
            ->and($terminal['data']['success']['data']['workspace']['lifecycle_status'] ?? null)
            ->toBe('expected')
            ->and($terminal['data']['success']['meta']['base'] ?? null)
            ->toBe('main');
    }

    $workspace = Workspace::query()->where('name', $name)->firstOrFail();

    expect($workspace->lifecycle_status)
        ->toBe(WorkspaceLifecycleStatus::Expected)
        ->and(ProxyRoute::query()->where('workspace_id', $workspace->id)->exists())
        ->toBeTrue();
})->with(['json', 'sse']);

it('returns matching create registration failures and retained source state through JSON and SSE endpoints', function (string $adapter): void {
    $name = "registration-{$adapter}";
    Exceptions::fake();

    Workspace::creating(function (): never {
        throw new RuntimeException('sensitive registration detail');
    });

    $response = workspace_plan_parity_endpoint_request($adapter, uri: '/api/workspaces', payload: [
        'name' => $name,
        'instance' => 'demo.development',
        'base' => 'main',
    ]);
    $path = "/home/orbit/apps/demo/.worktrees/{$name}";
    $expectedNextCommand =
        'orbit workspace:setup '
        .escapeshellarg($name)
        .' --instance='
        .escapeshellarg('demo.development')
        .' --path='
        .escapeshellarg($path);

    if ($adapter === 'json') {
        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'workspace.registration_failed')
            ->assertJsonPath('error.message', 'Workspace source was created, but registration failed.')
            ->assertJsonPath('error.meta.step', 'apply_workspace_registration')
            ->assertJsonPath('error.meta.partial_state', 'source_retained')
            ->assertJsonPath('error.meta.next_command', $expectedNextCommand);
    }

    if ($adapter === 'sse') {
        $response->assertSuccessful();
        $terminal = workspace_plan_parity_expect_sse_envelope(
            $response,
            [
                'provision_workspace_source',
                'apply_workspace_registration',
                'register_proxy_routes',
                'initialize_workspace_environment',
                'install_workspace_runtime_container',
                'run_workspace_setup_steps',
                'render_inherited_runtime_units',
                'check_workspace_readiness',
            ],
            [
                'provision_workspace_source',
                'apply_workspace_registration',
            ],
            expectedTerminalEvent: 'error',
        );

        expect($terminal['data']['code'] ?? null)
            ->toBe('workspace.registration_failed')
            ->and($terminal['data']['message'] ?? null)
            ->toBe('Workspace source was created, but registration failed.')
            ->and($terminal['data']['meta']['step'] ?? null)
            ->toBe('apply_workspace_registration')
            ->and($terminal['data']['meta']['partial_state'] ?? null)
            ->toBe('source_retained')
            ->and($terminal['data']['meta']['next_command'] ?? null)
            ->toBe($expectedNextCommand);

        expect($response->streamedContent())->not->toContain('sensitive registration detail');
    }

    Exceptions::assertReported(
        fn (RuntimeException $exception): bool => $exception->getMessage() === 'sensitive registration detail',
    );

    expect(Workspace::query()->where('name', $name)->exists())->toBeFalse();
})->with(['json', 'sse']);

it('returns stable reasons for generic create and setup failures through JSON and SSE endpoints', function (
    string $operation,
    string $adapter,
): void {
    $name = "generic-{$operation}-{$adapter}";
    $uri = $operation === 'create' ? '/api/workspaces' : '/api/workspaces/setup';
    $payload = [
        'name' => $name,
        'instance' => 'demo.development',
    ];
    $expectedMessage = $operation === 'create'
        ? "Workspace application on node 'app-1' stopped before Orbit could classify remaining drift."
        : "Workspace artifact application on node 'app-1' stopped before Orbit could classify remaining drift.";

    if ($operation === 'setup') {
        workspace_plan_parity_workspace($name);
    }

    ProxyRoute::creating(function (): never {
        throw new RuntimeException('sensitive generic failure detail');
    });

    $response = workspace_plan_parity_endpoint_request($adapter, $uri, $payload);

    if ($adapter === 'json') {
        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'workspace.enactment_failed')
            ->assertJsonPath('error.message', $expectedMessage)
            ->assertJsonPath('error.meta.reason', 'unexpected_failure');
    }

    if ($adapter === 'sse') {
        $response->assertSuccessful();
        $events = workspace_plan_parity_sse_events($response);
        $terminal = $events[array_key_last($events)] ?? null;

        expect($terminal['event'] ?? null)
            ->toBe('error')
            ->and($terminal['data']['data']['code'] ?? null)
            ->toBe('workspace.enactment_failed')
            ->and($terminal['data']['data']['message'] ?? null)
            ->toBe($expectedMessage)
            ->and($terminal['data']['data']['meta']['reason'] ?? null)
            ->toBe('unexpected_failure')
            ->and($response->streamedContent())
            ->not->toContain('sensitive generic failure detail');
    }
})->with([
    ['create', 'json'],
    ['create', 'sse'],
    ['setup', 'json'],
    ['setup', 'sse'],
]);

it('normalizes setup plan-construction exceptions through JSON and SSE without exposing details', function (
    string $adapter,
): void {
    $name = "plan-failure-{$adapter}";
    $workspace = workspace_plan_parity_workspace($name);
    $sentinel = "private-plan-construction-{$adapter}";
    $failPlanConstruction = true;
    Exceptions::fake();

    DB::connection()->beforeExecuting(
        function (string $query) use (&$failPlanConstruction, $sentinel): void {
            if (! $failPlanConstruction || ! str_contains($query, 'workspace_steps')) {
                return;
            }

            $failPlanConstruction = false;

            throw new RuntimeException($sentinel);
        },
    );

    $response = workspace_plan_parity_endpoint_request($adapter, uri: '/api/workspaces/setup', payload: [
        'name' => $name,
        'instance' => 'demo.development',
    ]);
    $error = workspace_plan_parity_endpoint_error($response, $adapter);

    $expectedError = [
        'code' => 'workspace.enactment_failed',
        'message' => "Workspace artifact application on node 'app-1' stopped before Orbit could classify remaining drift.",
        'meta' => [
            'phase' => 'planning',
            'node' => 'app-1',
            'reason' => 'plan_construction_failed',
        ],
    ];

    expect($error)
        ->toMatchArray($expectedError)
        ->and(workspace_plan_parity_response_content($response, $adapter))
        ->not
        ->toContain($sentinel)
        ->and($workspace->refresh()->lifecycle_status)
        ->toBe(WorkspaceLifecycleStatus::SetupPending);
    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === $sentinel);
})->with(['json', 'sse']);

it('normalizes actual setup plan factory exceptions through JSON and SSE without exposing details', function (
    string $adapter,
): void {
    $name = "factory-failure-{$adapter}";
    $workspace = workspace_plan_parity_workspace($name);
    $sentinel = 'active';
    Exceptions::fake();
    DB::table('workspaces')->where('id', $workspace->id)->update(['lifecycle_status' => $sentinel]);

    $response = workspace_plan_parity_endpoint_request($adapter, uri: '/api/workspaces/setup', payload: [
        'name' => $name,
        'instance' => 'demo.development',
    ]);
    $error = workspace_plan_parity_endpoint_error($response, $adapter);
    $expectedError = [
        'code' => 'workspace.enactment_failed',
        'message' => "Workspace artifact application on node 'app-1' stopped before Orbit could classify remaining drift.",
        'meta' => [
            'phase' => 'planning',
            'node' => 'app-1',
            'reason' => 'plan_construction_failed',
        ],
    ];

    if ($adapter === 'sse') {
        $expectedError['footer'] = "Failed to set up workspace '{$name}'.";
    }

    expect($error)
        ->toBe($expectedError)
        ->and(workspace_plan_parity_response_content($response, $adapter))
        ->not
        ->toContain($sentinel)
        ->and(DB::table('workspaces')->where('id', $workspace->id)->value('lifecycle_status'))
        ->toBe('setup-pending');
})->with(['json', 'sse']);

it('normalizes reporter initialization failures through literal JSON and SSE endpoints', function (
    string $operation,
    string $adapter,
): void {
    $name = "reporter-{$operation}-{$adapter}";
    $sentinel = "private-{$operation}-reporter-{$adapter}";
    Exceptions::fake();
    $reporter = new WorkspacePlanParityFailingTreeReporter($sentinel);

    if ($operation === 'setup') {
        workspace_plan_parity_workspace($name);
    }

    if ($adapter === 'json') {
        app()->instance(ProgressReporter::class, $reporter);
    }

    if ($adapter === 'sse') {
        app()->instance(
            ProgressEventStreamResponseFactory::class,
            new ProgressEventStreamResponseFactory(
                reporterFactory: static fn (): ProgressReporter => $reporter,
            ),
        );
    }

    $response = workspace_plan_parity_endpoint_request(
        $adapter,
        uri: $operation === 'create' ? '/api/workspaces' : '/api/workspaces/setup',
        payload: [
            'name' => $name,
            'instance' => 'demo.development',
        ],
    );
    $error = workspace_plan_parity_endpoint_error($response, $adapter);
    $expectedError = $operation === 'create'
        ? [
            'code' => 'workspace.enactment_failed',
            'message' => "Workspace application on node 'app-1' stopped before Orbit could classify remaining drift.",
            'meta' => [
                'step' => 'reporting',
                'node' => 'app-1',
                'reason' => 'reporter_initialization_failed',
            ],
        ]
        : [
            'code' => 'workspace.enactment_failed',
            'message' => "Workspace artifact application on node 'app-1' stopped before Orbit could classify remaining drift.",
            'meta' => [
                'phase' => 'reporting',
                'node' => 'app-1',
                'reason' => 'reporter_initialization_failed',
            ],
        ];

    if ($adapter === 'sse') {
        $expectedError['footer'] = $operation === 'create'
            ? "Failed to create workspace '{$name}'."
            : "Failed to set up workspace '{$name}'.";
    }

    expect($error)
        ->toBe($expectedError)
        ->and(workspace_plan_parity_response_content($response, $adapter))
        ->not
        ->toContain($sentinel)
        ->and(Workspace::query()->where('name', $name)->exists())
        ->toBe($operation === 'setup');
})->with([
    ['create', 'json'],
    ['create', 'sse'],
    ['setup', 'json'],
    ['setup', 'sse'],
]);

it('preserves adopted state across early setup failure, retry, list, and show through JSON and SSE', function (
    string $failurePoint,
    string $adapter,
): void {
    $name = "adoption-{$failurePoint}-{$adapter}";
    $path = "/srv/external/{$name}";
    $sentinel = "private-adoption-{$failurePoint}-{$adapter}";
    Exceptions::fake();

    if ($failurePoint === 'planning') {
        $failPlanning = true;

        DB::connection()->beforeExecuting(
            function (string $query) use (&$failPlanning, $sentinel): void {
                if (! $failPlanning || ! str_contains($query, 'workspace_steps')) {
                    return;
                }

                $failPlanning = false;

                throw new RuntimeException($sentinel);
            },
        );
    }

    if ($failurePoint === 'reporting') {
        $reporter = new WorkspacePlanParityFailingTreeReporter($sentinel);

        if ($adapter === 'json') {
            app()->instance(ProgressReporter::class, $reporter);
        }

        if ($adapter === 'sse') {
            app()->instance(
                ProgressEventStreamResponseFactory::class,
                new ProgressEventStreamResponseFactory(
                    reporterFactory: static fn (): ProgressReporter => $reporter,
                ),
            );
        }
    }

    $failureResponse = workspace_plan_parity_endpoint_request(
        $adapter,
        uri: '/api/workspaces/setup',
        payload: [
            'name' => $name,
            'instance' => 'demo.development',
            'path' => $path,
        ],
    );
    $workspace = Workspace::query()->where('name', $name)->firstOrFail();

    $failure = workspace_plan_parity_endpoint_error($failureResponse, $adapter);
    $expectedReason = 'reporter_initialization_failed';

    if ($failurePoint === 'planning') {
        $expectedReason = 'plan_construction_failed';
    }

    expect($failure['code'] ?? null)
        ->toBe('workspace.enactment_failed')
        ->and($failure['meta']['phase'] ?? null)
        ->toBe($failurePoint)
        ->and($failure['meta']['reason'] ?? null)
        ->toBe($expectedReason)
        ->and($workspace->refresh()->adopted)
        ->toBeTrue();

    app()->instance(ProgressReporter::class, new NullProgressReporter);
    app()->instance(ProgressEventStreamResponseFactory::class, new ProgressEventStreamResponseFactory);

    $retryResponse = workspace_plan_parity_endpoint_request(
        $adapter,
        uri: '/api/workspaces/setup',
        payload: [
            'name' => $name,
            'instance' => 'demo.development',
        ],
    );

    if ($adapter === 'json') {
        $retryResponse
            ->assertSuccessful()
            ->assertJsonPath('success.data.result.action', 'converged')
            ->assertJsonPath('success.data.workspace.adopted', true);
    }

    if ($adapter === 'sse') {
        $events = workspace_plan_parity_sse_events($retryResponse);
        $terminal = $events[array_key_last($events)] ?? [];

        expect($terminal['data']['data']['success']['data']['result']['action'] ?? null)
            ->toBe('converged')
            ->and($terminal['data']['data']['success']['data']['workspace']['adopted'] ?? null)
            ->toBeTrue();
    }

    $listResponse = $this->call(
        'GET',
        '/api/workspaces?instance=demo.development',
        [],
        [],
        [],
        ['REMOTE_ADDR' => WORKSPACE_PLAN_PARITY_CALLER_WG_IP],
    );
    $showResponse = $this->call(
        'GET',
        "/api/workspaces/{$name}?instance=demo.development",
        [],
        [],
        [],
        ['REMOTE_ADDR' => WORKSPACE_PLAN_PARITY_CALLER_WG_IP],
    );

    $listResponse
        ->assertSuccessful()
        ->assertJsonPath('success.data.workspaces.0.adopted', true);
    $showResponse
        ->assertSuccessful()
        ->assertJsonPath('success.data.workspace.adopted', true);
})->with([
    ['planning', 'json'],
    ['planning', 'sse'],
    ['reporting', 'json'],
    ['reporting', 'sse'],
]);

it('normalizes reporter tree initialization exceptions without exposing details', function (): void {
    $workspace = workspace_plan_parity_workspace();
    $app = App::query()->where('name', 'demo')->firstOrFail();
    $node = Node::query()->where('name', 'app-1')->firstOrFail();
    $sentinel = 'private-reporter-initialization';
    Exceptions::fake();

    $result = app(SetupWorkspace::class)
        ->plan($app, $workspace, $node)
        ->run(new WorkspacePlanParityFailingTreeReporter($sentinel));

    expect($result->failure())
        ->toBe([
            'code' => 'workspace.enactment_failed',
            'message' => "Workspace artifact application on node 'app-1' stopped before Orbit could classify remaining drift.",
            'meta' => [
                'phase' => 'reporting',
                'node' => 'app-1',
                'reason' => 'reporter_initialization_failed',
            ],
        ])
        ->and(json_encode($result->failure(), JSON_THROW_ON_ERROR))
        ->not
        ->toContain($sentinel)
        ->and($workspace->refresh()->lifecycle_status)
        ->toBe(WorkspaceLifecycleStatus::SetupPending);
    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === $sentinel);
});

it('returns the same complete canonical setup success payload through JSON and SSE endpoints', function (): void {
    $payloads = [];

    foreach (['json', 'sse'] as $adapter) {
        $name = "setup-{$adapter}";
        $workspace = workspace_plan_parity_workspace($name);
        $response = workspace_plan_parity_endpoint_request($adapter, uri: '/api/workspaces/setup', payload: [
            'name' => $name,
            'instance' => 'demo.development',
        ]);

        if ($adapter === 'json') {
            $response->assertSuccessful();
            $payload = $response->json();
        }

        if ($adapter === 'sse') {
            expect($response->getStatusCode())->toBe(200);
            $payload = workspace_plan_parity_expect_sse_envelope(
                $response,
                [
                    'apply_workspace_registration',
                    'register_proxy_routes',
                    'initialize_workspace_environment',
                    'install_workspace_runtime_container',
                    'check_workspace_readiness',
                ],
                [
                    'apply_workspace_registration',
                    'register_proxy_routes',
                    'initialize_workspace_environment',
                    'install_workspace_runtime_container',
                    'check_workspace_readiness',
                ],
                expectedTerminalEvent: 'complete',
            );
        }

        $payloads[$adapter] = workspace_plan_parity_normalize_payload($payload, $name);

        expect($workspace->refresh()->lifecycle_status)
            ->toBe(WorkspaceLifecycleStatus::Expected)
            ->and(ProxyRoute::query()->where('workspace_id', $workspace->id)->exists())
            ->toBeTrue();
    }

    $success = [
        'data' => [
            'result' => ['action' => 'set_up'],
            'workspace' => [
                'name' => 'setup-adapter',
                'app' => 'demo',
                'instance' => 'development',
                'node' => 'app-1',
                'path' => '/home/orbit/apps/demo/.worktrees/setup-adapter',
                'url' => 'https://setup-adapter.demo.test',
                'php_version' => '8.5',
                'php_inherited' => false,
                'adopted' => false,
                'lifecycle_status' => 'expected',
            ],
        ],
        'meta' => [
            'node' => 'app-1',
            'http_probe' => [
                'url' => 'https://setup-adapter.demo.test',
                'result' => 'unhealthy',
                'status_code' => null,
                'duration_ms' => 0,
            ],
            'warnings' => [
                [
                    'code' => 'proxy.enactment_failed',
                    'family' => 'proxy',
                    'message' => "Proxy route 'setup-adapter.demo.test' was recorded, but TLS material could not be installed. Run doctor to converge proxy artifacts.",
                    'next_command' => 'doctor --family=proxy --restore',
                ],
                [
                    'code' => 'process.runtime_unit_missing',
                    'family' => 'process',
                    'message' => "FrankenPHP runtime container for workspace 'setup-adapter' could not be installed on 'app-1'. Run doctor to converge process runtime units.",
                    'next_command' => 'doctor --family=process --restore',
                ],
                [
                    'code' => 'workspace.http_probe_unhealthy',
                    'family' => null,
                    'message' => "Setup completed, but the HTTP probe for 'https://setup-adapter.demo.test' did not return a serving response within 10s.",
                    'next_command' => "orbit workspace:setup 'setup-adapter' --instance='demo.development'",
                ],
            ],
        ],
    ];

    expect($payloads['json'])
        ->toBe(['success' => $success])
        ->and($payloads['sse'])
        ->toBe([
            'exit_code' => 0,
            'data' => [
                'footer' => 'Workspace ready and available at: https://setup-adapter.demo.test',
                'success' => $success,
            ],
        ]);
});

it('keeps setup-step output private and returns stable reasons through JSON and SSE endpoints', function (
    string $operation,
    string $adapter,
): void {
    $sentinel = "private-setup-output-{$operation}-{$adapter}";
    $name = "private-{$operation}-{$adapter}";
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
    app()->instance(
        RunsInternalCommands::class,
        new WorkspacePlanParityInternalCommands(failSetupStep: true, setupFailureOutput: $sentinel),
    );

    if ($operation === 'setup') {
        workspace_plan_parity_workspace($name);
    }

    $response = workspace_plan_parity_endpoint_request(
        $adapter,
        $operation === 'create' ? '/api/workspaces' : '/api/workspaces/setup',
        [
            'name' => $name,
            'instance' => 'demo.development',
        ],
    );
    $error = workspace_plan_parity_endpoint_error($response, $adapter);

    expect($error['code'] ?? null)
        ->toBe($operation === 'create' ? 'workspace.enactment_failed' : 'workspace.setup_step_failed')
        ->and($error['message'] ?? null)
        ->toBe(
            $operation === 'create'
                ? "Workspace enactment on node 'app-1' stopped before Orbit could classify remaining drift."
                : 'Workspace setup step failed.',
        )
        ->and($error['meta']['reason'] ?? null)
        ->toBe('setup_step_failed')
        ->and(workspace_plan_parity_response_content($response, $adapter))
        ->not
        ->toContain($sentinel)
        ->and(WorkspaceRunStep::query()->where('output', 'like', "%{$sentinel}%")->exists())
        ->toBeTrue();
})->with([
    ['create', 'json'],
    ['create', 'sse'],
    ['setup', 'json'],
    ['setup', 'sse'],
]);

it('reports runtime-container exceptions internally without exposing them through JSON or SSE', function (
    string $operation,
    string $adapter,
): void {
    $sentinel = "private-runtime-exception-{$operation}-{$adapter}";
    $name = "runtime-{$operation}-{$adapter}";
    Exceptions::fake();
    app()->instance(
        RunsInternalCommands::class,
        new WorkspacePlanParityInternalCommands(runtimeFailureOutput: $sentinel),
    );

    if ($operation === 'setup') {
        workspace_plan_parity_workspace($name);
    }

    $response = workspace_plan_parity_endpoint_request(
        $adapter,
        $operation === 'create' ? '/api/workspaces' : '/api/workspaces/setup',
        [
            'name' => $name,
            'instance' => 'demo.development',
        ],
    );
    $warnings = workspace_plan_parity_endpoint_warnings($response, $adapter);
    $warning = collect($warnings)->firstWhere('code', 'process.runtime_unit_missing');

    expect($response->isSuccessful())
        ->toBeTrue()
        ->and($warning)
        ->toMatchArray([
            'code' => 'process.runtime_unit_missing',
            'family' => 'process',
            'message' => "FrankenPHP runtime container for workspace '{$name}' could not be installed on 'app-1'. Run doctor to converge process runtime units.",
            'next_command' => 'doctor --family=process --restore',
        ])
        ->and(workspace_plan_parity_response_content($response, $adapter))
        ->not->toContain($sentinel);

    Exceptions::assertReportedCount(1);
})->with([
    ['create', 'json'],
    ['create', 'sse'],
    ['setup', 'json'],
    ['setup', 'sse'],
]);

it('returns process_start_failed for classified process failures through JSON and SSE endpoints', function (
    string $operation,
    string $adapter,
): void {
    $name = "process-{$operation}-{$adapter}";
    $app = App::query()->where('name', 'demo')->firstOrFail();
    $instance = Instance::query()->where('app_id', $app->id)->firstOrFail();

    Process::factory()
        ->forOwner($app)
        ->create([
            'instance_id' => $instance->id,
            'name' => 'queue',
            'command' => 'php artisan queue:work',
            'runtime' => ProcessRuntime::Systemd,
        ]);
    app()->instance(RunsInternalCommands::class, new WorkspacePlanParityInternalCommands(failProcessStart: true));

    if ($operation === 'setup') {
        workspace_plan_parity_workspace($name);
    }

    $response = workspace_plan_parity_endpoint_request(
        $adapter,
        $operation === 'create' ? '/api/workspaces' : '/api/workspaces/setup',
        [
            'name' => $name,
            'instance' => 'demo.development',
        ],
    );
    $error = workspace_plan_parity_endpoint_error($response, $adapter);

    expect($error['code'] ?? null)
        ->toBe('workspace.enactment_failed')
        ->and($error['meta']['reason'] ?? null)
        ->toBe('process_start_failed')
        ->and($error['meta']['node'] ?? null)
        ->toBe('app-1');
})->with([
    ['create', 'json'],
    ['create', 'sse'],
    ['setup', 'json'],
    ['setup', 'sse'],
]);

it('returns matching setup failures and retained phase state through JSON and SSE endpoints', function (string $adapter): void {
    $name = "failure-{$adapter}";
    $workspace = workspace_plan_parity_workspace($name);
    $app = App::query()->where('name', 'demo')->firstOrFail();

    WorkspaceStep::create([
        'app_id' => $app->id,
        'instance_id' => $workspace->instance_id,
        'phase' => WorkspaceLifecyclePhase::Setup,
        'sort_order' => 1,
        'command' => 'exit 1',
        'timeout_seconds' => 60,
    ]);
    app()->instance(RunsInternalCommands::class, new WorkspacePlanParityInternalCommands(failSetupStep: true));

    $response = workspace_plan_parity_endpoint_request($adapter, uri: '/api/workspaces/setup', payload: [
        'name' => $name,
        'instance' => 'demo.development',
    ]);

    if ($adapter === 'json') {
        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'workspace.setup_step_failed')
            ->assertJsonPath('error.meta.step', 'exit 1')
            ->assertJsonPath('error.meta.phase', 'setup_steps');
    }

    if ($adapter === 'sse') {
        $response->assertSuccessful();
        $terminal = workspace_plan_parity_expect_sse_envelope(
            $response,
            [
                'apply_workspace_registration',
                'register_proxy_routes',
                'initialize_workspace_environment',
                'install_workspace_runtime_container',
                'run_workspace_setup_steps',
                'check_workspace_readiness',
            ],
            [
                'apply_workspace_registration',
                'register_proxy_routes',
                'initialize_workspace_environment',
                'install_workspace_runtime_container',
                'run_workspace_setup_steps',
            ],
            expectedTerminalEvent: 'error',
        );

        expect($terminal['data']['code'] ?? null)
            ->toBe('workspace.setup_step_failed')
            ->and($terminal['data']['meta']['step'] ?? null)
            ->toBe('exit 1')
            ->and($terminal['data']['meta']['phase'] ?? null)
            ->toBe('setup_steps');
    }

    expect($workspace->refresh()->lifecycle_status)
        ->toBe(WorkspaceLifecycleStatus::SetupPending)
        ->and(ProxyRoute::query()->where('workspace_id', $workspace->id)->exists())
        ->toBeTrue();
})->with(['json', 'sse']);

it('reports retained source and an adoption retry when workspace registration fails', function (): void {
    $app = App::query()->where('name', 'demo')->firstOrFail();
    $instance = Instance::query()->where('app_id', $app->id)->firstOrFail();
    $node = Node::query()->where('name', 'app-1')->firstOrFail();
    $appPath = "/home/orbit/apps/demo workspace;\$(touch nope)'quoted";
    $workspacePath = "{$appPath}/.worktrees/feature-a";

    $instance->update([
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            path: $appPath,
            document_root: 'public',
            domain: 'demo.test',
        ),
    ]);

    Workspace::creating(function (): never {
        throw new RuntimeException('registration failed');
    });

    $result = workspace_plan_parity_create_plan('json', $app, $instance, name: 'feature-a')
        ->run(new NullProgressReporter);

    expect($result->isSuccessful())
        ->toBeFalse()
        ->and($result->failure())
        ->toMatchArray([
            'code' => 'workspace.registration_failed',
            'meta' => [
                'step' => 'apply_workspace_registration',
                'node' => 'app-1',
                'path' => $workspacePath,
                'partial_state' => 'source_retained',
                'next_command' =>
                    'orbit workspace:setup '
                        .escapeshellarg('feature-a')
                        .' --instance='
                        .escapeshellarg('demo.development')
                        .' --path='
                        .escapeshellarg($workspacePath),
            ],
        ])
        ->and($result->completedSteps())
        ->toBe(['provision_workspace_source'])
        ->and(Workspace::query()->where('name', 'feature-a')->exists())
        ->toBeFalse();
});

it('retains a registered workspace when preparation fails and converges it through the retry command', function (): void {
    $app = App::query()->where('name', 'demo')->firstOrFail();
    $instance = Instance::query()->where('app_id', $app->id)->firstOrFail();
    $failPreparation = true;

    Workspace::saving(function (Workspace $workspace) use (&$failPreparation): void {
        if (! $workspace->exists || ! $failPreparation) {
            return;
        }

        $failPreparation = false;

        throw new RuntimeException('sensitive preparation detail');
    });

    $result = workspace_plan_parity_create_plan('json', $app, $instance, name: 'feature-a')
        ->run(new NullProgressReporter);
    $workspacePath = '/home/orbit/apps/demo/.worktrees/feature-a';
    $expectedNextCommand =
        'orbit workspace:setup '
        .escapeshellarg('feature-a')
        .' --instance='
        .escapeshellarg('demo.development')
        .' --path='
        .escapeshellarg($workspacePath);

    expect($result->isSuccessful())
        ->toBeFalse()
        ->and($result->failure())
        ->toMatchArray([
            'code' => 'workspace.registration_failed',
            'message' => 'Workspace source was created, but registration failed.',
            'meta' => [
                'step' => 'apply_workspace_registration',
                'node' => 'app-1',
                'path' => $workspacePath,
                'partial_state' => 'workspace_registered',
                'next_command' => $expectedNextCommand,
            ],
        ])
        ->and($result->completedSteps())
        ->toBe(['provision_workspace_source']);

    $retainedWorkspace = Workspace::query()->where('name', 'feature-a')->firstOrFail();

    $retry = workspace_plan_parity_endpoint_request('json', uri: '/api/workspaces/setup', payload: [
        'name' => 'feature-a',
        'instance' => 'demo.development',
        'path' => $workspacePath,
    ]);

    $retry
        ->assertSuccessful()
        ->assertJsonPath('success.data.workspace.name', 'feature-a')
        ->assertJsonPath('success.data.result.action', 'set_up');

    expect($retainedWorkspace->refresh()->lifecycle_status)
        ->toBe(WorkspaceLifecycleStatus::Expected)
        ->and(Workspace::query()->where('name', 'feature-a')->count())
        ->toBe(1);
});

it('returns command-owned readiness warnings from create and setup plans', function (
    string $operation,
    string $adapter,
): void {
    Http::fake(['*' => Http::response('', 503)]);
    $app = App::query()->where('name', 'demo')->firstOrFail();
    $instance = Instance::query()->where('app_id', $app->id)->firstOrFail();
    $result = $operation === 'create'
        ? workspace_plan_parity_create_plan($adapter, $app, $instance, name: 'feature-a')
            ->run(workspace_plan_parity_reporter($adapter))
        : workspace_plan_parity_setup_plan(
            $adapter,
            $app,
            workspace_plan_parity_workspace(),
            Node::query()->where('name', 'app-1')->firstOrFail(),
        )->run(workspace_plan_parity_reporter($adapter));

    $warnings = $result->data()['meta']['warnings'] ?? $result->data()['warnings'] ?? [];
    $warning =
        array_values(array_filter(
            $warnings,
            static fn (array $warning): bool => $warning['code'] === 'workspace.http_probe_unhealthy',
        ))[0] ?? null;

    expect($warning)
        ->toMatchArray([
            'code' => 'workspace.http_probe_unhealthy',
            'next_command' => "orbit workspace:setup 'feature-a' --instance='demo.development'",
        ])
        ->and($warning)
        ->toHaveKey('family')
        ->and($warning['family'])
        ->toBeNull();
})->with([
    ['create', 'json'],
    ['create', 'sse'],
    ['setup', 'json'],
    ['setup', 'sse'],
]);

it('keeps page and vite readiness exceptions private in JSON and SSE setup results', function (
    string $probeFailure,
    string $adapter,
): void {
    $name = "probe-{$probeFailure}-{$adapter}";
    $workspace = workspace_plan_parity_workspace($name);
    $url = $workspace->url();
    $sentinel = "private-{$probeFailure}-probe-{$adapter}";
    Exceptions::fake();
    app()->instance(
        WorkspaceReadinessProbe::class,
        new WorkspaceReadinessProbe(maxAttempts: 1, retryDelayMilliseconds: 0),
    );
    Http::swap(new HttpFactory);
    Http::fake(function (Request $request) use ($probeFailure, $url, $sentinel) {
        if ($probeFailure === 'vite' && $request->url() === $url) {
            return Http::response('<script type="module" src="/@vite/client"></script>');
        }

        throw new RuntimeException($sentinel);
    });

    $response = workspace_plan_parity_endpoint_request($adapter, uri: '/api/workspaces/setup', payload: [
        'name' => $name,
        'instance' => 'demo.development',
    ]);

    $success = [];

    if ($adapter === 'json') {
        $response->assertSuccessful();
        $success = $response->json('success');
    }

    if ($adapter === 'sse') {
        expect($response->getStatusCode())->toBe(200);
        $terminal = workspace_plan_parity_expect_sse_envelope(
            $response,
            [
                'apply_workspace_registration',
                'register_proxy_routes',
                'initialize_workspace_environment',
                'install_workspace_runtime_container',
                'check_workspace_readiness',
            ],
            [
                'apply_workspace_registration',
                'register_proxy_routes',
                'initialize_workspace_environment',
                'install_workspace_runtime_container',
                'check_workspace_readiness',
            ],
            expectedTerminalEvent: 'complete',
        );
        $success = $terminal['data']['success'] ?? [];
    }

    $httpProbe = $success['meta']['http_probe'] ?? null;
    $warning = collect($success['meta']['warnings'] ?? [])
        ->firstWhere('code', 'workspace.http_probe_unhealthy');

    expect($httpProbe)
        ->toHaveKeys(['url', 'result', 'status_code', 'duration_ms'])
        ->and($httpProbe['url'] ?? null)
        ->toBe($url)
        ->and($httpProbe['result'] ?? null)
        ->toBe('unhealthy')
        ->and(array_key_exists('status_code', $httpProbe))
        ->toBeTrue()
        ->and($httpProbe['status_code'])
        ->toBeNull()
        ->and($httpProbe['duration_ms'] ?? null)
        ->toBeInt()
        ->and($warning)
        ->toBe([
            'code' => 'workspace.http_probe_unhealthy',
            'family' => null,
            'message' => "Setup completed, but the HTTP probe for '{$url}' did not return a serving response within 10s.",
            'next_command' => "orbit workspace:setup '{$name}' --instance='demo.development'",
        ])
        ->and(workspace_plan_parity_response_content($response, $adapter))
        ->not->toContain($sentinel);
    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === $sentinel);
})->with([
    ['page', 'json'],
    ['page', 'sse'],
    ['vite', 'json'],
    ['vite', 'sse'],
]);

function workspace_plan_parity_workspace(string $name = 'feature-a'): Workspace
{
    $app = App::query()->where('name', 'demo')->firstOrFail();
    $instance = Instance::query()->where('app_id', $app->id)->firstOrFail();

    return Workspace::create([
        'app_id' => $app->id,
        'instance_id' => $instance->id,
        'name' => $name,
        'path' => "/home/orbit/apps/demo/.worktrees/{$name}",
        'php_version' => '8.5',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);
}

/** @param array<string, mixed> $payload */
function workspace_plan_parity_endpoint_request(string $adapter, string $uri, array $payload): TestResponse
{
    return test()->call(
        'POST',
        $uri,
        $payload,
        [],
        [],
        [
            'HTTP_ACCEPT' => $adapter === 'sse' ? 'text/event-stream' : 'application/json',
            'REMOTE_ADDR' => WORKSPACE_PLAN_PARITY_CALLER_WG_IP,
        ],
    );
}

/**
 * @param list<string> $expectedPlannedSteps
 * @param list<string> $expectedTerminalSteps
 * @return array<string, mixed>
 */
function workspace_plan_parity_expect_sse_envelope(
    TestResponse $response,
    array $expectedPlannedSteps,
    array $expectedTerminalSteps,
    string $expectedTerminalEvent,
): array {
    $events = workspace_plan_parity_sse_events($response);
    $tree = $events[0] ?? null;
    $terminal = $events[array_key_last($events)] ?? null;
    $terminalSteps = array_values(array_filter(
        $events,
        static fn (array $event): bool => $event['event'] === 'step'
        && in_array($event['data']['status'] ?? null, ['done', 'skip', 'fail'], strict: true),
    ));

    expect($tree['event'] ?? null)
        ->toBe('tree')
        ->and(array_column($tree['data']['steps'] ?? [], 'key'))
        ->toBe($expectedPlannedSteps)
        ->and(array_map(
            static fn (array $event): mixed => $event['data']['key'] ?? null,
            $terminalSteps,
        ))
        ->toBe($expectedTerminalSteps)
        ->and($terminal['event'] ?? null)
        ->toBe($expectedTerminalEvent);

    return is_array($terminal['data'] ?? null) ? $terminal['data'] : [];
}

/** @return list<array{event: string, data: array<string, mixed>}> */
function workspace_plan_parity_sse_events(TestResponse $response): array
{
    preg_match_all(
        '/event: ([^\n]+)\ndata: ([^\n]+)\n\n/',
        $response->streamedContent(),
        $matches,
        PREG_SET_ORDER,
    );

    return array_map(
        static fn (array $match): array => [
            'event' => $match[1],
            'data' => json_decode($match[2], associative: true, flags: JSON_THROW_ON_ERROR),
        ],
        $matches,
    );
}

/** @return array<string, mixed> */
function workspace_plan_parity_normalize_payload(mixed $payload, string $workspaceName): array
{
    $json = json_encode($payload, JSON_THROW_ON_ERROR);
    $normalized = json_decode(
        str_replace(search: $workspaceName, replace: 'setup-adapter', subject: $json),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );

    return is_array($normalized) ? $normalized : [];
}

/** @return array<string, mixed> */
function workspace_plan_parity_endpoint_error(TestResponse $response, string $adapter): array
{
    if ($adapter === 'json') {
        $error = $response->json('error');

        return is_array($error) ? $error : [];
    }

    $events = workspace_plan_parity_sse_events($response);
    $terminal = $events[array_key_last($events)] ?? [];
    $error = $terminal['data']['data'] ?? null;

    return is_array($error) ? $error : [];
}

/** @return list<array<string, mixed>> */
function workspace_plan_parity_endpoint_warnings(
    TestResponse $response,
    string $adapter,
): array {
    if ($adapter === 'json') {
        $warnings = $response->json('success.meta.warnings');

        return is_array($warnings) ? array_values($warnings) : [];
    }

    $events = workspace_plan_parity_sse_events($response);
    $terminal = $events[array_key_last($events)] ?? [];
    $warnings = $terminal['data']['data']['success']['meta']['warnings'] ?? [];

    return is_array($warnings) ? array_values($warnings) : [];
}

function workspace_plan_parity_response_content(TestResponse $response, string $adapter): string
{
    return $adapter === 'sse' ? $response->streamedContent() : $response->getContent();
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
final readonly class WorkspacePlanParityFailingTreeReporter implements ProgressReporter
{
    public function __construct(
        private string $message,
    ) {}

    public function tree(string $title, array $steps): void
    {
        throw new RuntimeException($this->message);
    }

    public function stepStart(string $key): void {}

    public function stepProgress(string $key, string $status, ?string $message = null): void {}

    public function stepDone(string $key, ?string $message = null): void {}

    public function stepFail(string $key, string $message): void {}

    public function stepSkip(string $key, ?string $message = null): void {}
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
final class WorkspacePlanParityCertificateInstaller implements SiteCertificateInstaller
{
    public function ensureFor(Node $node, string $host): array
    {
        return $this->expectedPathsFor($node, $host);
    }

    public function expectedPathsFor(Node $node, string $host): array
    {
        return [
            'cert' => "/home/orbit/.config/orbit/certs/{$host}.crt",
            'key' => "/home/orbit/.config/orbit/certs/{$host}.key",
        ];
    }
}

/** @mago-expect lint:single-class-per-file */
final readonly class WorkspacePlanParityInternalCommands implements RunsInternalCommands
{
    public function __construct(
        private bool $failSetupStep = false,
        private bool $failSource = false,
        private ?string $setupFailureOutput = null,
        private bool $failProcessStart = false,
        private ?string $runtimeFailureOutput = null,
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

        if ($commandName === 'internal:tool-run-script') {
            return new RemoteShellResult(
                exitCode: 0,
                stdout: json_encode([
                    'success' => [
                        'data' => [
                            'exit_code' => $this->runtimeFailureOutput === null ? 0 : 1,
                            'stdout' => '',
                            'stderr' => $this->runtimeFailureOutput ?? '',
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

        if ($this->failSetupStep && $commandName === 'internal:workspace-setup-step') {
            return new RemoteShellResult(
                exitCode: 1,
                stdout: '',
                stderr: $this->setupFailureOutput ?? 'step failed',
                durationMs: 1,
            );
        }

        if (
            $this->failProcessStart
            && $commandName === 'internal:process-systemd-service'
            && ($arguments[0] ?? null) === 'start'
        ) {
            return new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'process start failed', durationMs: 1);
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
