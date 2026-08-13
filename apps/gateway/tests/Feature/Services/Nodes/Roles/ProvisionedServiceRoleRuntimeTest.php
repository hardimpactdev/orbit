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
use App\Models\ProxyRoute;
use App\Services\Ca\OrbitCaService;
use App\Services\Nodes\Roles\NodeRoleAssignmentService;
use App\Services\Nodes\Roles\RoleBaselines\RoleRuntimeConverger;
use App\Services\RemoteShell\RunsInternalCommands;
use App\Services\S3\S3ServiceConfigurator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

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

    expect($assignment->last_error)
        ->toBeNull()
        ->and($assignment->status)
        ->toBe(NodeRoleStatus::Active)
        ->and($runtime->tools)
        ->toBe(['docker'])
        ->and($runtime->processes)
        ->toBe(['s3:seaweedfs']);
});

it('publishes the active s3 backend into gateway dns during role activation', function (): void {
    $runtime = new ProvisionedServiceRoleRecordingRuntime;
    app()->instance(RoleRuntimeConverger::class, $runtime);

    $configRoot = storage_path('framework/testing/s3-role-dns-'.uniqid());
    config()->set('orbit.paths.config_root', $configRoot);
    app()->forgetInstance(\App\Services\Dns\DnsmasqReconciler::class);
    \Illuminate\Support\Facades\Process::fake();

    $router = Node::factory()->create([
        'name' => 'gateway-1',
        'wireguard_address' => '10.6.0.1',
        'status' => NodeStatus::Active,
    ]);
    NodeRoleAssignment::factory()->for($router)->create([
        'role' => 'router',
        'status' => NodeRoleStatus::Active,
    ]);

    $node = Node::factory()->create([
        'name' => 'services1',
        'platform' => 'ubuntu_26-04',
        'wireguard_address' => '10.6.0.14',
        'status' => NodeStatus::Active,
    ]);

    $assignment = app(NodeRoleAssignmentService::class)->addDuringCreation($node, 's3', [
        'data_path' => '/var/lib/orbit/s3',
    ]);

    expect($assignment->status)
        ->toBe(NodeRoleStatus::Active)
        ->and(\Illuminate\Support\Facades\File::get($configRoot.'/dnsmasq.d/20-proxy-records.conf'))
        ->toContain('address=/services1.s3.orbit/10.6.0.14')
        ->toContain('address=/orbit/10.6.0.1');

    \Illuminate\Support\Facades\File::deleteDirectory($configRoot);
});

