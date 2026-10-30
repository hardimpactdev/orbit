<?php

declare(strict_types=1);

namespace App\Actions\DatabaseServers;

use App\Data\DatabaseServers\CreateDatabaseServerData;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseConnections\DockerPublishedPort;
use App\Domain\DatabaseServers\DatabaseServerProcess;
use App\Domain\DatabaseServers\ValkeyAcl;
use App\Domain\DatabaseServers\ValkeyProcessAdoption;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessSpecification;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Validates and reserves server and Process intent; Swamp executes every Node step. */
final readonly class CreateDatabaseServerAction
{
    private const int ROOT_PASSWORD_LENGTH = 48;

    public function __construct(
        private ProcessTargetResolver $targets,
        private ProcessSpecification $specifications,
        private ValkeyProcessAdoption $adoption,
        private RecordDatabaseOperationAction $operations,
    ) {}

    public function execute(CreateDatabaseServerData $data): Operation
    {
        return DB::transaction(fn (): Operation => $this->provisionReserved($data));
    }

    private function provisionReserved(CreateDatabaseServerData $data): Operation
    {
        $node = Node::query()->findOrFail($data->nodeId);
        $target = $this->targets->forNodeAdmission($node);

        if (! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            throw new ResourceOperationException(
                errorCode: 'process.wireguard_ip_missing',
                message: "Node [{$node->name}] has no WireGuard address.",
                status: 422,
            );
        }

        DatabaseServerProcess::validatePrivateAddresses($node);
        if ($data->driver === DatabaseDriver::Pgsql && preg_match(DatabaseServerProcess::POSTGRES_TAG_PATTERN, $data->tag) !== 1) {
            throw new ResourceOperationException('validation.failed', 'Managed PostgreSQL requires a major 18 image tag.', 422);
        }
        $adoption = null;

        if ($data->processId !== null) {
            if ($data->driver !== DatabaseDriver::Redis) {
                throw ValkeyProcessAdoption::refused('Only a redis server can adopt a Process.');
            }

            $adoption = $this->adoption->admit($node, $data->slug, $data->processId, (string) $data->connection);
        }

        $reserved = $this->reserve($data, $node, $adoption);
        $server = $reserved['server'];
        // Only an explicit create/retry opts an existing server into private publication checks.
        $server->update(['private_endpoints_managed' => true]);

        $process = $server->process;
        $processData = $server->driver === DatabaseDriver::Redis
            ? DatabaseServerProcess::valkeyData($server, $node, $process->name ?? $server->slug,
                ($process === null ? null : DatabaseServerProcess::valkeyVolume($process)) ?? DatabaseServerProcess::volume($server->slug))
            : DatabaseServerProcess::data($server, $node, $server->root_password);
        $attributes = $this->specifications->attributes($processData, $target);
        $initialize = $server->driver !== DatabaseDriver::Redis && ($server->status !== LifecycleStatus::Active || $process === null || DatabaseServerProcess::holdsRootPassword($process));
        $initialEnvironment = $initialize ? $attributes['runtime_config']['environment'] : [];
        $attributes['runtime_config']['environment'] = [];
        $validateExisting = $adoption !== null && $server->status !== LifecycleStatus::Active;
        if ($process === null) {
            $process = Process::query()->create([
                'owner_type' => Node::class, 'owner_id' => $node->id, 'name' => $server->slug,
                ...$attributes, 'desired_state' => DesiredProcessState::Running, 'status' => LifecycleStatus::Provisioning,
            ]);
            $server->update(['process_id' => $process->id]);
        }
        $desired = clone $process;
        $desired->fill($attributes);
        $docker = new DockerProcessRenderer;
        $scrubSpec = $docker->specHash($desired, $target);
        $containerInput = $docker->containerInput($desired, $target);
        unset($containerInput['environment']);
        $initial = clone $desired;
        $initial->runtime_config = [...$desired->runtime_config, 'environment' => $initialEnvironment];
        $private = ['password' => $server->root_password, 'environment' => $initialEnvironment,
            'acl' => $server->driver === DatabaseDriver::Redis ? ValkeyAcl::file($server) : '',
            'payload' => $server->driver === DatabaseDriver::Redis ? (object) ['users' => array_map(static fn ($user): array => ['name' => $user->name, 'rules' => $user->rules, 'hashes' => $user->passwordHashes], array_values(ValkeyAcl::users($server))),
                'default_open' => $server->default_open] : (object) []];

        return $this->operations->execute('database_server.create', 'database_server', $server->id, $node->id,
            ['server_id' => $server->id, 'driver' => $server->driver->value, 'created' => $reserved['created'],
                'process_id' => $process->id, 'container' => $docker->containerName($process), 'container_input' => $containerInput,
                'spec' => $docker->specHash($initial, $target), 'scrub_spec' => $scrubSpec, 'initialize' => $initialize,
                'validate_existing' => $validateExisting, 'was_active' => $server->status === LifecycleStatus::Active,
                'host' => $node->wireguard_ip, 'port' => $server->port, 'default_open' => $server->default_open,
                'volume' => $server->driver === DatabaseDriver::Redis ? (DatabaseServerProcess::valkeyVolume($process) ?? DatabaseServerProcess::volume($server->slug)) : null,
                'adoption_connection_id' => $adoption['connection']->id ?? null],
            ['database-apply' => ['name' => 'database.server-create', 'input' => $private, 'protected' => ['password', 'environment', 'acl', 'payload']]],
            ['server' => ['status' => 'active', 'failed_step' => null, 'error_code' => null],
                'process' => ['runtime_config' => $desired->runtime_config, 'working_directory' => $desired->working_directory, 'restart_policy' => $desired->restart_policy], 'adopted_connection' => $data->connection]);
    }

    /**
     * @param  array{process: Process, connection: DatabaseConnection, port: int}|null  $adoption
     * @return array{server: DatabaseServer, created: bool}
     */
    private function reserve(CreateDatabaseServerData $data, Node $node, ?array $adoption): array
    {
        try {
            return DB::transaction(function () use ($data, $node, $adoption): array {
                $existing = DatabaseServer::query()
                    ->where('slug', $data->slug)
                    ->lockForUpdate()
                    ->first();

                if ($existing instanceof DatabaseServer) {
                    if (
                        $existing->node_id !== $node->id
                        || $existing->driver !== $data->driver
                        || $existing->tag !== $data->tag
                        || ($data->processId === null ? $existing->port !== $data->port : $existing->process_id !== $data->processId)
                    ) {
                        throw $this->slugConflict($data->slug);
                    }

                    return ['server' => $existing, 'created' => false];
                }

                if ($adoption !== null) {
                    $server = DatabaseServer::query()->create([
                        'slug' => $data->slug,
                        'driver' => DatabaseDriver::Redis,
                        'node_id' => $node->id,
                        'process_id' => $adoption['process']->id,
                        'tag' => $data->tag,
                        'port' => $adoption['port'],
                        'root_password' => $adoption['connection']->password ?: Str::random(self::ROOT_PASSWORD_LENGTH),
                        'default_open' => ! filled($adoption['connection']->password),
                        'status' => LifecycleStatus::Provisioning,
                    ]);

                    return ['server' => $server, 'created' => true];
                }

                $port = (int) $data->port;
                $this->assertPortFree($node, $port);

                $server = DatabaseServer::query()->create([
                    'slug' => $data->slug,
                    'driver' => $data->driver,
                    'node_id' => $node->id,
                    'process_id' => null,
                    'tag' => $data->tag,
                    'port' => $port,
                    'root_password' => Str::random(self::ROOT_PASSWORD_LENGTH),
                    'status' => LifecycleStatus::Provisioning,
                ]);

                return ['server' => $server, 'created' => true];
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->slugConflict($data->slug);
        }
    }

    private function assertPortFree(Node $node, int $port): void
    {
        $instanceIds = Instance::query()->where('node_id', $node->id)->pluck('id')->all();
        $processes = Process::query()
            ->where('runtime', ProcessRuntime::Docker)
            ->where(function ($query) use ($node, $instanceIds): void {
                $query->where(function ($owned) use ($node): void {
                    $owned->where('owner_type', Node::class)->where('owner_id', $node->id);
                })->orWhere(function ($owned) use ($instanceIds): void {
                    $owned->whereIn('owner_type', Instance::morphTypes())->whereIn('owner_id', $instanceIds);
                });
            })
            ->get();

        foreach ($processes as $process) {
            $ports = $process->runtime_config['ports'] ?? [];

            foreach (is_array($ports) ? $ports : [] as $spec) {
                $mapping = is_string($spec) ? DockerPublishedPort::parse($spec) : null;

                if ($mapping instanceof DockerPublishedPort && $mapping->publishedPort === $port) {
                    throw new ResourceOperationException(
                        errorCode: 'database.server_port_in_use',
                        message: "Process [{$process->name}] on Node [{$node->name}] already publishes port {$port}.",
                        status: 409,
                    );
                }
            }
        }
    }

    private function slugConflict(string $slug): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'database.server_slug_conflict',
            message: "Database server [{$slug}] already exists with a different Node, driver, tag, port, or Process.",
            status: 409,
        );
    }
}
