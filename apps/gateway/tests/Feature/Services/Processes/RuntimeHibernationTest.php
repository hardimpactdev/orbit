<?php

declare(strict_types=1);

use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\OperationRun;
use App\Models\Process;
use App\Models\ProcessEvent;
use App\Models\Project;
use App\Models\Workspace;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Processes\ProcessLifecycle;
use App\Services\Processes\ProcessOwnerContext;
use App\Services\Processes\RuntimeActivationRunner;
use App\Services\Processes\RuntimeDependencyColdStorage;
use App\Services\Processes\RuntimeHibernationScope;
use App\Services\Processes\RuntimeHibernationScopes;
use App\Services\Processes\RuntimeIdleHibernation;
use App\Services\RemoteShell\RunsInternalCommands;
use App\Services\Schedules\OrbitScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process as ProcessFacade;
use Illuminate\Support\Sleep;
use Orbit\Core\Enums\OperationStatus;

uses(RefreshDatabase::class);

it('marks a development app instance asleep before a bulk process stop', function (): void {
    [$node, $app, $instance] = create_runtime_hibernation_instance();
    Process::factory()->forOwner($app, $node)->create(['name' => 'queue']);
    $executor = new RuntimeHibernationRecordingExecutor;
    app()->instance(RunsInternalCommands::class, $executor);

    app(ProcessLifecycle::class)->stop(
        runtime_hibernation_context($node, $app, $instance),
        null,
    );

    expect($executor->actions())
        ->toBe([
            'internal:caddy-config:runtime-asleep',
            'internal:process-systemd-service:stop',
        ])
        ->and($executor->runtimeMarkerKeys())
        ->toBe(["app-instance-{$instance->id}"]);
});

it('keeps named process stops independent from the development hibernation marker', function (): void {
    [$node, $app, $instance] = create_runtime_hibernation_instance();
    Process::factory()->forOwner($app, $node)->create(['name' => 'queue']);
    $executor = new RuntimeHibernationRecordingExecutor;
    app()->instance(RunsInternalCommands::class, $executor);

    app(ProcessLifecycle::class)->stop(
        runtime_hibernation_context($node, $app, $instance),
        'queue',
    );

    expect($executor->actions())
        ->toBe(['internal:process-systemd-service:stop'])
        ->and($executor->runtimeMarkerKeys())
        ->toBeEmpty();
});

it('does not stop a development process group when its asleep marker cannot be written', function (): void {
    [$node, $app, $instance] = create_runtime_hibernation_instance();
    Process::factory()->forOwner($app, $node)->create(['name' => 'queue']);
    $executor = new RuntimeHibernationRecordingExecutor(
        failingAction: 'internal:caddy-config:runtime-asleep',
    );
    app()->instance(RunsInternalCommands::class, $executor);

    $result = app(ProcessLifecycle::class)->stop(
        runtime_hibernation_context($node, $app, $instance),
        null,
    );

    expect($result['failed'])
        ->toBeTrue()
        ->and($result['meta']['runtime_state'])
        ->toBe('runtime_asleep_marker_failed')
        ->and($executor->actions())
        ->toBe(['internal:caddy-config:runtime-asleep']);
});

it('keeps a development process group asleep when its bulk stop fails', function (): void {
    [$node, $app, $instance] = create_runtime_hibernation_instance();
    Process::factory()->forOwner($app, $node)->create(['name' => 'queue']);
    $executor = new RuntimeHibernationRecordingExecutor(
        failingAction: 'internal:process-systemd-service:stop',
    );
    app()->instance(RunsInternalCommands::class, $executor);

    $result = app(ProcessLifecycle::class)->stop(
        runtime_hibernation_context($node, $app, $instance),
        null,
    );

    expect($result['failed'])
        ->toBeTrue()
        ->and($result['meta']['partial_state'])
        ->toBe('none_stopped')
        ->and($executor->actions())
        ->toBe([
            'internal:caddy-config:runtime-asleep',
            'internal:process-systemd-service:stop',
        ]);
});

it('keeps a development process group asleep when its bulk restart fails', function (): void {
    [$node, $app, $instance] = create_runtime_hibernation_instance();
    Process::factory()->forOwner($app, $node)->create(['name' => 'queue']);
    $executor = new RuntimeHibernationRecordingExecutor(
        failingAction: 'internal:process-systemd-service:restart',
    );
    app()->instance(RunsInternalCommands::class, $executor);

    $result = app(ProcessLifecycle::class)->restart(
        runtime_hibernation_context($node, $app, $instance),
        null,
    );

    expect($result['failed'])
        ->toBeTrue()
        ->and($result['meta']['partial_state'])
        ->toBe('none_restarted')
        ->and($executor->actions())
        ->toBe([
            'internal:caddy-config:runtime-asleep',
            'internal:process-systemd-service:restart',
        ]);
});

it('keeps production process groups outside development hibernation markers', function (): void {
    $node = createTestAppHostNode([
        'name' => 'app-prod-1',
    ], role: 'app-prod');
    $app = Project::factory()->for($node, 'node')->create(['name' => 'docs']);
    $instance = AppInstance::factory()->for($app)->create([
        'name' => 'production',
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: $app->path,
            document_root: $app->document_root,
            domain: 'docs.example.com',
        ),
    ]);
    Process::factory()->forOwner($app, $node)->create(['name' => 'queue']);
    $executor = new RuntimeHibernationRecordingExecutor;
    app()->instance(RunsInternalCommands::class, $executor);

    app(ProcessLifecycle::class)->stop(
        runtime_hibernation_context($node, $app, $instance),
        null,
    );

    expect($executor->actions())
        ->toBe(['internal:process-systemd-service:stop'])
        ->and($executor->runtimeMarkerKeys())
        ->toBeEmpty();
});

