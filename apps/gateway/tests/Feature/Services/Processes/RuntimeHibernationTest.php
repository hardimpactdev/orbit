<?php

declare(strict_types=1);

use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use App\Models\Workspace;
use App\Services\RemoteShell\RunsInternalCommands;
use App\Services\Schedules\OrbitScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('wakes an app instance on an exact serving-node request and marks it awake after startup', function (): void {
    createTestGatewayNode([
        'name' => 'gateway-1',
        'wireguard_address' => '10.6.0.1',
    ]);
    [$node, $app, $instance] = create_runtime_hibernation_instance();
    Process::factory()->forOwner($app, $node)->create(['name' => 'queue']);
    $executor = new RuntimeHibernationRecordingExecutor;
    app()->instance(RunsInternalCommands::class, $executor);

    $response = $this->call(
        'GET',
        "/api/runtime-activations/app-instance/{$instance->id}",
        server: ['REMOTE_ADDR' => $node->wireguard_address],
    );

    $response->assertNoContent();

    expect($executor->actions())
        ->toBe([
            'internal:caddy-config:runtime-states',
            'internal:process-systemd-service:start',
            'internal:caddy-config:runtime-awake',
        ]);
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
            server: ['REMOTE_ADDR' => $otherNode->wireguard_address],
        )
        ->assertForbidden()
        ->assertJsonPath('error.code', 'authorization_failed');

    expect($executor->actions())->toBeEmpty();
});

it('marks an instance with no configured processes awake without reporting a startup failure', function (): void {
    createTestGatewayNode([
        'name' => 'gateway-1',
        'wireguard_address' => '10.6.0.1',
    ]);
    [$node, , $instance] = create_runtime_hibernation_instance();
    $executor = new RuntimeHibernationRecordingExecutor;
    app()->instance(RunsInternalCommands::class, $executor);

    $this->call(
        'GET',
        "/api/runtime-activations/app-instance/{$instance->id}",
        server: ['REMOTE_ADDR' => $node->wireguard_address],
    )->assertNoContent();

    expect($executor->actions())
        ->toBe([
            'internal:caddy-config:runtime-states',
            'internal:caddy-config:runtime-awake',
        ]);
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
        server: ['REMOTE_ADDR' => $node->wireguard_address],
    )->assertNoContent();

    expect($executor->actions())
        ->toBe(['internal:caddy-config:runtime-states']);
});

it('wakes inherited instance and workspace processes as one workspace lifecycle group', function (): void {
    createTestGatewayNode([
        'name' => 'gateway-1',
        'wireguard_address' => '10.6.0.1',
    ]);
    [$node, $app, $instance] = create_runtime_hibernation_instance();
    $workspace = Workspace::factory()->for($app, 'app')->create([
        'app_instance_id' => $instance->id,
        'name' => 'feature-a',
    ]);
    Process::factory()->forOwner($app, $node)->create(['name' => 'queue']);
    Process::factory()->forOwner($workspace, $node)->create(['name' => 'vite']);
    $executor = new RuntimeHibernationRecordingExecutor;
    app()->instance(RunsInternalCommands::class, $executor);

    $this->call(
        'GET',
        "/api/runtime-activations/workspace/{$workspace->id}",
        server: ['REMOTE_ADDR' => $node->wireguard_address],
    )->assertNoContent();

    expect($executor->actions())
        ->toBe([
            'internal:caddy-config:runtime-states',
            'internal:process-systemd-service:start',
            'internal:process-systemd-service:start',
            'internal:caddy-config:runtime-awake',
        ]);
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

    app(OrbitScheduler::class)->tick(CarbonImmutable::parse('2026-01-01T13:00:00Z'));

    expect($executor->actions())
        ->toBe([
            'internal:caddy-config:runtime-states',
            'internal:caddy-config:runtime-asleep',
            'internal:process-systemd-service:stop',
        ]);
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

    app(OrbitScheduler::class)->tick(CarbonImmutable::parse('2026-01-01T13:00:00Z'));

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

    app(OrbitScheduler::class)->tick(CarbonImmutable::parse('2026-01-01T13:00:00Z'));

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

    app(OrbitScheduler::class)->tick(CarbonImmutable::parse('2026-01-01T13:00:00Z'));

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

    app(OrbitScheduler::class)->tick(CarbonImmutable::parse('2026-01-01T13:00:00Z'));

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

    app(OrbitScheduler::class)->tick(CarbonImmutable::parse('2026-01-01T13:00:00Z'));

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

/**
 * @mago-expect lint:file-name
 */
final class RuntimeHibernationRecordingExecutor implements RunsInternalCommands
{
    /** @var list<array{command: string, action: string}> */
    private array $calls = [];

    private int $failingActionCalls = 0;

    public function __construct(
        private readonly int $lastActivityAt = 1_767_272_400,
        private readonly bool $awake = false,
        private readonly bool $hibernated = false,
        private readonly ?string $failingAction = null,
        private readonly int $failingActionOccurrence = 1,
    ) {}

    public function runInternal(
        Node $node,
        string $commandName,
        array $arguments = [],
        array $commandOptions = [],
        array $transportOptions = [],
    ): RemoteShellResult {
        $action = is_string($arguments[0] ?? null) ? $arguments[0] : '';
        $this->calls[] = ['command' => $commandName, 'action' => $action];
        $call = "{$commandName}:{$action}";
        $shouldFail = false;

        if ($call === $this->failingAction) {
            $this->failingActionCalls++;
            $shouldFail = $this->failingActionCalls === $this->failingActionOccurrence;
        }

        $data =
            $commandName === 'internal:caddy-config' && $action === 'runtime-states'
                ? [
                    'states' => [[
                        'key' => 'app-instance-1',
                        'awake' => false,
                        'hibernated' => false,
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
                'awake' => $this->awake || $this->lastActivityAt < 1_767_272_400,
                'hibernated' => $this->hibernated,
                'last_activity_at' => $this->lastActivityAt,
            ], $keys);
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
}
