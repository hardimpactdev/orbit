<?php

declare(strict_types=1);

namespace App\Actions\DatabaseServers;

use App\Actions\Processes\CreateProcessAction;
use App\Data\DatabaseServers\CreateDatabaseServerData;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseConnections\DockerPublishedPort;
use App\Domain\DatabaseServers\DatabaseServerAdmin;
use App\Domain\DatabaseServers\DatabaseServerFirewallReconciler;
use App\Domain\DatabaseServers\DatabaseServerLanPolicy;
use App\Domain\DatabaseServers\DatabaseServerProcess;
use App\Domain\DatabaseServers\ValkeyProcessAdoption;
use App\Domain\DatabaseServers\ValkeyServerAdmin;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessRuntimeLease;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\ProcessSpecification;
use App\Domain\Processes\ProcessTarget;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Creates a Database server. A MySQL server takes four steps: the record with a generated root password, the Docker
 * Node Process started with MYSQL_ROOT_PASSWORD, the wait for the root login, and the removal of the variable from the
 * Process. A Valkey server takes the record with a generated admin password, the ACL file in its volume, the Process,
 * and the wait for the admin login. An adoption of a Valkey Process takes the connection's password, replaces the
 * Process specification instead of creating one, and links the connection at the end. Each step leaves state that a
 * retry of the same request reads, so a retry continues from the first step that did not finish. A retry for an
 * active Valkey server repairs it to its records ({@see ConvergeValkeyServerAction}).
 */