it('marks a development app instance awake after a successful bulk process start', function (): void {
    [$node, $app, $instance] = create_runtime_hibernation_instance();
    Process::factory()->forOwner($app, $node)->create(['name' => 'queue']);
    $executor = new RuntimeHibernationRecordingExecutor;
    app()->instance(RunsInternalCommands::class, $executor);

    app(ProcessLifecycle::class)->start(
        runtime_hibernation_context($node, $app, $instance),
        null,
    );

    expect($executor->actions())
        ->toBe([
            'internal:caddy-config:runtime-asleep',
            'internal:process-systemd-service:start',
            'internal:caddy-config:runtime-awake',
        ])
        ->and($executor->runtimeMarkerKeys())
        ->toBe([
            "app-instance-{$instance->id}",
            "app-instance-{$instance->id}",
        ]);
});

it('brackets a development workspace bulk restart with its asleep and awake markers', function (): void {
    [$node, $app, $instance] = create_runtime_hibernation_instance();
    $workspace = Workspace::factory()->for($app, 'app')->create([
        'app_instance_id' => $instance->id,
        'name' => 'feature-a',
    ]);
    Process::factory()->forOwner($workspace, $node)->create(['name' => 'vite']);
    $executor = new RuntimeHibernationRecordingExecutor;
    app()->instance(RunsInternalCommands::class, $executor);

    app(ProcessLifecycle::class)->restart(
        new ProcessOwnerContext(
            node: $node,
            app: $app,
            workspace: $workspace,
            owner: $workspace,
            appInstance: $instance,
        ),
        null,
    );

    expect($executor->actions())
        ->toBe([
            'internal:caddy-config:runtime-asleep',
            'internal:process-systemd-service:restart',
            'internal:caddy-config:runtime-awake',
        ])
        ->and($executor->runtimeMarkerKeys())
        ->toBe([
            "workspace-{$workspace->id}",
            "workspace-{$workspace->id}",
        ]);
});

it('returns the minimal progress page immediately for a soft-asleep app instance without starting processes inline', function (): void {
    createTestGatewayNode([
        'name' => 'gateway-1',
        'wireguard_address' => '10.6.0.1',
    ]);
    ProcessFacade::fake();
    config()->set(
        'orbit.updates.gateway_image',
        'ghcr.io/hardimpactdev/orbit-gateway:current@sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc',
    );
    [$node, $app, $instance] = create_runtime_hibernation_instance();
    Process::factory()->forOwner($app, $node)->create(['name' => 'queue']);
    $executor = new RuntimeHibernationRecordingExecutor;
    app()->instance(RunsInternalCommands::class, $executor);

    $response = $this->call(
        'GET',
        "/api/runtime-activations/app-instance/{$instance->id}",
        server: [
            'REMOTE_ADDR' => $node->wireguard_address,
            'HTTP_X_ORBIT_RUNTIME_COLD' => '0',
            'HTTP_X_FORWARDED_URI' => '/dashboard?tab=jobs',
        ],
    );

    assert_runtime_activation_boot_screen($response, '/dashboard?tab=jobs');
    $response
        ->assertDontSee('Starting queue')
        ->assertDontSee('composer install')
        ->assertDontSee('role="progressbar"', false);

    $run = OperationRun::query()
        ->where('operation_id', "runtime-activation:app-instance-{$instance->id}")
        ->sole();

    expect($run->status)
        ->toBe(OperationStatus::Queued)
        ->and($run->result['runtime_activation']['cold'] ?? null)
        ->toBeFalse()
        ->and($run->result['runtime_activation']['dependencies'] ?? null)
        ->toBe([])
        ->and(array_column($run->result['runtime_activation']['processes'], 'name'))
        ->toBe(['queue'])
        ->and($executor->actions())
        ->toBe(['internal:caddy-config:runtime-states'])
        ->and($executor->actions())
        ->not->toContain('internal:process-systemd-service:start')
        ->not->toContain('internal:caddy-config:runtime-awake')
        ->not->toContain('internal:runtime-dependencies:inspect')
        ->not->toContain('internal:caddy-config:runtime-warm');

    app(RuntimeActivationRunner::class)->run($run->id);

    expect($run->refresh()->status)
        ->toBe(OperationStatus::Succeeded)
        ->and($executor->actions())
        ->toBe([
            'internal:caddy-config:runtime-states',
            'internal:caddy-config:runtime-states',
            'internal:process-systemd-service:start',
            'internal:caddy-config:runtime-awake',
        ])
        ->and($executor->actions())
        ->not->toContain('internal:runtime-dependencies:inspect')
        ->not->toContain('internal:caddy-config:runtime-warm');

    $this->call(
        'GET',
        "/api/runtime-activations/app-instance/{$instance->id}",
        server: [
            'REMOTE_ADDR' => $node->wireguard_address,
            'HTTP_X_ORBIT_RUNTIME_COLD' => '0',
        ],
    )->assertNoContent();
});

it('rejects runtime activation from a node other than the exact serving node', function (): void {
    createTestGatewayNode([
        'name' => 'gateway-1',
        'wireguard_address' => '10.6.0.1',
    ]);
    [, , $instance] = create_runtime_hibernation_instance();
    $otherNode = createTestAppHostNode([
        'name' => 'other-app-dev',
        'wireguard_address' => '10.6.0.22',
    ]);
    $executor = new RuntimeHibernationRecordingExecutor;
    app()->instance(RunsInternalCommands::class, $executor);

    $this
        ->call(
            'GET',
            "/api/runtime-activations/app-instance/{$instance->id}",
            server: [
                'REMOTE_ADDR' => $otherNode->wireguard_address,
                'HTTP_X_ORBIT_RUNTIME_COLD' => '0',
            ],
        )
        ->assertForbidden()
        ->assertJsonPath('error.code', 'authorization_failed');

    expect($executor->actions())->toBeEmpty();
});

