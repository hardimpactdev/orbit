<?php

declare(strict_types=1);

use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Nodes\NodeStatus;
use App\Enums\Processes\ProcessRuntime;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Models\Process;
use App\Services\Nodes\Roles\NodeRoleAssignmentService;
use App\Services\Nodes\Roles\RoleBaselines\RoleRuntimeConverger;
use App\Services\RemoteShell\RunsInternalCommands;
use App\Services\S3\S3ServiceConfigurator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('enacts Docker and the SeaweedFS runtime before activating the S3 role', function (): void {
    $runtime = new ProvisionedServiceRoleRecordingRuntime;
    app()->instance(RoleRuntimeConverger::class, $runtime);

    $node = Node::factory()->create([
        'name' => 'services1',
        'platform' => 'ubuntu_26-04',
        'wireguard_address' => '10.6.0.8',
        'status' => NodeStatus::Provisioning,
    ]);

    $assignment = app(NodeRoleAssignmentService::class)->addDuringCreation($node, 's3', [
        'data_path' => '/var/lib/orbit/s3',
    ]);

    expect($assignment->status)
        ->toBe(NodeRoleStatus::Active)
        ->and($runtime->tools)
        ->toBe(['docker'])
        ->and($runtime->processes)
        ->toBe(['s3:seaweedfs']);
});

it('enacts Docker and Plausible before activating the analytics role', function (): void {
    $runtime = new ProvisionedServiceRoleRecordingRuntime;
    app()->instance(RoleRuntimeConverger::class, $runtime);

    $databaseNode = provisionedServiceRoleDatabaseNode();
    $node = Node::factory()->create([
        'name' => 'services1',
        'platform' => 'ubuntu_26-04',
        'wireguard_address' => '10.6.0.8',
        'status' => NodeStatus::Provisioning,
    ]);

    $assignment = app(NodeRoleAssignmentService::class)->addDuringCreation($node, 'analytics', [
        'postgres_node_id' => $databaseNode->id,
        'clickhouse_node_id' => $databaseNode->id,
    ]);

    expect($assignment->status)
        ->toBe(NodeRoleStatus::Active)
        ->and($runtime->tools)
        ->toBe(['docker'])
        ->and($runtime->processes)
        ->toBe(['analytics:plausible']);
});

it('keeps provisioning incomplete when a service runtime cannot start', function (string $role): void {
    app()->instance(RoleRuntimeConverger::class, new ProvisionedServiceRoleRecordingRuntime(failProcess: true));

    $node = Node::factory()->create([
        'name' => "services1-{$role}",
        'platform' => 'ubuntu_26-04',
        'status' => NodeStatus::Provisioning,
    ]);
    $settings = ['data_path' => '/var/lib/orbit/s3'];

    if ($role === 'analytics') {
        $databaseNode = provisionedServiceRoleDatabaseNode();
        $settings = [
            'postgres_node_id' => $databaseNode->id,
            'clickhouse_node_id' => $databaseNode->id,
        ];
    }

    $assignment = app(NodeRoleAssignmentService::class)->addDuringCreation($node, $role, $settings);

    expect($assignment->status)
        ->toBe(NodeRoleStatus::Error)
        ->and($assignment->last_error)
        ->toBe("The {$role} runtime is unavailable.")
        ->and($assignment->converged_at)
        ->toBeNull()
        ->and($node->fresh()->status)
        ->toBe(NodeStatus::Provisioning);
})->with(['analytics', 's3']);

it('applies and starts the node-owned SeaweedFS Docker runtime', function (NodeRoleStatus $status): void {
    $executor = new ProvisionedServiceRoleInternalExecutor;
    app()->instance(RunsInternalCommands::class, $executor);

    $node = Node::factory()->create([
        'name' => 'services1',
        'platform' => 'ubuntu_26-04',
        'status' => NodeStatus::Provisioning,
    ]);
    $assignment = NodeRoleAssignment::factory()->for($node)->create([
        'role' => 's3',
        'status' => $status,
        'settings' => [
            'data_path' => '/var/lib/orbit/s3',
        ],
    ]);
    NodeTool::factory()->for($node)->create([
        'name' => 'docker',
        'expected_state' => 'installed',
    ]);
    $process = app(S3ServiceConfigurator::class)->configure($node, $assignment)->process;

    $converger = app(RoleRuntimeConverger::class);
    $converger->convergeTool($node, 'docker');
    $converger->convergeProcess($node, $process, 's3');

    expect($executor->commands)
        ->toBe([
            'internal:tool:run-script',
            'internal:tool:run-script',
            'internal:process-docker-container',
            'internal:process-docker-container',
        ])
        ->and($executor->dockerActions)
        ->toBe(['apply', 'start']);
})->with([
    NodeRoleStatus::Pending,
    NodeRoleStatus::Error,
]);

