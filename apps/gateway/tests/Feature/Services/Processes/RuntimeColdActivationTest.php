<?php

declare(strict_types=1);

use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\OperationRun;
use App\Models\Process;
use App\Models\Project;
use App\Models\Workspace;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Processes\RuntimeActivationRunner;
use App\Services\Processes\RuntimeActivationRunnerLauncher;
use App\Services\RemoteShell\RunsInternalCommands;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process as ProcessFacade;
use Orbit\Core\Enums\OperationStatus;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    createTestGatewayNode([
        'name' => 'gateway-1',
        'wireguard_address' => '10.6.0.1',
    ]);
    ProcessFacade::fake();
    config()->set(
        'orbit.updates.gateway_image',
        'ghcr.io/hardimpactdev/orbit-gateway:current@sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc',
    );
});

it('returns an immediate minimal progress page with only detected dependencies and actual processes', function (): void {
    [$node, $app, $instance] = create_cold_runtime_instance();
    Process::factory()
        ->forOwner($app, $node)
        ->create([
            'name' => 'horizon',
            'sort_order' => 1,
        ]);
    Process::factory()
        ->forOwner($app, $node)
        ->create([
            'name' => 'vite',
            'sort_order' => 2,
        ]);
    app()->instance(RunsInternalCommands::class, new ColdRuntimeExecutor([
        [
            'key' => 'composer',
            'label' => 'Installing PHP dependencies',
            'present' => false,
            'reconstructable' => true,
        ],
        [
            'key' => 'npm',
            'label' => 'Installing frontend dependencies',
            'present' => false,
            'reconstructable' => true,
        ],
    ]));

    $response = $this->call(
        'GET',
        "/api/runtime-activations/app-instance/{$instance->id}",
        server: [
            'REMOTE_ADDR' => $node->wireguard_address,
            'HTTP_X_ORBIT_RUNTIME_COLD' => '1',
        ],
    );

    $response
        ->assertServiceUnavailable()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertSee('Waking docs.test')
        ->assertSee('Installing PHP dependencies')
        ->assertSee('Installing frontend dependencies')
        ->assertSee('Starting horizon')
        ->assertSee('Starting vite')
        ->assertDontSee('Starting queue')
        ->assertDontSee('/home/orbit/apps/docs')
        ->assertDontSee('composer install')
        ->assertDontSee('npm ci');

    $run = OperationRun::query()
        ->where('operation_id', "runtime-activation:app-instance-{$instance->id}")
        ->sole();

    expect($run->operation_type)
        ->toBe('runtime-activation')
        ->and($run->result['runtime_activation']['dependencies'] ?? null)
        ->toHaveCount(2);

    expect(array_column($run->result['runtime_activation']['processes'], 'name'))
        ->toBe(['horizon', 'vite'])
        ->and(array_column($run->result['runtime_activation']['processes'], 'label'))
        ->toBe(['Starting horizon', 'Starting vite']);
});

it('follows one in-flight activation instead of creating duplicate operations', function (): void {
    [$node, , $instance] = create_cold_runtime_instance();
    app()->instance(RunsInternalCommands::class, new ColdRuntimeExecutor([
        [
            'key' => 'composer',
            'label' => 'Installing PHP dependencies',
            'present' => false,
            'reconstructable' => true,
        ],
    ]));

    foreach (range(start: 1, end: 2) as $_) {
        $this->call(
            'GET',
            "/api/runtime-activations/app-instance/{$instance->id}",
            server: [
                'REMOTE_ADDR' => $node->wireguard_address,
                'HTTP_X_ORBIT_RUNTIME_COLD' => '1',
            ],
        )->assertServiceUnavailable();
    }

    expect(
        OperationRun::query()
            ->where('operation_id', "runtime-activation:app-instance-{$instance->id}")
            ->count(),
    )
        ->toBe(1);
});