it('returns no content for a soft scope that is awake after a terminal failed activation', function (): void {
    createTestGatewayNode([
        'name' => 'gateway-1',
        'wireguard_address' => '10.6.0.1',
    ]);
    ProcessFacade::fake();
    config()->set(
        'orbit.updates.gateway_image',
        'ghcr.io/hardimpactdev/orbit-gateway:current@sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc',
    );
    [$node, $app, $instance] = create_runtime_hibernation_instance();
    Process::factory()->forOwner($app, $node)->create(['name' => 'queue']);
    $failedRun = app(OperationRunRecorder::class)->queued(
        operationId: "runtime-activation:app-instance-{$instance->id}",
        lane: 'gateway',
        operationType: 'runtime-activation',
        targetNodeId: $node->id,
        result: [
            'runtime_activation' => [
                'scope' => ['type' => 'app-instance', 'id' => $instance->id],
                'cold' => false,
                'dependencies' => [],
                'processes' => [],
            ],
        ],
    );
    app(OperationRunRecorder::class)->failed($failedRun->id, error: [
        'code' => 'runtime_activation_failed',
        'message' => 'The application could not be prepared.',
    ]);
    $executor = new RuntimeHibernationRecordingExecutor(awake: true);
    app()->instance(RunsInternalCommands::class, $executor);

    $this->call(
        'GET',
        "/api/runtime-activations/app-instance/{$instance->id}",
        server: [
            'REMOTE_ADDR' => $node->wireguard_address,
            'HTTP_X_ORBIT_RUNTIME_COLD' => '0',
        ],
    )->assertNoContent();

    expect(OperationRun::query()->where('operation_type', 'runtime-activation')->count())
        ->toBe(1)
        ->and($failedRun->refresh()->status)
        ->toBe(OperationStatus::Failed)
        ->and($executor->actions())
        ->toBe(['internal:caddy-config:runtime-states']);
});

it('preserves the failed soft progress page when the scope is still asleep and retry is absent', function (): void {
    createTestGatewayNode([
        'name' => 'gateway-1',
        'wireguard_address' => '10.6.0.1',
    ]);
    ProcessFacade::fake();
    config()->set(
        'orbit.updates.gateway_image',
        'ghcr.io/hardimpactdev/orbit-gateway:current@sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc',
    );
    [$node, $app, $instance] = create_runtime_hibernation_instance();
    Process::factory()->forOwner($app, $node)->create(['name' => 'queue']);
    $failedRun = app(OperationRunRecorder::class)->queued(
        operationId: "runtime-activation:app-instance-{$instance->id}",
        lane: 'gateway',
        operationType: 'runtime-activation',
        targetNodeId: $node->id,
        result: [
            'runtime_activation' => [
                'scope' => ['type' => 'app-instance', 'id' => $instance->id],
                'cold' => false,
                'dependencies' => [],
                'processes' => [
                    ['id' => 1, 'name' => 'queue', 'label' => 'Starting queue'],
                ],
            ],
        ],
    );
    app(OperationRunRecorder::class)->failed($failedRun->id, error: [
        'code' => 'runtime_activation_failed',
        'message' => 'The application could not be prepared.',
    ]);
    $executor = new RuntimeHibernationRecordingExecutor(awake: false);
    app()->instance(RunsInternalCommands::class, $executor);

    $this
        ->call(
            'GET',
            "/api/runtime-activations/app-instance/{$instance->id}",
            server: [
                'REMOTE_ADDR' => $node->wireguard_address,
                'HTTP_X_ORBIT_RUNTIME_COLD' => '0',
                'HTTP_X_FORWARDED_URI' => '/dashboard?tab=jobs',
            ],
        )
        ->assertServiceUnavailable()
        ->assertSee('orbit-spin', false)
        ->assertSee('logo-rotor', false)
        ->assertDontSee('Wake-up paused')
        ->assertDontSee('role="progressbar"', false)
        ->assertSee('Try again')
        ->assertSee('/dashboard?tab=jobs&orbit-wake-retry=1');

    expect(OperationRun::query()->where('operation_type', 'runtime-activation')->count())
        ->toBe(1)
        ->and($failedRun->refresh()->status)
        ->toBe(OperationStatus::Failed)
        ->and($executor->actions())
        ->toBe(['internal:caddy-config:runtime-states'])
        ->not->toContain('internal:process-systemd-service:start');
});