it('enacts Docker, Plausible, and the private route before activating the analytics role', function (): void {
    $runtime = new ProvisionedServiceRoleRecordingRuntime;
    $executor = new ProvisionedServiceRoleInternalExecutor;
    app()->instance(RoleRuntimeConverger::class, $runtime);
    app()->instance(RunsInternalCommands::class, $executor);
    app()->instance(OrbitCaService::class, new ProvisionedServiceRoleFakeCa);

    Node::factory()
        ->router()
        ->create([
            'name' => 'gateway-1',
            'wireguard_address' => '10.6.0.2',
            'status' => NodeStatus::Active,
        ]);

    $databaseNode = provisioned_service_role_database_node();
    $node = Node::factory()->create([
        'name' => 'services1',
        'platform' => 'ubuntu_26-04',
        'wireguard_address' => '10.6.0.8',
        'status' => NodeStatus::Provisioning,
    ]);

    $assignment = app(NodeRoleAssignmentService::class)->addDuringCreation(
        $node,
        'analytics',
        provisioned_service_role_analytics_settings($databaseNode),
    );

    expect($assignment->last_error)
        ->toBeNull()
        ->and($assignment->status)
        ->toBe(NodeRoleStatus::Active)
        ->and($runtime->tools)
        ->toBe(['docker'])
        ->and($runtime->processes)
        ->toBe(['analytics:plausible'])
        ->and($executor->analyticsStatusAtCaddyWrite)
        ->toBe(NodeRoleStatus::Pending)
        ->and(ProxyRoute::query()->where('domain', 'analytics.orbit')->where('owner_type', 'router')->exists())
        ->toBeTrue()
        ->and($executor->commands)
        ->toContain('internal:managed-file', 'internal:caddy-config');
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
        $databaseNode = provisioned_service_role_database_node();
        $settings = provisioned_service_role_analytics_settings($databaseNode);
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

it('keeps analytics provisioning incomplete when the private route cannot be enacted', function (): void {
    $runtime = new ProvisionedServiceRoleRecordingRuntime;
    $executor = new ProvisionedServiceRoleInternalExecutor(failCaddyWrite: true);
    app()->instance(RoleRuntimeConverger::class, $runtime);
    app()->instance(RunsInternalCommands::class, $executor);
    app()->instance(OrbitCaService::class, new ProvisionedServiceRoleFakeCa);

    Node::factory()
        ->router()
        ->create([
            'name' => 'gateway-1',
            'wireguard_address' => '10.6.0.2',
            'status' => NodeStatus::Active,
        ]);

    $databaseNode = provisioned_service_role_database_node();
    $node = Node::factory()->create([
        'name' => 'services1',
        'platform' => 'ubuntu_26-04',
        'wireguard_address' => '10.6.0.14',
        'status' => NodeStatus::Provisioning,
    ]);

    $assignment = app(NodeRoleAssignmentService::class)->addDuringCreation(
        $node,
        'analytics',
        provisioned_service_role_analytics_settings($databaseNode),
    );

    expect($assignment->status)
        ->toBe(NodeRoleStatus::Error)
        ->and($assignment->last_error)
        ->toBe('Failed to write Caddy site analytics.orbit on gateway-1: Caddy write failed.')
        ->and($runtime->processes)
        ->toBe(['analytics:plausible']);
});

it('removes the private analytics route and its artifacts with the role', function (): void {
    $runtime = new ProvisionedServiceRoleRecordingRuntime;
    $executor = new ProvisionedServiceRoleInternalExecutor;
    app()->instance(RoleRuntimeConverger::class, $runtime);
    app()->instance(RunsInternalCommands::class, $executor);
    app()->instance(OrbitCaService::class, new ProvisionedServiceRoleFakeCa);

    Node::factory()
        ->router()
        ->create([
            'name' => 'gateway-1',
            'wireguard_address' => '10.6.0.2',
            'status' => NodeStatus::Active,
        ]);

    $databaseNode = provisioned_service_role_database_node();
    $node = Node::factory()->create([
        'name' => 'services1',
        'platform' => 'ubuntu_26-04',
        'wireguard_address' => '10.6.0.14',
        'status' => NodeStatus::Active,
    ]);

    app(NodeRoleAssignmentService::class)->addDuringCreation(
        $node,
        'analytics',
        provisioned_service_role_analytics_settings($databaseNode),
    );
    app(NodeRoleAssignmentService::class)->remove($node, 'analytics', force: true);

    expect(ProxyRoute::query()->where('domain', 'analytics.orbit')->exists())
        ->toBeFalse()
        ->and($executor->caddyActions)
        ->toContain('write-site', 'remove-site');
});

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
            'internal:managed-file',
            'internal:managed-file',
            'internal:process-docker-container',
            'internal:process-docker-container',
        ])
        ->and($executor->dockerActions)
        ->toBe(['apply', 'start'])
        ->and($executor->managedFileActions)
        ->toBe(['probe', 'write'])
        ->and($executor->managedFilePayloads[1])
        ->toMatchArray([
            'path' => '/var/lib/orbit/s3/s3.json',
            'mode' => '0600',
            'directory_mode' => '0750',
        ]);
})->with([
    NodeRoleStatus::Pending,
    NodeRoleStatus::Error,
]);