it('replaces a queued activation whose detached runner never started', function (): void {
    [$node, , $instance] = create_cold_runtime_instance();
    app()->instance(RunsInternalCommands::class, new ColdRuntimeExecutor([
        [
            'key' => 'composer',
            'label' => 'Installing PHP dependencies',
            'present' => false,
            'reconstructable' => true,
        ],
    ]));
    config()->set('orbit.runtime_hibernation.activation_queued_timeout_seconds', 30);

    $this->call(
        'GET',
        "/api/runtime-activations/app-instance/{$instance->id}",
        server: [
            'REMOTE_ADDR' => $node->wireguard_address,
            'HTTP_X_ORBIT_RUNTIME_COLD' => '1',
        ],
    )->assertServiceUnavailable();

    $staleRun = OperationRun::query()->sole();
    $staleRun->timestamps = false;
    $staleRun->forceFill([
        'created_at' => now()->subSeconds(31),
        'updated_at' => now()->subSeconds(31),
    ])->save();

    $this->call(
        'GET',
        "/api/runtime-activations/app-instance/{$instance->id}",
        server: [
            'REMOTE_ADDR' => $node->wireguard_address,
            'HTTP_X_ORBIT_RUNTIME_COLD' => '1',
        ],
    )->assertServiceUnavailable();

    expect($staleRun->refresh()->status)
        ->toBe(OperationStatus::Failed)
        ->and($staleRun->error['code'] ?? null)
        ->toBe('runtime_activation_runner_stale')
        ->and(OperationRun::query()->where('operation_type', 'runtime-activation')->count())
        ->toBe(2)
        ->and(OperationRun::query()->latest('created_at')->first()?->id)
        ->not->toBe($staleRun->id);
});

it('replaces a running activation after its heartbeat expires', function (): void {
    [$node, , $instance] = create_cold_runtime_instance();
    app()->instance(RunsInternalCommands::class, new ColdRuntimeExecutor([
        [
            'key' => 'composer',
            'label' => 'Installing PHP dependencies',
            'present' => false,
            'reconstructable' => true,
        ],
    ]));
    config()->set('orbit.runtime_hibernation.activation_running_timeout_seconds', 1200);

    $this->call(
        'GET',
        "/api/runtime-activations/app-instance/{$instance->id}",
        server: [
            'REMOTE_ADDR' => $node->wireguard_address,
            'HTTP_X_ORBIT_RUNTIME_COLD' => '1',
        ],
    )->assertServiceUnavailable();

    $staleRun = OperationRun::query()->sole();
    $staleRun->timestamps = false;
    $staleRun->forceFill([
        'status' => OperationStatus::Running,
        'started_at' => now()->subSeconds(1201),
        'updated_at' => now()->subSeconds(1201),
    ])->save();

    $this->call(
        'GET',
        "/api/runtime-activations/app-instance/{$instance->id}",
        server: [
            'REMOTE_ADDR' => $node->wireguard_address,
            'HTTP_X_ORBIT_RUNTIME_COLD' => '1',
        ],
    )->assertServiceUnavailable();

    expect($staleRun->refresh()->status)
        ->toBe(OperationStatus::Failed)
        ->and($staleRun->error['code'] ?? null)
        ->toBe('runtime_activation_runner_stale')
        ->and(OperationRun::query()->where('operation_type', 'runtime-activation')->count())
        ->toBe(2);
});

it('does not take over a stale activation while its runner holds the side effect fence', function (): void {
    [$node, , $instance] = create_cold_runtime_instance();
    app()->instance(RunsInternalCommands::class, new ColdRuntimeExecutor([
        [
            'key' => 'composer',
            'label' => 'Installing PHP dependencies',
            'present' => false,
            'reconstructable' => true,
        ],
    ]));
    config()->set('orbit.runtime_hibernation.activation_running_timeout_seconds', 1200);

    $this->call(
        'GET',
        "/api/runtime-activations/app-instance/{$instance->id}",
        server: [
            'REMOTE_ADDR' => $node->wireguard_address,
            'HTTP_X_ORBIT_RUNTIME_COLD' => '1',
        ],
    )->assertServiceUnavailable();

    $run = OperationRun::query()->sole();
    $run->timestamps = false;
    $run->forceFill([
        'status' => OperationStatus::Running,
        'started_at' => now()->subSeconds(1201),
        'updated_at' => now()->subSeconds(1201),
    ])->save();
    $fence = Cache::lock("runtime-activation-fence:app-instance-{$instance->id}", 1000);

    expect($fence->get())->toBeTrue();

    try {
        $this->call(
            'GET',
            "/api/runtime-activations/app-instance/{$instance->id}",
            server: [
                'REMOTE_ADDR' => $node->wireguard_address,
                'HTTP_X_ORBIT_RUNTIME_COLD' => '1',
            ],
        )->assertServiceUnavailable();
    } finally {
        $fence->release();
    }

    expect($run->refresh()->status)
        ->toBe(OperationStatus::Running)
        ->and(OperationRun::query()->where('operation_type', 'runtime-activation')->count())
        ->toBe(1);
});