it('starts a new soft activation when retry is requested after a terminal failed run', function (): void {
    createTestGatewayNode([
        'name' => 'gateway-1',
        'wireguard_address' => '10.6.0.1',
    ]);
    ProcessFacade::fake();
    config()->set(
        'orbit.updates.gateway_image',
        'ghcr.io/hardimpactdev/orbit-gateway:current@sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc',
    );
    [$node, $app, $instance] = create_runtime_hibernation_instance();
    Process::factory()->forOwner($app, $node)->create(['name' => 'queue']);
    $failedRun = app(OperationRunRecorder::class)->queued(
        operationId: "runtime-activation:app-instance-{$instance->id}",
        lane: 'gateway',
        operationType: 'runtime-activation',
        targetNodeId: $node->id,
        result: [
            'runtime_activation' => [
                'scope' => ['type' => 'app-instance', 'id' => $instance->id],
                'cold' => false,
                'dependencies' => [],
                'processes' => [],
            ],
        ],
    );
    app(OperationRunRecorder::class)->failed($failedRun->id, error: [
        'code' => 'runtime_activation_failed',
        'message' => 'The application could not be prepared.',
    ]);
    $executor = new RuntimeHibernationRecordingExecutor(awake: false);
    app()->instance(RunsInternalCommands::class, $executor);

    $this
        ->call(
            'GET',
            "/api/runtime-activations/app-instance/{$instance->id}",
            server: [
                'REMOTE_ADDR' => $node->wireguard_address,
                'HTTP_X_ORBIT_RUNTIME_COLD' => '0',
                'HTTP_X_FORWARDED_URI' => '/dashboard?orbit-wake-retry=1',
            ],
        )
        ->assertServiceUnavailable()
        ->assertSee('orbit-spin', false)
        ->assertDontSee('Wake-up paused')
        ->assertDontSee('role="progressbar"', false);

    $runs = OperationRun::query()
        ->where('operation_type', 'runtime-activation')
        ->orderBy('created_at')
        ->get();

    expect($runs)
        ->toHaveCount(2)
        ->and($failedRun->refresh()->status)
        ->toBe(OperationStatus::Failed)
        ->and($runs->last()?->status)
        ->toBe(OperationStatus::Queued)
        ->and($runs->last()?->result['runtime_activation']['cold'] ?? null)
        ->toBeFalse()
        ->and($executor->actions())
        ->toBe(['internal:caddy-config:runtime-states']);
});

it('marks an instance with no configured processes awake on the detached soft activation runner', function (): void {
    createTestGatewayNode([
        'name' => 'gateway-1',
        'wireguard_address' => '10.6.0.1',
    ]);
    ProcessFacade::fake();
    config()->set(
        'orbit.updates.gateway_image',
        'ghcr.io/hardimpactdev/orbit-gateway:current@sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc',
    );
    [$node, , $instance] = create_runtime_hibernation_instance();
    $executor = new RuntimeHibernationRecordingExecutor;
    app()->instance(RunsInternalCommands::class, $executor);

    $this->call(
        'GET',
        "/api/runtime-activations/app-instance/{$instance->id}",
        server: [
            'REMOTE_ADDR' => $node->wireguard_address,
            'HTTP_X_ORBIT_RUNTIME_COLD' => '0',
        ],
    )->assertServiceUnavailable();

    $run = OperationRun::query()->sole();

    expect($executor->actions())
        ->toBe(['internal:caddy-config:runtime-states'])
        ->and($run->result['runtime_activation']['cold'] ?? null)
        ->toBeFalse()
        ->and($run->result['runtime_activation']['processes'] ?? null)
        ->toBe([]);

    app(RuntimeActivationRunner::class)->run($run->id);

    expect($run->refresh()->status)
        ->toBe(OperationStatus::Succeeded)
        ->and($executor->actions())
        ->toBe([
            'internal:caddy-config:runtime-states',
            'internal:caddy-config:runtime-states',
            'internal:caddy-config:runtime-awake',
        ])
        ->and($executor->actions())
        ->not->toContain('internal:runtime-dependencies:inspect')
        ->not->toContain('internal:caddy-config:runtime-warm');
});

it('does not restart a process group that is already marked awake', function (): void {
    createTestGatewayNode([
        'name' => 'gateway-1',
        'wireguard_address' => '10.6.0.1',
    ]);
    [$node, $app, $instance] = create_runtime_hibernation_instance();
    Process::factory()->forOwner($app, $node)->create(['name' => 'queue']);
    $executor = new RuntimeHibernationRecordingExecutor(awake: true);
    app()->instance(RunsInternalCommands::class, $executor);

    $this->call(
        'GET',
        "/api/runtime-activations/app-instance/{$instance->id}",
        server: [
            'REMOTE_ADDR' => $node->wireguard_address,
            'HTTP_X_ORBIT_RUNTIME_COLD' => '0',
        ],
    )->assertNoContent();

    expect($executor->actions())
        ->toBe(['internal:caddy-config:runtime-states']);
});

it('returns the soft progress page for a workspace and wakes inherited processes on the runner', function (): void {
    createTestGatewayNode([
        'name' => 'gateway-1',
        'wireguard_address' => '10.6.0.1',
    ]);
    ProcessFacade::fake();
    config()->set(
        'orbit.updates.gateway_image',
        'ghcr.io/hardimpactdev/orbit-gateway:current@sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc',
    );
    [$node, $app, $instance] = create_runtime_hibernation_instance();
    $workspace = Workspace::factory()->for($app, 'app')->create([
        'app_instance_id' => $instance->id,
        'name' => 'feature-a',
    ]);
    Process::factory()
        ->forOwner($app, $node)
        ->create([
            'name' => 'queue',
            'sort_order' => 1,
        ]);
    Process::factory()
        ->forOwner($workspace, $node)
        ->create([
            'name' => 'vite',
            'sort_order' => 2,
        ]);
    $executor = new RuntimeHibernationRecordingExecutor;
    app()->instance(RunsInternalCommands::class, $executor);

    $workspaceResponse = $this->call(
        'GET',
        "/api/runtime-activations/workspace/{$workspace->id}",
        server: [
            'REMOTE_ADDR' => $node->wireguard_address,
            'HTTP_X_ORBIT_RUNTIME_COLD' => '0',
            'HTTP_X_FORWARDED_URI' => '/workspace',
        ],
    );
    assert_runtime_activation_boot_screen($workspaceResponse, '/workspace');
    $workspaceResponse
        ->assertDontSee('Starting queue')
        ->assertDontSee('Starting vite');

    $run = OperationRun::query()
        ->where('operation_id', "runtime-activation:workspace-{$workspace->id}")
        ->sole();

    expect($executor->actions())
        ->toBe(['internal:caddy-config:runtime-states'])
        ->and($run->result['runtime_activation']['cold'] ?? null)
        ->toBeFalse()
        ->and(array_column($run->result['runtime_activation']['processes'], 'name'))
        ->toBe(['queue', 'vite']);

    app(RuntimeActivationRunner::class)->run($run->id);

    expect($run->refresh()->status)
        ->toBe(OperationStatus::Succeeded)
        ->and($executor->actions())
        ->toContain('internal:process-systemd-service:start')
        ->toContain('internal:caddy-config:runtime-awake')
        ->and($executor->actions())
        ->not->toContain('internal:runtime-dependencies:inspect')
        ->not->toContain('internal:caddy-config:runtime-warm');

    $startCount = array_count_values($executor->actions())['internal:process-systemd-service:start'] ?? 0;

    expect($startCount)->toBe(2);
});