it('removes a replaced legacy Docker runtime before applying its canonical process', function (): void {
    $executor = new ProvisionedServiceRoleInternalExecutor;
    app()->instance(RunsInternalCommands::class, $executor);

    $node = Node::factory()->create([
        'name' => 'database1',
        'platform' => 'ubuntu_26-04',
        'wireguard_address' => '10.6.0.4',
        'status' => NodeStatus::Active,
    ]);
    $descriptor = app(\App\Services\Processes\ProcessServiceCatalog::class)->resolve(
        service: 'valkey',
        version: '8',
        runtime: ProcessRuntime::Docker,
        node: $node,
        processName: 'valkey',
    );
    $process = Process::factory()
        ->forOwner($node)
        ->create([
            'name' => 'valkey',
            'command' => $descriptor->command,
            'runtime' => ProcessRuntime::Docker,
            'runtime_config' => [
                ...$descriptor->runtimeConfig,
                'replaces_runtime_unit' => 'redis',
            ],
        ]);

    app(RoleRuntimeConverger::class)->convergeProcess($node, $process, 'database');

    expect($executor->dockerActions)
        ->toBe(['remove', 'apply', 'start'])
        ->and($executor->dockerPayloads[0]['container'])
        ->toBe('redis')
        ->and($process->fresh()->runtime_config)
        ->not->toHaveKey('replaces_runtime_unit');
});

it('applies and starts the node-owned Plausible Docker runtime on WireGuard', function (): void {
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
            'runtime' => ProcessRuntime::Docker,
            'runtime_config' => [
                'service' => 'plausible',
                'service_name' => 'orbit-plausible',
                'image' => 'ghcr.io/plausible/community-edition:v3.2.1',
                'environment' => [
                    'BASE_URL' => 'https://analytics.orbit',
                ],
                'ports' => [
                    [
                        'host' => '10.6.0.8',
                        'published' => 8000,
                        'target' => 8000,
                        'protocol' => 'tcp',
                    ],
                ],
                'mounts' => [],
                'labels' => [],
            ],
            'credentials' => [
                'environment' => [
                    'DATABASE_URL' => 'postgres://orbit:secret@10.6.0.4:5432/plausible_db',
                    'CLICKHOUSE_DATABASE_URL' => 'http://plausible:secret@10.6.0.4:8123/plausible_events_db',
                    'SECRET_KEY_BASE' => Str::random(64),
                ],
            ],
        ]);

    $converger = app(RoleRuntimeConverger::class);
    $converger->convergeTool($node, 'docker');
    $converger->convergeProcess($node, $process, 'analytics');

    expect($executor->commands)
        ->toBe([
            'internal:tool:run-script',
            'internal:tool:run-script',
            'internal:process-docker-container',
            'internal:process-docker-container',
        ])
        ->and($executor->dockerActions)
        ->toBe(['apply', 'start'])
        ->and($executor->dockerPayloads[0]['spec']['ports'][0])
        ->toMatchArray([
            'host' => '10.6.0.8',
            'published' => 8000,
            'target' => 8000,
        ])
        ->and($executor->dockerPayloads[0]['spec']['environment'])
        ->toHaveKeys(['DATABASE_URL', 'CLICKHOUSE_DATABASE_URL', 'SECRET_KEY_BASE']);
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
        $databaseNode = provisioned_service_role_database_node();
        $settings = provisioned_service_role_analytics_settings($databaseNode);
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

function provisioned_service_role_database_node(): Node
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
        $password = Str::random(32);

        Process::factory()
            ->forOwner($node)
            ->create([
                'name' => $service,
                'runtime' => ProcessRuntime::Docker,
                'runtime_config' => [
                    'service' => $service,
                    ...($service === 'postgres' ? ['version_family' => '16'] : []),
                    'endpoint' => [
                        'host' => '10.6.0.4',
                        'port' => $port,
                    ],
                ],
                'credentials' => [
                    'database' => $service === 'postgres' ? 'plausible_db' : 'plausible_events_db',
                    'username' => $service === 'postgres' ? 'orbit' : 'plausible',
                    'password' => $password,
                    'environment' => [
                        $service === 'postgres' ? 'POSTGRES_PASSWORD' : 'CLICKHOUSE_PASSWORD' => $password,
                    ],
                ],
            ]);
    }

    return $node;
}