final readonly class CreateDatabaseServerAction
{
    public const int READY_TIMEOUT_SECONDS = 120;

    private const int ROOT_PASSWORD_LENGTH = 48;

    public function __construct(
        private ProcessTargetResolver $targets,
        private CreateProcessAction $processes,
        private ProcessRuntimeLease $lease,
        private ProcessRuntimeManager $runtime,
        private ProcessSpecification $specifications,
        private DatabaseServerAdmin $admin,
        private DatabaseServerFirewallReconciler $firewall,
        private DatabaseServerLanPolicy $lan,
        private ValkeyServerAdmin $valkey,
        private ValkeyProcessAdoption $adoption,
        private WriteValkeyAclFileAction $aclFile,
        private ConvergeValkeyServerAction $converge,
    ) {}

    /** @return array{server: DatabaseServer, created: bool} */
    public function execute(CreateDatabaseServerData $data): array
    {
        $lock = Cache::lock('database-server:provision:'.$data->slug, 900);
        if (! $lock->get()) {
            throw new ResourceOperationException('database.server_in_use', 'Another server creation is in progress.', 409);
        }
        try {
            return $this->provisionReserved($data);
        } finally {
            $lock->release();
        }
    }

    /** @return array{server: DatabaseServer, created: bool} */
    private function provisionReserved(CreateDatabaseServerData $data): array
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

        $this->lan->validate($node, $data->lanNodeIds ?? []);
        if ($data->driver === DatabaseDriver::Pgsql && preg_match(DatabaseServerProcess::POSTGRES_TAG_PATTERN, $data->tag) !== 1) {
            throw new ResourceOperationException('validation.failed', 'Managed PostgreSQL requires a major 18 image tag.', 422);
        }
        $adoption = null;

        if ($data->processId !== null) {
            if ($data->driver !== DatabaseDriver::Redis) {
                throw ValkeyProcessAdoption::refused('Only a redis server can adopt a Process.');
            }

            // The checks run once, before the record exists. A retry continues from the recorded steps.
            if (! DatabaseServer::query()->where('slug', $data->slug)->exists()) {
                $adoption = $this->adoption->check($node, $data->slug, $data->processId, (string) $data->connection);
            }
        }

        $reserved = $this->reserve($data, $node, $adoption);
        $server = $reserved['server'];
        // Only an explicit create/retry enables filtering for an existing server.
        $server->update(['private_endpoints_managed' => true]);

        if ($data->lanNodeIds !== null) {
            $this->step($server, 'firewall', fn () => $this->firewall->replacePeers($server, $data->lanNodeIds));
        }
        $this->step($server, 'firewall', fn () => $this->firewall->reconcile($node));

        if ($server->status !== LifecycleStatus::Active) {
            if ($server->driver === DatabaseDriver::Redis) {
                $connection = $data->connection === null ? null : $this->adoption->connection($server, $data->connection);
                $this->provisionValkey($server, $node, $target, $connection);
            } else {
                $this->provision($server, $node);
            }
        } elseif ($server->driver === DatabaseDriver::Redis) {
            $this->converge->execute($server);
        } else {
            $this->provision($server, $node);
        }
        $this->reconcilePublications($server, $node);
        $server->load('process');
        $this->step($server, 'firewall', fn () => $this->firewall->reconcile($node));

        $server->update(['failed_step' => null, 'error_code' => null]);

        return ['server' => $server->refresh(), 'created' => $reserved['created']];
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
                    ValkeyProcessAdoption::assertUnowned($adoption['process']);

                    $server = DatabaseServer::query()->create([
                        'slug' => $data->slug,
                        'driver' => DatabaseDriver::Redis,
                        'node_id' => $node->id,
                        'process_id' => $adoption['process']->id,
                        'tag' => $data->tag,
                        'port' => $adoption['port'],
                        'lan_node_ids' => $data->lanNodeIds ?? [],
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
                    'lan_node_ids' => $data->lanNodeIds ?? [],
                    'root_password' => Str::random(self::ROOT_PASSWORD_LENGTH),
                    'status' => LifecycleStatus::Provisioning,
                ]);

                return ['server' => $server, 'created' => true];
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->slugConflict($data->slug);
        }
    }

    private function provision(DatabaseServer $server, Node $node): void
    {
        $server->update([
            'status' => $server->status === LifecycleStatus::Active ? LifecycleStatus::Active : LifecycleStatus::Provisioning,
            'failed_step' => null,
            'error_code' => null,
        ]);

        $process = $server->process;

        if (! $process instanceof Process || DatabaseServerProcess::holdsRootPassword($process)) {
            $process = $this->step($server, 'process', fn (): Process => $this->processes->execute(
                DatabaseServerProcess::data($server, $node, $server->root_password),
            )['process']);
            $server->update(['process_id' => $process->id]);
            $server->setRelation('process', $process);

            $this->step($server, 'readiness', fn () => $this->waitUntilReady($server));
            $this->step($server, 'root_password_removal', fn () => $this->forgetRootPassword($process));
        } elseif ($process->status !== LifecycleStatus::Active) {
            $this->step($server, 'root_password_removal', fn () => $this->forgetRootPassword($process));
        }

        $this->step($server, 'readiness', fn () => $this->waitUntilReady($server));

        $server->update([
            'status' => LifecycleStatus::Active,
            'failed_step' => null,
            'error_code' => null,
        ]);
    }

    /**
     * The steps of a Valkey server: `acl_file`, `process`, and `readiness`. They run until the Process runs the server
     * specification; a retry after a failed `process` step skips the ACL file that the attempt already wrote. An
     * adoption links its connection last.
     */
    private function provisionValkey(DatabaseServer $server, Node $node, ProcessTarget $target, ?DatabaseConnection $connection): void
    {
        $aclFileWritten = $server->failed_step === 'process';

        $server->update([
            'status' => LifecycleStatus::Provisioning,
            'failed_step' => null,
            'error_code' => null,
        ]);

        // An adopted Process keeps its name and volume; a created one is named after the slug.
        $process = $server->process;
        $name = $process instanceof Process ? $process->name : $server->slug;
        $volume = ($process instanceof Process ? DatabaseServerProcess::valkeyVolume($process) : null)
            ?? DatabaseServerProcess::volume($server->slug);
        $data = DatabaseServerProcess::valkeyData($server, $node, $name, $volume);
        $attributes = $this->specifications->attributes($data, $target);

        if (
            ! $process instanceof Process
            || $process->status !== LifecycleStatus::Active
            || $process->desired_state !== DesiredProcessState::Running
            || ! $this->specifications->matches($process, $attributes)
        ) {
            if (! $aclFileWritten) {
                $this->step($server, 'acl_file', fn () => $this->aclFile->execute($server, $volume));
            }

            $process = $this->step($server, 'process', fn (): Process => $process instanceof Process
                ? $this->replaceSpecification($process, $attributes)
                : $this->processes->execute($data)['process']);
            $server->update(['process_id' => $process->id]);
            $server->setRelation('process', $process);
        }

        $this->step($server, 'readiness', fn () => $this->waitUntilReady($server));

        DB::transaction(function () use ($server, $connection): void {
            $connection?->update(['database_server_id' => $server->id]);
            $server->update([
                'status' => LifecycleStatus::Active,
                'failed_step' => null,
                'error_code' => null,
            ]);
        });
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function step(DatabaseServer $server, string $step, callable $operation): mixed
    {
        try {
            return $operation();
        } catch (ResourceOperationException|ProcessOperationException $exception) {
            $server->update([
                'status' => $server->status === LifecycleStatus::Active ? LifecycleStatus::Active : LifecycleStatus::Failed,
                'failed_step' => $step,
                'error_code' => $exception->errorCode,
            ]);

            throw $exception;
        }
    }

    private function waitUntilReady(DatabaseServer $server): void
    {
        if ($server->driver === DatabaseDriver::Redis) {
            if ($this->valkey->waitUntilReady($server, self::READY_TIMEOUT_SECONDS)) {
                return;
            }

            throw self::startFailed($server);
        }

        if ($this->admin->waitUntilReady($server, self::READY_TIMEOUT_SECONDS)) {
            return;
        }

        throw new ResourceOperationException(
            errorCode: 'database.server_start_failed',
            message: "Database server [{$server->slug}] did not accept the root login within ".self::READY_TIMEOUT_SECONDS.' seconds.',
            status: 502,
        );
    }

    /** A Valkey server whose `default` did not log in with the admin password in time. */
    public static function startFailed(DatabaseServer $server): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'database.server_start_failed',
            message: "Database server [{$server->slug}] did not accept the admin login within ".self::READY_TIMEOUT_SECONDS.' seconds.',
            status: 502,
        );
    }

    /** Remove MYSQL_ROOT_PASSWORD from the Process and recreate its container. The data volume keeps the password. */
    private function forgetRootPassword(#[SensitiveParameter] Process $process): void
    {
        $this->recreate($process, static function (Process $fresh): array {
            $runtimeConfig = $fresh->runtime_config;
            $environment = is_array($runtimeConfig['environment'] ?? null) ? $runtimeConfig['environment'] : [];
            unset($environment[DatabaseServerProcess::ROOT_PASSWORD_VARIABLE], $environment['POSTGRES_PASSWORD']);

            return ['runtime_config' => [...$runtimeConfig, 'environment' => $environment]];
        });
    }

    /**
     * Replace an adopted Process's specification with the Valkey server specification and recreate its container. The
     * Process keeps its ID, name, volume, and port. This is the server's own step, so the `process:update` refusal does
     * not apply.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function replaceSpecification(#[SensitiveParameter] Process $process, array $attributes): Process
    {
        return $this->recreate($process, static fn (): array => [
            ...$attributes,
            'desired_state' => DesiredProcessState::Running,
        ]);
    }

    /**
     * Change the Process record under its lease and converge its container again.
     *
     * @param  callable(Process): array<string, mixed>  $changes
     */
    private function recreate(#[SensitiveParameter] Process $process, callable $changes): Process
    {
        return $this->lease->run($process, function (Process $fresh) use ($changes): Process {
            $fresh->fill([
                ...$changes($fresh),
                'status' => LifecycleStatus::Provisioning,
                'failed_step' => null,
                'error_code' => null,
            ])->save();

            try {
                $this->runtime->converge($fresh);
            } catch (ProcessOperationException $exception) {
                $fresh->update([
                    'status' => LifecycleStatus::Failed,
                    'failed_step' => $exception->step,
                    'error_code' => $exception->errorCode,
                ]);

                throw $exception;
            }

            $fresh->update(['status' => LifecycleStatus::Active]);

            return $fresh;
        });
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

    private function reconcilePublications(DatabaseServer $server, Node $node): void
    {
        $process = $server->process;
        if (! $process instanceof Process) {
            return;
        }
        $containerPort = $server->driver->defaultPort() ?? 3306;
        $ports = DatabaseServerProcess::publishedPorts($node, $server->port, $containerPort);
        if (($process->runtime_config['ports'] ?? []) === $ports) {
            return;
        }
        $this->step($server, 'publication', fn () => $this->recreate($process, static fn (Process $fresh): array => [
            'runtime_config' => [...$fresh->runtime_config, 'ports' => $ports],
        ]));
    }
}