it('hibernates an awake app instance after the configured HTTP idle interval', function (): void {
    createTestGatewayNode([
        'name' => 'gateway-1',
        'wireguard_address' => '10.6.0.1',
    ]);
    [$node, $app] = create_runtime_hibernation_instance();
    Process::factory()->forOwner($app, $node)->create(['name' => 'queue']);
    config()->set('orbit.runtime_hibernation.idle_seconds', 3600);
    $executor = new RuntimeHibernationRecordingExecutor(lastActivityAt: 1_767_268_799);
    app()->instance(RunsInternalCommands::class, $executor);

    app(RuntimeIdleHibernation::class)->hibernate(CarbonImmutable::parse('2026-01-01T13:00:00Z'));

    expect($executor->actions())
        ->toBe([
            'internal:caddy-config:runtime-states',
            'internal:caddy-config:runtime-asleep',
            'internal:process-systemd-service:stop',
        ]);
});

it('checks idle runtimes in a dedicated ten-minute daemon instead of the minute scheduler', function (): void {
    createTestGatewayNode([
        'name' => 'gateway-1',
        'wireguard_address' => '10.6.0.1',
    ]);
    [$node, $app] = create_runtime_hibernation_instance();
    Process::factory()->forOwner($app, $node)->create(['name' => 'queue']);
    config()->set('orbit.runtime_hibernation.idle_seconds', 3600);
    $executor = new RuntimeHibernationRecordingExecutor(lastActivityAt: 1_767_268_799);
    app()->instance(RunsInternalCommands::class, $executor);

    expect(config('orbit.runtime_hibernation.sweep_interval_minutes'))->toBe(10);

    app(OrbitScheduler::class)->tick(CarbonImmutable::parse('2026-01-01T13:10:00Z'));

    expect($executor->actions())->toBeEmpty();

    CarbonImmutable::setTestNow('2026-01-01T13:10:00Z');

    $this
        ->artisan('orbit-runtime-hibernator --once')
        ->expectsOutputToContain('Runtime hibernation sweep completed')
        ->assertSuccessful();

    expect($executor->actions())
        ->toBe([
            'internal:caddy-config:runtime-states',
            'internal:caddy-config:runtime-asleep',
            'internal:process-systemd-service:stop',
        ]);

    CarbonImmutable::setTestNow();
});

it('waits ten minutes after a completed hibernation sweep', function (): void {
    Sleep::fake();

    expect(config('orbit.runtime_hibernation.sweep_interval_minutes'))->toBe(10);

    $this
        ->artisan('orbit-runtime-hibernator --max-sweeps=2')
        ->expectsOutputToContain('Runtime hibernation sweep completed')
        ->assertSuccessful();

    Sleep::assertSlept(
        fn ($duration): bool => $duration->totalSeconds === 600.0,
    );
    Sleep::assertSleptTimes(1);
});

it('leaves recently active scopes awake', function (): void {
    createTestGatewayNode([
        'name' => 'gateway-1',
        'wireguard_address' => '10.6.0.1',
    ]);
    [$node, $app] = create_runtime_hibernation_instance();
    Process::factory()->forOwner($app, $node)->create(['name' => 'queue']);
    $executor = new RuntimeHibernationRecordingExecutor(
        lastActivityAt: 1_767_272_399,
        awake: true,
    );
    app()->instance(RunsInternalCommands::class, $executor);

    app(RuntimeIdleHibernation::class)->hibernate(CarbonImmutable::parse('2026-01-01T13:00:00Z'));

    expect($executor->actions())
        ->toBe(['internal:caddy-config:runtime-states']);
});

it('hibernates an uninitialized scope after the Caddy marker directory is reset', function (): void {
    createTestGatewayNode([
        'name' => 'gateway-1',
        'wireguard_address' => '10.6.0.1',
    ]);
    [$node, $app] = create_runtime_hibernation_instance();
    Process::factory()->forOwner($app, $node)->create(['name' => 'queue']);
    $executor = new RuntimeHibernationRecordingExecutor(
        awake: false,
        hibernated: false,
    );
    app()->instance(RunsInternalCommands::class, $executor);

    app(RuntimeIdleHibernation::class)->hibernate(CarbonImmutable::parse('2026-01-01T13:00:00Z'));

    expect($executor->actions())
        ->toBe([
            'internal:caddy-config:runtime-states',
            'internal:caddy-config:runtime-asleep',
            'internal:process-systemd-service:stop',
        ]);
});