it('applies and starts the node-owned Plausible Docker Swarm runtime', function (): void {
    $executor = new ProvisionedServiceRoleInternalExecutor;
    app()->instance(RunsInternalCommands::class, $executor);

    $node = Node::factory()->create([
        'name' => 'services1',
        'platform' => 'ubuntu_26-04',
        'wireguard_address' => '10.6.0.8',
        'status' => NodeStatus::Provisioning,
    ]);
    NodeRoleAssignment::factory()->for($node)->create([
        'role' => 'analytics',
        'status' => NodeRoleStatus::Pending,
    ]);
    NodeTool::factory()->for($node)->create([
        'name' => 'docker',
        'expected_state' => 'installed',
    ]);
    $process = Process::factory()
        ->forOwner($node)
        ->create([
            'name' => 'plausible',
            'command' => 'run',
            'runtime' => ProcessRuntime::DockerSwarm,
            'runtime_config' => [
                'service' => 'plausible',
                'service_name' => 'orbit-plausible',
                'image' => 'ghcr.io/plausible/community-edition:v3.2.1',
                'environment' => [],
                'ports' => [],
                'mounts' => [],
                'labels' => [],
            ],
        ]);

    $converger = app(RoleRuntimeConverger::class);
    $converger->convergeTool($node, 'docker');
    $converger->convergeProcess($node, $process, 'analytics');

    expect($executor->commands)
        ->toBe([
            'internal:tool:run-script',
            'internal:tool:run-script',
            'internal:process-docker-swarm-service',
            'internal:process-docker-swarm-service',
            'internal:process-docker-swarm-service',
        ])
        ->and($executor->swarmActions)
        ->toBe(['ensure', 'apply', 'start'])
        ->and($executor->swarmPayloads[0])
        ->toBe(['advertise_address' => '10.6.0.8']);
});

it('throws when the persisted service runtime cannot be started', function (): void {
    $executor = new ProvisionedServiceRoleInternalExecutor(failStart: true);
    app()->instance(RunsInternalCommands::class, $executor);

    $node = Node::factory()->create([
        'name' => 'services1',
        'platform' => 'ubuntu_26-04',
        'status' => NodeStatus::Provisioning,
    ]);
    $assignment = NodeRoleAssignment::factory()->for($node)->create([
        'role' => 's3',
        'status' => NodeRoleStatus::Pending,
        'settings' => [
            'data_path' => '/var/lib/orbit/s3',
        ],
    ]);
    $process = app(S3ServiceConfigurator::class)->configure($node, $assignment)->process;

    expect(fn () => app(RoleRuntimeConverger::class)->convergeProcess($node, $process, 's3'))
        ->toThrow(\RuntimeException::class, "S3 process runtime unit 'orbit-seaweedfs' could not be started.");
});

it('removes the node-owned runtime before deleting the role process', function (string $role): void {
    $runtime = new ProvisionedServiceRoleRecordingRuntime;
    app()->instance(RoleRuntimeConverger::class, $runtime);

    $node = Node::factory()->create([
        'name' => "services1-{$role}",
        'platform' => 'ubuntu_26-04',
        'status' => NodeStatus::Active,
    ]);
    $settings = ['data_path' => '/var/lib/orbit/s3'];

    if ($role === 'analytics') {
        $databaseNode = provisionedServiceRoleDatabaseNode();
        $settings = [
            'postgres_node_id' => $databaseNode->id,
            'clickhouse_node_id' => $databaseNode->id,
        ];
    }

    $assignment = app(NodeRoleAssignmentService::class)->addDuringCreation($node, $role, $settings);

    app(NodeRoleAssignmentService::class)->remove($node, $role, force: true);

    expect($runtime->removedProcesses)
        ->toBe(["{$role}:".($role === 'analytics' ? 'plausible' : 'seaweedfs')])
        ->and(Process::query()->ownedBy($node)->count())
        ->toBe(0)
        ->and($assignment->fresh())
        ->toBeNull();
})->with(['analytics', 's3']);

