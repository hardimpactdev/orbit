<?php

declare(strict_types=1);

namespace App\Actions\DatabaseServers;

use App\Data\DatabaseServers\CreateDatabaseServerData;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseConnections\DockerPublishedPort;
use App\Domain\DatabaseServers\DatabaseServerProcess;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessSpecification;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
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
        app(SwampSshExecutionInput::class)->forNode($node);
        if ($data->driver === DatabaseDriver::Pgsql && preg_match(DatabaseServerProcess::POSTGRES_TAG_PATTERN, $data->tag) !== 1) {
            throw new ResourceOperationException('validation.failed', 'Managed PostgreSQL requires a major 18 image tag.', 422);
        }

        $reserved = $this->reserve($data, $node);
        $server = $reserved['server'];
        // Only an explicit create/retry opts an existing server into private publication checks.
        $server->update(['private_endpoints_managed' => true]);

        $process = $server->process;
        $redis = $server->driver === DatabaseDriver::Redis;
        $volume = $redis ? (($process === null ? null : DatabaseServerProcess::valkeyVolume($process)) ?? DatabaseServerProcess::volume($server->slug)) : null;
        $processData = $redis
            ? DatabaseServerProcess::valkeyData($server, $node, $process->name ?? $server->slug, (string) $volume)
            : DatabaseServerProcess::data($server, $node, $server->root_password);
        $attributes = $this->specifications->attributes($processData, $target);
        if ($process !== null) {
            $attributes['runtime_config']['image'] = $process->runtime_config['image'];
        }
        $initialize = ! $redis && ($server->status !== LifecycleStatus::Active || $process === null || DatabaseServerProcess::holdsRootPassword($process));
        $initialEnvironment = $initialize ? $attributes['runtime_config']['environment'] : [];
        $attributes['runtime_config']['environment'] = [];
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
        $containerInput = $docker->containerInput($desired, $target);
        unset($containerInput['environment']);
        $initial = clone $desired;
        $initial->runtime_config = [...$desired->runtime_config, 'environment' => $initialEnvironment];

        // The root password and the Valkey ACL file go to the vault at run start; the intent holds no secret.
        return $this->operations->execute('database_server.create', 'database_server', $server->id, $node->id,
            ['server_id' => $server->id, 'driver' => $server->driver->value, 'created' => $reserved['created'],
                'process_id' => $process->id, 'container' => $docker->containerName($process), 'container_input' => $containerInput,
                'spec' => $docker->specHash($initial, $target), 'scrub_spec' => $docker->specHash($desired, $target), 'initialize' => $initialize,
                'password_variable' => $redis ? null : DatabaseServerProcess::passwordVariable($server), 'volume' => $volume],
            [],
            ['server' => ['status' => 'active', 'failed_step' => null, 'error_code' => null],
                'process' => ['runtime_config' => $desired->runtime_config, 'working_directory' => $desired->working_directory, 'restart_policy' => $desired->restart_policy]]);
    }

    /** @return array{server: DatabaseServer, created: bool} */
    private function reserve(CreateDatabaseServerData $data, Node $node): array
    {
        try {
            return DB::transaction(function () use ($data, $node): array {
                $existing = DatabaseServer::query()
                    ->where('slug', $data->slug)
                    ->lockForUpdate()
                    ->first();

                if ($existing instanceof DatabaseServer) {
                    if ($existing->status === LifecycleStatus::Removing) {
                        throw new ResourceOperationException('database.server_removing', 'The server has an unfinished removal intent.', 409);
                    }
                    if ($existing->node_id !== $node->id || $existing->driver !== $data->driver
                        || $existing->tag !== $data->tag || $existing->port !== $data->port) {
                        throw $this->slugConflict($data->slug);
                    }

                    return ['server' => $existing, 'created' => false];
                }

                $this->assertPortFree($node, $data->port);

                $server = DatabaseServer::query()->create([
                    'slug' => $data->slug,
                    'driver' => $data->driver,
                    'node_id' => $node->id,
                    'process_id' => null,
                    'tag' => $data->tag,
                    'port' => $data->port,
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
            message: "Database server [{$slug}] already exists with a different Node, driver, tag or port.",
            status: 409,
        );
    }
}