it('does not repeatedly stop a scope that is already marked hibernated', function (): void {
    createTestGatewayNode([
        'name' => 'gateway-1',
        'wireguard_address' => '10.6.0.1',
    ]);
    [$node, $app] = create_runtime_hibernation_instance();
    Process::factory()->forOwner($app, $node)->create(['name' => 'queue']);
    $executor = new RuntimeHibernationRecordingExecutor(hibernated: true);
    app()->instance(RunsInternalCommands::class, $executor);

    app(RuntimeIdleHibernation::class)->hibernate(CarbonImmutable::parse('2026-01-01T13:00:00Z'));

    expect($executor->actions())
        ->toBe(['internal:caddy-config:runtime-states']);
});

it('prunes reconstructable dependencies after seven days while a scope is hibernated', function (): void {
    createTestGatewayNode([
        'name' => 'gateway-1',
        'wireguard_address' => '10.6.0.1',
    ]);
    create_runtime_hibernation_instance();
    config()->set('orbit.runtime_hibernation.dependency_idle_seconds', 604_800);
    $executor = new RuntimeHibernationRecordingExecutor(
        lastActivityAt: 1_766_664_000,
        hibernated: true,
        sourceActivityAt: 1_766_664_000,
        dependencies: [[
            'key' => 'composer',
            'label' => 'Installing PHP dependencies',
            'present' => true,
            'reconstructable' => true,
        ]],
    );
    app()->instance(RunsInternalCommands::class, $executor);

    app(RuntimeIdleHibernation::class)->hibernate(CarbonImmutable::parse('2026-01-08T13:00:01Z'));

    expect($executor->actions())
        ->toBe([
            'internal:caddy-config:runtime-states',
            'internal:runtime-dependencies:inspect',
            'internal:caddy-config:runtime-cold',
            'internal:runtime-dependencies:prune',
        ]);
});

it('does not re-prune a cold scope while its dependencies may be restoring', function (): void {
    createTestGatewayNode([
        'name' => 'gateway-1',
        'wireguard_address' => '10.6.0.1',
    ]);
    create_runtime_hibernation_instance();
    config()->set('orbit.runtime_hibernation.dependency_idle_seconds', 604_800);
    $executor = new RuntimeHibernationRecordingExecutor(
        lastActivityAt: 1_766_664_000,
        hibernated: true,
        cold: true,
        sourceActivityAt: 1_766_664_000,
        dependencies: [[
            'key' => 'composer',
            'label' => 'Installing PHP dependencies',
            'present' => true,
            'reconstructable' => true,
        ]],
    );
    app()->instance(RunsInternalCommands::class, $executor);

    app(RuntimeIdleHibernation::class)->hibernate(CarbonImmutable::parse('2026-01-08T13:00:01Z'));

    expect($executor->actions())
        ->toBe(['internal:caddy-config:runtime-states']);
});

it('keeps the cold marker when dependency pruning fails after it may have partially completed', function (): void {
    createTestGatewayNode([
        'name' => 'gateway-1',
        'wireguard_address' => '10.6.0.1',
    ]);
    create_runtime_hibernation_instance();
    config()->set('orbit.runtime_hibernation.dependency_idle_seconds', 604_800);
    $executor = new RuntimeHibernationRecordingExecutor(
        lastActivityAt: 1_766_664_000,
        hibernated: true,
        failingAction: 'internal:runtime-dependencies:prune',
        sourceActivityAt: 1_766_664_000,
        dependencies: [[
            'key' => 'composer',
            'label' => 'Installing PHP dependencies',
            'present' => true,
            'reconstructable' => true,
        ]],
    );
    app()->instance(RunsInternalCommands::class, $executor);

    app(RuntimeIdleHibernation::class)->hibernate(CarbonImmutable::parse('2026-01-08T13:00:01Z'));

    expect($executor->actions())
        ->toBe([
            'internal:caddy-config:runtime-states',
            'internal:runtime-dependencies:inspect',
            'internal:caddy-config:runtime-cold',
            'internal:runtime-dependencies:prune',
        ]);
});

it('coordinates cold markers across shared sources and warms only the activated scope', function (): void {
    createTestGatewayNode([
        'name' => 'gateway-1',
        'wireguard_address' => '10.6.0.1',
    ]);
    [$node, $app, $instance] = create_runtime_hibernation_instance();
    $sibling = AppInstance::factory()->for($app)->create([
        'name' => 'preview',
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: $app->path,
            document_root: $app->document_root,
            domain: 'preview.docs.test',
        ),
    ]);
    $executor = new RuntimeHibernationRecordingExecutor(
        lastActivityAt: 1_767_272_400,
        hibernated: true,
        sourceActivityAt: 1_766_664_000,
        dependencies: [[
            'key' => 'composer',
            'label' => 'Installing PHP dependencies',
            'present' => true,
            'reconstructable' => true,
        ]],
    );
    app()->instance(RunsInternalCommands::class, $executor);
    $scope = app(RuntimeHibernationScopes::class)->resolve('app-instance', $instance->id);

    expect($scope)->toBeInstanceOf(RuntimeHibernationScope::class);

    app(RuntimeDependencyColdStorage::class)->pruneIfEligible(
        $scope,
        [
            'key' => $scope->key(),
            'awake' => false,
            'hibernated' => true,
            'cold' => false,
            'last_activity_at' => 1_767_272_400,
        ],
        1_767_272_401,
    );
    app(RuntimeDependencyColdStorage::class)->markScopeWarm($scope);

    expect($executor->actions())
        ->toBe([
            'internal:caddy-config:runtime-states',
            'internal:runtime-dependencies:inspect',
            'internal:caddy-config:runtime-cold',
            'internal:caddy-config:runtime-cold',
            'internal:runtime-dependencies:prune',
            'internal:caddy-config:runtime-warm',
        ])
        ->and($executor->runtimeColdMarkerKeys())
        ->toBe([
            "app-instance-{$instance->id}",
            "app-instance-{$sibling->id}",
        ])
        ->and($executor->runtimeWarmMarkerKeys())
        ->toBe(["app-instance-{$instance->id}"]);
});