it('keeps following a running activation while its heartbeat is current', function (): void {
    [$node, , $instance] = create_cold_runtime_instance();
    app()->instance(RunsInternalCommands::class, new ColdRuntimeExecutor([
        [
            'key' => 'composer',
            'label' => 'Installing PHP dependencies',
            'present' => false,
            'reconstructable' => true,
        ],
    ]));

    $this->call(
        'GET',
        "/api/runtime-activations/app-instance/{$instance->id}",
        server: [
            'REMOTE_ADDR' => $node->wireguard_address,
            'HTTP_X_ORBIT_RUNTIME_COLD' => '1',
        ],
    )->assertServiceUnavailable();

    $run = OperationRun::query()->sole();
    app(OperationRunRecorder::class)->running($run->id);

    $this->call(
        'GET',
        "/api/runtime-activations/app-instance/{$instance->id}",
        server: [
            'REMOTE_ADDR' => $node->wireguard_address,
            'HTTP_X_ORBIT_RUNTIME_COLD' => '1',
        ],
    )->assertServiceUnavailable();

    expect(OperationRun::query()->where('operation_type', 'runtime-activation')->count())
        ->toBe(1)
        ->and($run->refresh()->status)
        ->toBe(OperationStatus::Running);
});

it('detects persistent cold state for a legacy route that does not send the rollout header', function (): void {
    [$node, , $instance] = create_cold_runtime_instance();
    $executor = new ColdRuntimeExecutor([
        [
            'key' => 'composer',
            'label' => 'Installing PHP dependencies',
            'present' => false,
            'reconstructable' => true,
        ],
    ]);
    app()->instance(RunsInternalCommands::class, $executor);

    $this
        ->call(
            'GET',
            "/api/runtime-activations/app-instance/{$instance->id}",
            server: ['REMOTE_ADDR' => $node->wireguard_address],
        )
        ->assertServiceUnavailable()
        ->assertSee('Installing PHP dependencies');

    expect(array_slice($executor->actions(), offset: 0, length: 2))
        ->toBe([
            'internal:caddy-config:runtime-states',
            'internal:runtime-dependencies:inspect',
        ]);
});

it('keeps the cold gate when dependency inspection fails', function (): void {
    [$node, , $instance] = create_cold_runtime_instance();
    $executor = new ColdRuntimeExecutor(
        dependencies: [],
        failingAction: 'internal:runtime-dependencies:inspect',
    );
    app()->instance(RunsInternalCommands::class, $executor);

    $this->call(
        'GET',
        "/api/runtime-activations/app-instance/{$instance->id}",
        server: [
            'REMOTE_ADDR' => $node->wireguard_address,
            'HTTP_X_ORBIT_RUNTIME_COLD' => '1',
        ],
    )->assertServiceUnavailable();

    expect($executor->actions())
        ->toBe(['internal:runtime-dependencies:inspect'])
        ->not->toContain('internal:caddy-config:runtime-warm')
        ->not->toContain('internal:process-systemd-service:start');
});

it('keeps the transparent fast wake when no dependencies need restoration', function (): void {
    [$node, $app, $instance] = create_cold_runtime_instance();
    Process::factory()->forOwner($app, $node)->create(['name' => 'horizon']);
    $executor = new ColdRuntimeExecutor([
        [
            'key' => 'composer',
            'label' => 'Installing PHP dependencies',
            'present' => true,
            'reconstructable' => true,
        ],
    ]);
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
        ->toBe(0)
        ->and($executor->actions())
        ->toContain('internal:process-systemd-service:start')
        ->toContain('internal:caddy-config:runtime-awake');
});

