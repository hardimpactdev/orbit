<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Actions\DatabaseServers\RecordDatabaseOperationAction;
use App\Data\DatabaseConnections\CreateServerDatabaseData;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseServers\DatabaseDestroySafety;
use App\Domain\DatabaseServers\DatabaseUserPrivileges;
use App\Domain\DatabaseServers\MysqlNames;
use App\Domain\DatabaseServers\PostgresTestDatabases;
use App\Domain\DatabaseServers\PreparedDatabaseOperation;
use App\Domain\Instances\CloneValkeyKeyspace;
use App\Domain\Instances\OwnedDatabaseSlug;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use App\Models\DatabaseUser;
use App\Models\Instance;
use App\Models\Operation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Creates a managed SQL database and its owner on a Database server and records the connection.
 *
 * With an Instance, the user is the Instance's one user on that server. It also gets the test
 * database `<name>_test`: MySQL grants its prefix; PostgreSQL pre-creates a bounded worker pool. The connection is
 * attached to the Instance under prefix DB. Without an Instance, the database gets its own user.
 *
 * On a Valkey server it creates one ACL user named after the slug instead, and records the `redis`
 * connection that logs in as that user. A Valkey server holds no Instance databases.
 */
final readonly class CreateServerDatabaseAction
{
    public const string ATTACH_PREFIX = 'DB';

    private const int PASSWORD_LENGTH = 40;

    public function __construct(
        private MysqlNames $names,
        private DatabaseUserPrivileges $privileges,
        private PostgresTestDatabases $postgresTests,
        private RecordDatabaseOperationAction $operations,
        private RetryDatabaseCreateAction $retries,
    ) {}

    public function execute(CreateServerDatabaseData $data, ?string $createdBy): Operation
    {
        return DB::transaction(fn (): Operation => $this->operations->start($this->prepare($data, $createdBy)));
    }

    public function prepare(CreateServerDatabaseData $data, ?string $createdBy): PreparedDatabaseOperation
    {
        return DB::transaction(function () use ($data, $createdBy): PreparedDatabaseOperation {
            $server = $this->server($data->server);
            $server = DatabaseServer::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();
            $this->assertActive($server);
            app(DatabaseDestroySafety::class)->assertAcceptsCreates($server);
            app(SwampSshExecutionInput::class)->forNode($server->node);
            if (($retry = $this->retries->prepare($data)) !== null) {
                return $retry;
            }
            if ($data->testProcesses !== null && ($server->driver === DatabaseDriver::Redis || $data->instanceId === null)) {
                throw new ResourceOperationException('validation.failed', 'Test processes require a MySQL or PostgreSQL server and an Instance.', 422);
            }
            if ($data->locale !== null && $server->driver !== DatabaseDriver::Pgsql) {
                throw new ResourceOperationException('validation.failed', 'A locale requires a PostgreSQL server.', 422);
            }
            $instance = $data->instanceId === null ? null : Instance::query()->with('project')->findOrFail($data->instanceId);
            if ($instance !== null) {
                $this->assertHoldsDatabases($server);
            }
            $redis = $server->driver === DatabaseDriver::Redis;
            $database = $redis ? ($data->database === null ? null : (string) $data->database)
                : ($server->driver === DatabaseDriver::Pgsql ? $this->postgresName($data->slug) : $this->names->database($data->slug));
            // Both SQL servers get the parallel test pool `<test>_test_1..N` beside an Instance's database.
            $processes = ! $redis && $instance !== null ? ($data->testProcesses ?? PostgresTestDatabases::DEFAULT_PROCESSES) : null;
            if ($processes !== null && $server->driver === DatabaseDriver::Pgsql) {
                $database = $this->postgresTests->database($database ?? '');
            }
            $test = $instance === null ? null : $this->names->testDatabase($database ?? '', pool: $processes !== null);
            if (! $redis) {
                app(DatabaseDestroySafety::class)->assertNames([$database ?? ''], $test === null ? [] : [$test]);
            }
            $connection = DatabaseConnection::query()->where('slug', $data->slug)->first();
            $repair = $connection !== null;
            if ($connection !== null) {
                if ($server->driver !== DatabaseDriver::Pgsql || $instance === null
                    || $connection->database_server_id !== $server->id || $connection->owner_instance_id !== $instance->id
                    || $connection->database !== $database || $connection->test_database !== $test
                    || $connection->test_processes !== $processes || $connection->locale !== $data->locale) {
                    throw $this->slugConflict($data->slug);
                }
            } else {
                $credentials = $redis ? ['username' => $data->slug, 'password' => Str::random(self::PASSWORD_LENGTH), 'new' => true]
                    : $this->credentials($server, $database ?? '', $instance);
                // Recorded intent conflicts are Gateway validation; live inventory belongs to Swamp.
                $this->assertRecordedNamesFree($server, $database ?? '', $test, $credentials, $processes);
                try {
                    $connection = DatabaseConnection::query()->create([
                        'slug' => $data->slug, 'driver' => $server->driver->value, 'node_id' => $server->node_id,
                        'database_server_id' => $server->id, 'owner_instance_id' => $instance?->id,
                        'host' => $server->node->wireguard_ip, 'port' => $server->port, 'database' => $database,
                        'locale' => $data->locale, 'test_processes' => $processes, 'test_database' => $test,
                        'path' => null, 'username' => $credentials['username'], 'password' => $credentials['password'],
                    ]);
                } catch (UniqueConstraintViolationException) {
                    throw $this->slugConflict($data->slug);
                }
                DatabaseUser::query()->create([
                    'database_connection_id' => $connection->id, 'username' => $credentials['username'],
                    'privileges' => $redis ? $this->valkeyPrivileges($data) : $this->privileges->text($server->driver, $database, prefix: $test),
                    'created_by' => $createdBy,
                ]);
            }

            return $this->retries->record($this->record($server, $connection, $repair, $instance, $redis ? $data : null), $data);
        });
    }

    /**
     * The owner, database and test databases of an Instance that `instance:create --database-server` makes, recorded
     * exactly as `database:create --instance` records them, but for a run step of the create instead of an Operation.
     * An identical create retry records the same names again; the statements converge.
     */
    public function prepareForInstance(string $serverSlug, Instance $instance): PreparedDatabaseOperation
    {
        $slug = OwnedDatabaseSlug::for($instance);
        $connection = DatabaseConnection::query()->where('slug', $slug)->first();
        if ($connection === null) {
            return $this->prepare(new CreateServerDatabaseData($slug, $serverSlug, $instance->id), null);
        }
        $server = $this->server($serverSlug);
        if ($connection->owner_instance_id !== $instance->id || $connection->database_server_id !== $server->id) {
            throw $this->slugConflict($slug);
        }
        $this->assertActive($server);

        return $this->record($server, $connection, true, $instance, null);
    }

    /**
     * A clone's own Valkey user on the server of its default's Valkey connection, recorded as `database:create --server` records
     * a Valkey user but owned by the clone, and created by the clone run's `operation-database-create` child. It keeps the
     * default user's commands and index; its keys are only the clone's own (CloneValkeyKeyspace). A retry records the same user
     * again; `ACL SETUSER` converges.
     */
    public function prepareValkeyForInstance(Instance $instance, DatabaseConnection $source): PreparedDatabaseOperation
    {
        return DB::transaction(function () use ($instance, $source): PreparedDatabaseOperation {
            $server = $source->server;
            $sourceUser = $source->users()->where('username', $source->username ?? '')->first();
            if (! $server instanceof DatabaseServer || $server->driver !== DatabaseDriver::Redis || ! $sourceUser instanceof DatabaseUser) {
                throw new ResourceOperationException('instance.database_clone_unsupported',
                    "The default Instance's Valkey connection [{$source->slug}] has no Orbit-made user on a Valkey server.", 422);
            }
            $server = DatabaseServer::query()->with(['node', 'process'])->whereKey($server->id)->lockForUpdate()->firstOrFail();
            $this->assertActive($server);
            app(DatabaseDestroySafety::class)->assertAcceptsCreates($server);
            app(SwampSshExecutionInput::class)->forNode($server->node);
            $slug = OwnedDatabaseSlug::for($instance, 'valkey');
            $data = new CreateServerDatabaseData($slug, $server->slug, null, CloneValkeyKeyspace::patterns($instance),
                CloneValkeyKeyspace::allow($sourceUser->privileges), $source->database === null ? null : (int) $source->database);
            $connection = DatabaseConnection::query()->where('slug', $slug)->first();
            if ($connection !== null) {
                if ($connection->owner_instance_id !== $instance->id || $connection->database_server_id !== $server->id) {
                    throw $this->slugConflict($slug);
                }

                return $this->record($server, $connection, true, $instance, $data);
            }
            $credentials = ['username' => $slug, 'password' => Str::random(self::PASSWORD_LENGTH), 'new' => true];
            $this->assertRecordedNamesFree($server, '', null, $credentials);
            try {
                $connection = DatabaseConnection::query()->create([
                    'slug' => $slug, 'driver' => $server->driver->value, 'node_id' => $server->node_id,
                    'database_server_id' => $server->id, 'owner_instance_id' => $instance->id,
                    'host' => $server->node->wireguard_ip, 'port' => $server->port, 'database' => $source->database,
                    'username' => $credentials['username'], 'password' => $credentials['password'],
                ]);
            } catch (UniqueConstraintViolationException) {
                throw $this->slugConflict($slug);
            }
            DatabaseUser::query()->create(['database_connection_id' => $connection->id, 'username' => $slug,
                'privileges' => $this->valkeyPrivileges($data), 'created_by' => null]);

            return $this->record($server, $connection, false, $instance, $data);
        });
    }

    /** The rules Valkey reports for a user that `database.create` makes; Doctor compares them by token. */
    private function valkeyPrivileges(CreateServerDatabaseData $data): string
    {
        return implode(' ', ['on', ...array_map(static fn (string $pattern): string => '~'.$pattern, $data->patterns),
            'resetchannels', '-@all', ...array_map(static fn (string $rule): string => '+'.$rule, $data->allow), '-@admin', '-@dangerous', '-function', '-script']);
    }

    /** The database.create intent for a recorded connection; `$redis` carries the Valkey index and ACL rules. */
    private function record(DatabaseServer $server, DatabaseConnection $connection, bool $repair, ?Instance $instance, ?CreateServerDatabaseData $redis): PreparedDatabaseOperation
    {
        $test = $connection->test_database;
        $processes = $connection->test_processes;
        $safe = ['repair' => $repair, 'driver' => $server->driver->value, 'username' => $connection->username,
            'databases' => $redis !== null ? [] : [$connection->database, ...($test === null ? [] : ($processes === null ? [$test] : $this->postgresTests->names($test, $processes)))],
            'test_prefix' => $server->driver === DatabaseDriver::Mysql ? $test : null, 'locale' => $connection->locale,
            'host' => $server->node->wireguard_ip, 'port' => $server->port,
            'container' => app(DockerProcessRenderer::class)->containerName($server->process ?? throw new ResourceOperationException('database.server_command_failed', 'The server has no Process.', 502)),
            'index' => $redis?->database, 'patterns' => $redis->patterns ?? [], 'allow' => $redis->allow ?? []];

        return $this->operations->prepare('database.create', 'database_server', $server->id, $server->node_id,
            ['connection_id' => $connection->id, 'server_id' => $server->id, 'driver' => $server->driver->value],
            ['database-apply' => ['name' => 'database.create', 'input' => [...$safe,
                'payload' => ['password' => $connection->password, 'salt' => base64_encode(random_bytes(16))]], 'protected' => ['payload'], 'redactions' => [$connection->password]]],
            ['created_connection' => $connection->id, 'attach_instance' => $instance?->id]);
    }

    private function server(string $slug): DatabaseServer
    {
        $server = DatabaseServer::query()->with(['node', 'process'])->where('slug', $slug)->first();

        if (! $server instanceof DatabaseServer) {
            throw new ResourceOperationException(
                errorCode: 'database.server_missing',
                message: "Database server [{$slug}] does not exist.",
                status: 404,
            );
        }

        return $server;
    }

    /** A Valkey server holds no databases of its own: each of its users is one `redis` connection. */
    private function assertHoldsDatabases(DatabaseServer $server): void
    {
        if ($server->driver === DatabaseDriver::Redis) {
            throw new ResourceOperationException(
                errorCode: 'database.driver_unsupported',
                message: "Database server [{$server->slug}] is a Valkey server, which holds no Instance databases.",
                status: 422,
            );
        }
    }

    private function assertActive(DatabaseServer $server): void
    {
        if ($server->status !== LifecycleStatus::Active) {
            throw new ResourceOperationException(
                errorCode: 'database.server_inactive',
                message: "Database server [{$server->slug}] is not active. Run database:server:create again to finish it.",
                status: 409,
            );
        }
    }

    /**
     * An Instance has one user per server. A second database for the same Instance reuses that
     * user and its password, so every connection of the Instance keeps working.
     *
     * @return array{username: string, password: string, new: bool}
     */
    private function credentials(DatabaseServer $server, string $database, ?Instance $instance): array
    {
        if ($instance instanceof Instance) {
            $owned = DatabaseConnection::query()
                ->where('database_server_id', $server->id)
                ->where('owner_instance_id', $instance->id)
                ->orderBy('id')
                ->first();

            if (
                $owned instanceof DatabaseConnection
                && is_string($owned->username)
                && is_string($owned->password)
            ) {
                return ['username' => $owned->username, 'password' => $owned->password, 'new' => false];
            }

            return [
                'username' => $this->names->instanceUser($instance->project->slug, $instance->name),
                'password' => Str::random(self::PASSWORD_LENGTH),
                'new' => true,
            ];
        }

        return [
            'username' => $this->names->user($database),
            'password' => Str::random(self::PASSWORD_LENGTH),
            'new' => true,
        ];
    }

    /** @param array{username:string,password:string,new:bool} $credentials */
    private function assertRecordedNamesFree(DatabaseServer $server, string $database, ?string $testDatabase, #[SensitiveParameter] array $credentials, ?int $testProcesses = null, ?int $retryConnectionId = null): void
    {
        $connections = DatabaseConnection::query()->where('database_server_id', $server->id)->get();
        $planned = [$database, ...($testDatabase !== null && $testProcesses !== null ? $this->postgresTests->names($testDatabase, $testProcesses) : [])];

        foreach ($connections as $connection) {
            if ($connection->id === $retryConnectionId) {
                continue;
            }
            if ($server->driver === DatabaseDriver::Pgsql) {
                $recorded = [$connection->database, ...($connection->test_database !== null && $connection->test_processes !== null ? $this->postgresTests->names($connection->test_database, $connection->test_processes) : [])];
                if (array_intersect($planned, $recorded) !== []) {
                    throw $this->nameConflict($server, $database);
                }

                continue;
            }

            $taken = $connection->database === $database
                || (is_string($connection->test_database) && str_starts_with($database, $connection->test_database))
                || ($testDatabase !== null && is_string($connection->database) && str_starts_with($connection->database, $testDatabase));

            if ($taken) {
                throw $this->nameConflict($server, $database);
            }
        }

        if ($credentials['new'] && (DatabaseConnection::query()->where('database_server_id', $server->id)->where('username', $credentials['username'])->exists()
            || DatabaseUser::query()->where('username', $credentials['username'])->whereIn('database_connection_id', $connections->pluck('id'))->exists())) {
            throw $this->nameConflict($server, $credentials['username']);
        }
    }

    private function slugConflict(string $slug): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'database.slug_conflict',
            message: "Database connection [{$slug}] already exists.",
            status: 409,
        );
    }

    private function nameConflict(DatabaseServer $server, string $name): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'database.name_conflict',
            message: "Database server [{$server->slug}] already has a database or user named [{$name}].",
            status: 409,
        );
    }

    private function postgresName(string $slug): string
    {
        $name = $this->names->database($slug);

        return strlen($name) <= 63 ? $name : substr($name, 0, 54).'_'.substr(sha1($name), 0, 8);
    }
}