it('keeps dependencies when source activity is newer than the seven day threshold', function (): void {
    createTestGatewayNode([
        'name' => 'gateway-1',
        'wireguard_address' => '10.6.0.1',
    ]);
    create_runtime_hibernation_instance();
    config()->set('orbit.runtime_hibernation.dependency_idle_seconds', 604_800);
    $executor = new RuntimeHibernationRecordingExecutor(
        lastActivityAt: 1_766_664_000,
        hibernated: true,
        sourceActivityAt: 1_767_354_000,
        dependencies: [[
            'key' => 'composer',
            'label' => 'Installing PHP dependencies',
            'present' => true,
            'reconstructable' => true,
        ]],
    );
    app()->instance(RunsInternalCommands::class, $executor);

    app(RuntimeIdleHibernation::class)->hibernate(CarbonImmutable::parse('2026-01-08T13:00:01Z'));

    expect($executor->actions())
        ->toBe([
            'internal:caddy-config:runtime-states',
            'internal:runtime-dependencies:inspect',
        ]);
});

it('keeps dependencies when process lifecycle activity is newer than the seven day threshold', function (): void {
    createTestGatewayNode([
        'name' => 'gateway-1',
        'wireguard_address' => '10.6.0.1',
    ]);
    [$node, $app, $instance] = create_runtime_hibernation_instance();
    $process = Process::factory()->forOwner($app, $node)->create(['name' => 'queue']);
    ProcessEvent::factory()->create([
        'process_id' => $process->id,
        'app_id' => $app->id,
        'app_instance_id' => $instance->id,
        'workspace_id' => null,
        'node_id' => $node->id,
        'recorded_at' => CarbonImmutable::parse('2026-01-08T12:00:00Z'),
    ]);
    config()->set('orbit.runtime_hibernation.dependency_idle_seconds', 604_800);
    $executor = new RuntimeHibernationRecordingExecutor(
        lastActivityAt: 1_766_664_000,
        hibernated: true,
        sourceActivityAt: 1_766_664_000,
        dependencies: [[
            'key' => 'composer',
            'label' => 'Installing PHP dependencies',
            'present' => true,
            'reconstructable' => true,
        ]],
    );
    app()->instance(RunsInternalCommands::class, $executor);

    app(RuntimeIdleHibernation::class)->hibernate(CarbonImmutable::parse('2026-01-08T13:00:01Z'));

    expect($executor->actions())
        ->toBe(['internal:caddy-config:runtime-states']);
});

it('restores the awake marker when an idle process group cannot be stopped', function (): void {
    createTestGatewayNode([
        'name' => 'gateway-1',
        'wireguard_address' => '10.6.0.1',
    ]);
    [$node, $app] = create_runtime_hibernation_instance();
    Process::factory()->forOwner($app, $node)->create(['name' => 'queue']);
    $executor = new RuntimeHibernationRecordingExecutor(
        lastActivityAt: 1_767_268_799,
        awake: true,
        failingAction: 'internal:process-systemd-service:stop',
    );
    app()->instance(RunsInternalCommands::class, $executor);

    app(RuntimeIdleHibernation::class)->hibernate(CarbonImmutable::parse('2026-01-01T13:00:00Z'));

    expect($executor->actions())
        ->toBe([
            'internal:caddy-config:runtime-states',
            'internal:caddy-config:runtime-asleep',
            'internal:process-systemd-service:stop',
            'internal:caddy-config:runtime-awake',
        ]);
});

it('leaves a partially stopped process group asleep so the next request reconciles it', function (): void {
    createTestGatewayNode([
        'name' => 'gateway-1',
        'wireguard_address' => '10.6.0.1',
    ]);
    [$node, $app] = create_runtime_hibernation_instance();
    Process::factory()->forOwner($app, $node)->create(['name' => 'queue']);
    Process::factory()->forOwner($app, $node)->create(['name' => 'vite']);
    $executor = new RuntimeHibernationRecordingExecutor(
        lastActivityAt: 1_767_268_799,
        awake: true,
        failingAction: 'internal:process-systemd-service:stop',
        failingActionOccurrence: 2,
    );
    app()->instance(RunsInternalCommands::class, $executor);

    app(RuntimeIdleHibernation::class)->hibernate(CarbonImmutable::parse('2026-01-01T13:00:00Z'));

    expect($executor->actions())
        ->toBe([
            'internal:caddy-config:runtime-states',
            'internal:caddy-config:runtime-asleep',
            'internal:process-systemd-service:stop',
            'internal:process-systemd-service:stop',
        ]);
});

/**
 * @return array{Node, Project, AppInstance}
 */
function create_runtime_hibernation_instance(): array
{
    $node = createTestAppHostNode([
        'name' => 'app-dev-1',
        'wireguard_address' => '10.6.0.21',
    ]);
    $app = Project::factory()->for($node, 'node')->create([
        'name' => 'docs',
        'path' => '/home/orbit/apps/docs',
    ]);
    $instance = AppInstance::factory()->for($app)->create([
        'name' => 'development',
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: $app->path,
            document_root: $app->document_root,
            domain: 'docs.test',
        ),
    ]);

    return [$node, $app, $instance];
}