/** @return array{postgres_node_id: int, postgres_process_id: int, clickhouse_node_id: int} */
function provisioned_service_role_analytics_settings(Node $databaseNode): array
{
    $postgres = Process::query()
        ->ownedBy($databaseNode)
        ->where('runtime_config->service', 'postgres')
        ->sole();

    return [
        'postgres_node_id' => $databaseNode->id,
        'postgres_process_id' => $postgres->id,
        'clickhouse_node_id' => $databaseNode->id,
    ];
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

final readonly class ProvisionedServiceRoleFakeCa extends OrbitCaService
{
    /** @return array{cert: string, key: string} */
    #[\Override]
    public function issueLeaf(string $host, array $additionalSans = []): array
    {
        $directory = storage_path('framework/testing/provisioned-service-role-ca');
        File::ensureDirectoryExists($directory);
        File::put("{$directory}/{$host}.crt", "certificate-for-{$host}");
        File::put("{$directory}/{$host}.key", "key-for-{$host}");

        return [
            'cert' => "{$directory}/{$host}.crt",
            'key' => "{$directory}/{$host}.key",
        ];
    }
}

/**
 * @mago-expect lint:single-class-per-file
 * @mago-expect lint:cyclomatic-complexity
 */
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
     * @var list<array<string, mixed>>
     */
    public array $dockerPayloads = [];

    /**
     * @var list<string>
     */
    public array $managedFileActions = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $managedFilePayloads = [];

    /**
     * @var list<string>
     */
    public array $caddyActions = [];

    public ?NodeRoleStatus $analyticsStatusAtCaddyWrite = null;

    public function __construct(
        private readonly bool $failStart = false,
        private readonly bool $failCaddyWrite = false,
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
            $this->dockerPayloads[] = $payload;

            if ($this->failStart && $action === 'start') {
                return new RemoteShellResult(1, '', 'Docker unavailable.', 1);
            }

            return (
                $action === 'apply'
                    ? $this->successResult(['outcome' => 'created'])
                    : new RemoteShellResult(0, '', '', 1)
            );
        }

        if ($commandName === 'internal:managed-file') {
            $action = is_string($arguments[0] ?? null) ? $arguments[0] : '';
            $this->managedFileActions[] = $action;
            $this->managedFilePayloads[] = $this->payload($transportOptions);

            return (
                $action === 'probe'
                    ? $this->successResult(['exists' => false])
                    : $this->successResult(['outcome' => 'written'])
            );
        }

        if ($commandName === 'internal:caddy-config') {
            $action = is_string($arguments[0] ?? null) ? $arguments[0] : '';
            $this->caddyActions[] = $action;

            if ($action === 'write-site') {
                $this->analyticsStatusAtCaddyWrite = NodeRoleAssignment::query()
                    ->where('role', 'analytics')
                    ->first()
                    ?->status;
            }

            if ($this->failCaddyWrite && $action === 'write-site') {
                return new RemoteShellResult(1, '', 'Caddy write failed.', 1);
            }

            return $this->successResult(['action' => $action]);
        }

        return new RemoteShellResult(0, '', '', 1);
    }

    private function toolProbeResult(): RemoteShellResult
    {
        return $this->successResult([
            'exit_code' => 0,
            'stdout' =>
                json_encode([
                    'name' => 'docker',
                    'installed' => true,
                    'path' => '/usr/bin/docker',
                    'version' => '27.0',
                    'state' => 'running',
                    'container_exists' => null,
                    'container_state' => null,
                    'container_spec_hash' => null,
                    'provider_reachable' => true,
                    'provider_error' => null,
                ], JSON_THROW_ON_ERROR)."\n",
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

        $payload = json_decode(
            json: $input,
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        if (! is_array($payload)) {
            return [];
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }
}