it('uses the workspace source and its inherited dynamic process plan', function (): void {
    [$node, $app, $instance] = create_cold_runtime_instance();
    $workspace = Workspace::factory()->for($app, 'app')->create([
        'app_instance_id' => $instance->id,
        'name' => 'feature-a',
        'path' => '/home/orbit/apps/docs/.worktrees/feature-a',
    ]);
    Process::factory()->forOwner($app, $node)->create(['name' => 'horizon']);
    Process::factory()->forOwner($workspace, $node)->create(['name' => 'vite']);
    $executor = new ColdRuntimeExecutor([
        [
            'key' => 'npm',
            'label' => 'Installing frontend dependencies',
            'present' => false,
            'reconstructable' => true,
        ],
    ]);
    app()->instance(RunsInternalCommands::class, $executor);

    $this
        ->call(
            'GET',
            "/api/runtime-activations/workspace/{$workspace->id}",
            server: [
                'REMOTE_ADDR' => $node->wireguard_address,
                'HTTP_X_ORBIT_RUNTIME_COLD' => '1',
            ],
        )
        ->assertServiceUnavailable()
        ->assertSee('Waking feature-a.docs.test')
        ->assertSee('Installing frontend dependencies')
        ->assertSee('Starting horizon')
        ->assertSee('Starting vite');

    expect($executor->runtimeDependencyPaths())
        ->toBe(['/home/orbit/apps/docs/.worktrees/feature-a']);
});

it('restores dependencies before starting the planned processes and clearing cold state', function (): void {
    [$node, $app, $instance] = create_cold_runtime_instance();
    Process::factory()->forOwner($app, $node)->create(['name' => 'horizon']);
    $executor = new ColdRuntimeExecutor([
        [
            'key' => 'composer',
            'label' => 'Installing PHP dependencies',
            'present' => false,
            'reconstructable' => true,
        ],
    ]);
    app()->instance(RunsInternalCommands::class, $executor);

    $this->call(
        'GET',
        "/api/runtime-activations/app-instance/{$instance->id}",
        server: [
            'REMOTE_ADDR' => $node->wireguard_address,
            'HTTP_X_ORBIT_RUNTIME_COLD' => '1',
        ],
    )->assertServiceUnavailable();

    $run = OperationRun::query()
        ->where('operation_id', "runtime-activation:app-instance-{$instance->id}")
        ->sole();

    app(RuntimeActivationRunner::class)->run($run->id);

    expect($run->refresh()->status->value)
        ->toBe('succeeded')
        ->and($executor->actions())
        ->toContain('internal:runtime-dependencies:restore')
        ->toContain('internal:caddy-config:runtime-warm')
        ->toContain('internal:process-systemd-service:start')
        ->toContain('internal:caddy-config:runtime-awake');

    $stepStatuses = $run
        ->events()
        ->where('event_type', 'step')
        ->get()
        ->mapWithKeys(fn ($event): array => [
            $event->payload['key'] => $event->payload['status'],
        ])
        ->all();

    expect($stepStatuses)
        ->toMatchArray([
            'dependency:composer' => 'done',
            'process:1' => 'done',
        ]);
});

it('keeps the cold marker and failed progress page when a process cannot start', function (): void {
    [$node, $app, $instance] = create_cold_runtime_instance();
    Process::factory()->forOwner($app, $node)->create(['name' => 'horizon']);
    $executor = new ColdRuntimeExecutor(
        dependencies: [[
            'key' => 'composer',
            'label' => 'Installing PHP dependencies',
            'present' => false,
            'reconstructable' => true,
        ]],
        failingAction: 'internal:process-systemd-service:start',
    );
    app()->instance(RunsInternalCommands::class, $executor);

    $this->call(
        'GET',
        "/api/runtime-activations/app-instance/{$instance->id}",
        server: [
            'REMOTE_ADDR' => $node->wireguard_address,
            'HTTP_X_ORBIT_RUNTIME_COLD' => '1',
        ],
    )->assertServiceUnavailable();

    $run = OperationRun::query()->sole();
    app(RuntimeActivationRunner::class)->run($run->id);

    expect($run->refresh()->status)
        ->toBe(OperationStatus::Failed)
        ->and($executor->actions())
        ->toContain('internal:runtime-dependencies:restore')
        ->toContain('internal:process-systemd-service:start')
        ->not->toContain('internal:caddy-config:runtime-warm');

    $this
        ->call(
            'GET',
            "/api/runtime-activations/app-instance/{$instance->id}",
            server: [
                'REMOTE_ADDR' => $node->wireguard_address,
                'HTTP_X_ORBIT_RUNTIME_COLD' => '1',
            ],
        )
        ->assertServiceUnavailable()
        ->assertSee('Wake-up paused')
        ->assertSee('Try again');
});