function runtime_hibernation_context(Node $node, Project $app, AppInstance $instance): ProcessOwnerContext
{
    return new ProcessOwnerContext(
        node: $node,
        app: $app,
        workspace: null,
        owner: $app,
        appInstance: $instance,
    );
}

/**
 * @mago-expect lint:file-name
 * @mago-expect lint:cyclomatic-complexity
 */
final class RuntimeHibernationRecordingExecutor implements RunsInternalCommands
{
    /** @var list<array{command: string, action: string, input: string|null}> */
    private array $calls = [];

    private int $failingActionCalls = 0;

    /** @mago-expect lint:excessive-parameter-list */
    public function __construct(
        private readonly int $lastActivityAt = 1_767_272_400,
        private bool $awake = false,
        private readonly bool $hibernated = false,
        private bool $cold = false,
        private readonly ?string $failingAction = null,
        private readonly int $failingActionOccurrence = 1,
        private readonly int $sourceActivityAt = 1_767_272_400,
        private readonly array $dependencies = [],
    ) {}

    public function runInternal(
        Node $node,
        string $commandName,
        array $arguments = [],
        array $commandOptions = [],
        array $transportOptions = [],
    ): RemoteShellResult {
        $action = is_string($arguments[0] ?? null) ? $arguments[0] : '';
        $input = is_string($transportOptions['input'] ?? null) ? $transportOptions['input'] : null;
        $this->calls[] = ['command' => $commandName, 'action' => $action, 'input' => $input];
        $call = "{$commandName}:{$action}";
        $shouldFail = false;

        if ($call === $this->failingAction) {
            $this->failingActionCalls++;
            $shouldFail = $this->failingActionCalls === $this->failingActionOccurrence;
        }

        if (! $shouldFail && $commandName === 'internal:caddy-config' && $action === 'runtime-awake') {
            $this->awake = true;
        }

        if (! $shouldFail && $commandName === 'internal:caddy-config' && $action === 'runtime-asleep') {
            $this->awake = false;
        }

        if (! $shouldFail && $commandName === 'internal:caddy-config' && $action === 'runtime-warm') {
            $this->cold = false;
        }

        if (! $shouldFail && $commandName === 'internal:caddy-config' && $action === 'runtime-cold') {
            $this->cold = true;
        }

        $data =
            $commandName === 'internal:caddy-config' && $action === 'runtime-states'
                ? [
                    'states' => [[
                        'key' => 'app-instance-1',
                        'awake' => false,
                        'hibernated' => false,
                        'cold' => false,
                        'last_activity_at' => $this->lastActivityAt,
                    ]],
                ]
                : [];

        if ($commandName === 'internal:caddy-config' && $action === 'runtime-states') {
            $input = $transportOptions['input'] ?? null;
            $decoded = is_string($input)
                ? json_decode($input, associative: true, flags: JSON_THROW_ON_ERROR)
                : [];
            $keys = is_array($decoded) && is_array($decoded['keys'] ?? null) ? $decoded['keys'] : [];
            $data['states'] = array_map(fn (string $key): array => [
                'key' => $key,
                'awake' => $this->awake,
                'hibernated' => $this->hibernated,
                'cold' => $this->cold,
                'last_activity_at' => $this->lastActivityAt,
            ], $keys);
        }

        if ($commandName === 'internal:runtime-dependencies' && $action === 'inspect') {
            $data = [
                'source_activity_at' => $this->sourceActivityAt,
                'dependencies' => $this->dependencies,
            ];
        }

        return new RemoteShellResult(
            exitCode: $shouldFail ? 1 : 0,
            stdout: json_encode([
                'success' => [
                    'data' => $data,
                    'meta' => [],
                ],
            ], JSON_THROW_ON_ERROR)
                ."\n",
            stderr: '',
            durationMs: 1,
        );
    }

    /**
     * @return list<string>
     */
    public function actions(): array
    {
        return array_map(
            static fn (array $call): string => "{$call['command']}:{$call['action']}",
            $this->calls,
        );
    }

    /**
     * @return list<string>
     */
    public function runtimeMarkerKeys(): array
    {
        return array_values(array_filter(array_map(
            static function (array $call): ?string {
                if (
                    $call['command'] !== 'internal:caddy-config'
                    || ! in_array($call['action'], ['runtime-asleep', 'runtime-awake'], strict: true)
                    || ! is_string($call['input'])
                ) {
                    return null;
                }

                $payload = json_decode($call['input'], associative: true, flags: JSON_THROW_ON_ERROR);
                $key = is_array($payload) ? $payload['key'] ?? null : null;

                return is_string($key) ? $key : null;
            },
            $this->calls,
        )));
    }

    /**
     * @return list<string>
     */
    public function runtimeColdMarkerKeys(): array
    {
        return $this->runtimeMarkerKeysForAction('runtime-cold');
    }

    /**
     * @return list<string>
     */
    public function runtimeWarmMarkerKeys(): array
    {
        return $this->runtimeMarkerKeysForAction('runtime-warm');
    }

    /**
     * @return list<string>
     */
    private function runtimeMarkerKeysForAction(string $action): array
    {
        return array_values(array_filter(array_map(
            static function (array $call) use ($action): ?string {
                if (
                    $call['command'] !== 'internal:caddy-config'
                    || $call['action'] !== $action
                    || ! is_string($call['input'])
                ) {
                    return null;
                }

                $payload = json_decode($call['input'], associative: true, flags: JSON_THROW_ON_ERROR);
                $key = is_array($payload) ? $payload['key'] ?? null : null;

                return is_string($key) ? $key : null;
            },
            $this->calls,
        )));
    }
}