function provisionedServiceRoleDatabaseNode(): Node
{
    $node = Node::factory()
        ->database()
        ->create([
            'name' => 'database1',
            'wireguard_address' => '10.6.0.4',
            'status' => NodeStatus::Active,
        ]);

    foreach ([
        'postgres' => 5432,
        'clickhouse' => 8123,
    ] as $service => $port) {
        Process::factory()
            ->forOwner($node)
            ->create([
                'name' => $service,
                'runtime' => ProcessRuntime::DockerSwarm,
                'runtime_config' => [
                    'service' => $service,
                    'endpoint' => [
                        'host' => '10.6.0.4',
                        'port' => $port,
                    ],
                    'credentials' => $service === 'postgres'
                        ? [
                            'username' => 'orbit',
                            'password' => 'database-password',
                        ]
                        : [],
                ],
            ]);
    }

    return $node;
}

final class ProvisionedServiceRoleRecordingRuntime extends RoleRuntimeConverger
{
    /**
     * @var list<string>
     */
    public array $tools = [];

    /**
     * @var list<string>
     */
    public array $processes = [];

    /**
     * @var list<string>
     */
    public array $removedProcesses = [];

    public function __construct(
        private readonly bool $failProcess = false,
    ) {}

    #[\Override]
    public function convergeTool(Node $node, string $toolName): void
    {
        $this->tools[] = $toolName;
    }

    #[\Override]
    public function convergeProcess(Node $node, Process $process, string $role): void
    {
        if ($this->failProcess) {
            throw new \RuntimeException("The {$role} runtime is unavailable.");
        }

        $this->processes[] = "{$role}:{$process->name}";
    }

    #[\Override]
    public function removeProcess(Node $node, Process $process, string $role): void
    {
        $this->removedProcesses[] = "{$role}:{$process->name}";
    }
}

final class ProvisionedServiceRoleInternalExecutor implements RunsInternalCommands
{
    /**
     * @var list<string>
     */
    public array $commands = [];

    /**
     * @var list<string>
     */
    public array $dockerActions = [];

    /**
     * @var list<string>
     */
    public array $swarmActions = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $swarmPayloads = [];

    public function __construct(
        private readonly bool $failStart = false,
    ) {}

    public function runInternal(
        Node $node,
        string $commandName,
        array $arguments = [],
        array $commandOptions = [],
        array $transportOptions = [],
    ): RemoteShellResult {
        $this->commands[] = $commandName;

        if ($commandName === 'internal:tool:run-script') {
            return $this->toolProbeResult();
        }

        if ($commandName === 'internal:process-docker-container') {
            $payload = $this->payload($transportOptions);
            $action = is_string($payload['action'] ?? null) ? $payload['action'] : '';
            $this->dockerActions[] = $action;

            if ($this->failStart && $action === 'start') {
                return new RemoteShellResult(1, '', 'Docker unavailable.', 1);
            }

            return (
                $action === 'apply'
                    ? $this->successResult(['outcome' => 'created'])
                    : new RemoteShellResult(0, '', '', 1)
            );
        }

        if ($commandName === 'internal:process-docker-swarm-service') {
            $action = is_string($arguments[0] ?? null) ? $arguments[0] : '';
            $this->swarmActions[] = $action;
            $this->swarmPayloads[] = $this->payload($transportOptions);

            if ($this->failStart && $action === 'start') {
                return new RemoteShellResult(1, '', 'Docker Swarm unavailable.', 1);
            }

            return new RemoteShellResult(0, '', '', 1);
        }

        return new RemoteShellResult(0, '', '', 1);
    }

    private function toolProbeResult(): RemoteShellResult
    {
        return $this->successResult([
            'exit_code' => 0,
            'stdout' => "/usr/bin/docker\t27.0\trunning\t\t\t\t\t\t\t\t1\t",
            'stderr' => '',
            'duration_ms' => 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function successResult(array $data): RemoteShellResult
    {
        return new RemoteShellResult(
            0,
            json_encode([
                'success' => [
                    'data' => $data,
                    'meta' => [],
                ],
            ], JSON_THROW_ON_ERROR),
            '',
            1,
        );
    }

    /**
     * @param  array<string, mixed>  $transportOptions
     * @return array<string, mixed>
     */
    private function payload(array $transportOptions): array
    {
        $input = $transportOptions['input'] ?? null;

        if (! is_string($input)) {
            return [];
        }

        $payload = json_decode($input, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($payload)) {
            return [];
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }
}