it('launches the activation runner as a detached one-shot gateway container', function (): void {
    $run = app(OperationRunRecorder::class)->queued(
        operationId: 'runtime-activation:app-instance-1',
        lane: 'gateway',
        operationType: 'runtime-activation',
        result: [
            'runtime_activation' => [
                'scope' => ['type' => 'app-instance', 'id' => 1],
                'dependencies' => [],
                'processes' => [],
            ],
        ],
    );

    app(RuntimeActivationRunnerLauncher::class)->launch($run);

    ProcessFacade::assertRan(function ($process) use ($run): bool {
        $command = (string) $process->command;

        return (
            str_contains($command, "'orbit:runtime-activation-runner'")
            && str_contains($command, "'--operation-run-id={$run->id}'")
            && str_contains($command, "'orbit.role=runtime-activation-runner'")
            && ! str_contains($command, '/var/run/docker.sock')
        );
    });
});

it('allows only one detached runner to claim an activation operation', function (): void {
    $run = app(OperationRunRecorder::class)->queued(
        operationId: 'runtime-activation:app-instance-1',
        lane: 'gateway',
        operationType: 'runtime-activation',
    );
    $recorder = app(OperationRunRecorder::class);

    expect($recorder->claimRunning($run->id)?->status)
        ->toBe(OperationStatus::Running)
        ->and($recorder->claimRunning($run->id))
        ->toBeNull();
});

/**
 * @return array{Node, Project, AppInstance}
 */
function create_cold_runtime_instance(): array
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

/**
 * @mago-expect lint:file-name
 */
final class ColdRuntimeExecutor implements RunsInternalCommands
{
    /** @var list<string> */
    private array $actions = [];

    /** @var list<string> */
    private array $runtimeDependencyPaths = [];

    /**
     * @param  list<array{key: string, label: string, present: bool, reconstructable: bool}>  $dependencies
     */
    public function __construct(
        private readonly array $dependencies,
        private readonly ?string $failingAction = null,
    ) {}

    public function runInternal(
        Node $node,
        string $commandName,
        array $arguments = [],
        array $commandOptions = [],
        array $transportOptions = [],
    ): RemoteShellResult {
        $action = is_string($arguments[0] ?? null) ? $arguments[0] : '';
        $call = $action === '' ? $commandName : "{$commandName}:{$action}";
        $this->actions[] = $call;

        if (
            $commandName === 'internal:runtime-dependencies'
            && is_string($arguments[1] ?? null)
        ) {
            $this->runtimeDependencyPaths[] = $arguments[1];
        }

        $data = match ([$commandName, $action]) {
            ['internal:runtime-dependencies', 'inspect'] => [
                'source_activity_at' => 1_767_268_800,
                'dependencies' => $this->dependencies,
            ],
            ['internal:caddy-config', 'runtime-states'] => [
                'states' => [[
                    'key' => 'app-instance-1',
                    'awake' => false,
                    'hibernated' => true,
                    'cold' => true,
                    'last_activity_at' => 1_767_268_800,
                ]],
            ],
            default => [],
        };

        if ($commandName === 'internal:caddy-config' && $action === 'runtime-states') {
            $input = $transportOptions['input'] ?? null;
            $decoded = is_string($input)
                ? json_decode($input, associative: true, flags: JSON_THROW_ON_ERROR)
                : [];
            $keys = is_array($decoded) && is_array($decoded['keys'] ?? null) ? $decoded['keys'] : [];
            $data['states'] = array_map(static fn (string $key): array => [
                'key' => $key,
                'awake' => false,
                'hibernated' => true,
                'cold' => true,
                'last_activity_at' => 1_767_268_800,
            ], $keys);
        }

        return new RemoteShellResult(
            exitCode: $call === $this->failingAction ? 1 : 0,
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
        return $this->actions;
    }

    /**
     * @return list<string>
     */
    public function runtimeDependencyPaths(): array
    {
        return $this->runtimeDependencyPaths;
    }
}
