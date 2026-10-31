<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Actions\DatabaseServers\RecordDatabaseOperationAction;
use App\Data\DatabaseConnections\CreateServerDatabaseData;
use App\Data\DatabaseConnections\DatabaseConnectionData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseServers\DatabaseDestroySafety;
use App\Domain\DatabaseServers\MysqlNames;
use App\Domain\DatabaseServers\MysqlStatements;
use App\Domain\DatabaseServers\PostgresTestDatabases;
use App\Domain\DatabaseServers\PreparedDatabaseOperation;
use App\Domain\Instances\DatabaseClone\DatabaseCloneStep;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\DockerProcessRenderer;
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
        private MysqlStatements $statements,
        private RecordEventBroadcaster $broadcaster,
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
            $this->assertActive($server);
            app(DatabaseDestroySafety::class)->assertAcceptsCreates($server);
            if (($retry = $this->retries->prepare($data)) !== null) {
                return $retry;
            }
            if ($data->testProcesses !== null && ($server->driver !== DatabaseDriver::Pgsql || $data->instanceId === null)) {
                throw new ResourceOperationException('validation.failed', 'Test processes require a PostgreSQL server and an Instance.', 422);
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
            $processes = $server->driver === DatabaseDriver::Pgsql && $instance !== null ? ($data->testProcesses ?? PostgresTestDatabases::DEFAULT_PROCESSES) : null;
            if ($processes !== null) {
                $database = $this->postgresTests->database($database ?? '');
            }
            $test = $instance === null ? null : $this->names->testDatabase($database ?? '');
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
                    'privileges' => $redis ? implode(' ', ['on', ...array_map(static fn (string $pattern): string => '~'.$pattern, $data->patterns),
                        'resetchannels', '-@all', ...array_map(static fn (string $rule): string => '+'.$rule, $data->allow), '-@admin', '-@dangerous', '-function', '-script'])
                        : $this->statements->forDriver($server->driver)->privilegeText($database, prefix: $test),
                    'created_by' => $createdBy,
                ]);
            }
            $safe = ['repair' => $repair, 'driver' => $server->driver->value, 'username' => $connection->username,
                'databases' => $redis ? [] : [$database, ...($test === null ? [] : ($processes === null ? [$test] : $this->postgresTests->names($test, $processes)))],
                'test_prefix' => $server->driver === DatabaseDriver::Mysql ? $test : null, 'locale' => $data->locale,
                'host' => $server->node->wireguard_ip, 'port' => $server->port,
                'container' => $redis ? null : app(DockerProcessRenderer::class)->containerName($server->process ?? throw new ResourceOperationException('database.server_command_failed', 'The server has no Process.', 502)),
                'index' => $redis ? $data->database : null, 'patterns' => $redis ? $data->patterns : [], 'allow' => $redis ? $data->allow : []];

            return $this->retries->record($this->operations->prepare('database.create', 'database_server', $server->id, $server->node_id,
                ['connection_id' => $connection->id, 'server_id' => $server->id, 'driver' => $server->driver->value],
                ['database-apply' => ['name' => 'database.create', 'input' => [...$safe, 'password' => $server->root_password,
                    'payload' => ['password' => $connection->password, 'salt' => base64_encode(random_bytes(16))]], 'protected' => ['password', 'payload'], 'redactions' => [$server->root_password, $connection->password]]],
                ['created_connection' => $connection->id, 'attach_instance' => $instance?->id]), $data);
        });
    }

    /**
     * Record an Instance's database before it exists on the server, with the names and the
     * credentials it will get. A retry reads them back, so the copy keeps one user and password.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function reserve(DatabaseServer $server, string $slug, string $database, Instance $instance, ?string $createdBy, array $attributes = []): DatabaseConnection
    {
        return DB::transaction(fn (): DatabaseConnection => $this->reserveRecorded($server, $slug, $database, $instance, $createdBy, $attributes));
    }

    /** @param array<string,mixed> $attributes */
    private function reserveRecorded(
        DatabaseServer $server,
        string $slug,
        string $database,
        Instance $instance,
        ?string $createdBy,
        array $attributes = [],
    ): DatabaseConnection {
        $server = DatabaseServer::query()->with(['node', 'process'])->whereKey($server->id)->lockForUpdate()->firstOrFail();
        $this->assertActive($server);
        app(DatabaseDestroySafety::class)->assertAcceptsCreates($server);
        $this->assertHoldsDatabases($server);
        if ($server->driver === DatabaseDriver::Pgsql) {
            throw new ResourceOperationException('database.driver_unsupported', 'Instance-owned databases require MySQL.', 422);
        }

        $existing = DatabaseConnection::query()->where('slug', $slug)->first();
        if ($existing !== null) {
            if ($existing->owner_instance_id === $instance->id && $existing->database_server_id === $server->id
                && $existing->database === $database && $existing->clone_step !== null && $existing->clone_step !== DatabaseCloneStep::Complete) {
                $this->assertRecordedNamesFree($server, $database, $existing->test_database,
                    ['username' => $existing->username ?? '', 'password' => $existing->password ?? '', 'new' => false], null, $existing->id);

                return $existing;
            }
            throw $this->slugConflict($slug);
        }

        $testDatabase = $this->names->testDatabase($database);
        $credentials = $this->credentials($server, $database, $instance);

        $this->assertRecordedNamesFree($server, $database, $testDatabase, $credentials);

        try {
            $connection = DB::transaction(function () use (
                $slug,
                $server,
                $instance,
                $database,
                $testDatabase,
                $credentials,
                $createdBy,
                $attributes,
            ): DatabaseConnection {
                $connection = DatabaseConnection::query()->create([
                    ...$attributes,
                    'slug' => $slug,
                    'driver' => $server->driver->value,
                    'node_id' => $server->node_id,
                    'database_server_id' => $server->id,
                    'owner_instance_id' => $instance->id,
                    'host' => $server->node->wireguard_ip,
                    'port' => $server->port,
                    'database' => $database,
                    'test_database' => $testDatabase,
                    'path' => null,
                    'username' => $credentials['username'],
                    'password' => $credentials['password'],
                ]);

                DatabaseUser::query()->create([
                    'database_connection_id' => $connection->id,
                    'username' => $credentials['username'],
                    'privileges' => $this->statements->forDriver($server->driver)->privilegeText($database, prefix: $testDatabase),
                    'created_by' => $createdBy,
                ]);

                return $connection;
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->slugConflict($slug);
        }

        $this->broadcaster->broadcast(
            RecordEventType::DatabaseCreated,
            $connection->id,
            DatabaseConnectionData::fromModel($connection)->toArray(),
        );

        return $connection;
    }

    /**
     * The active MySQL Database server with this slug, checked before a caller changes anything. A
     * Valkey server holds no Instance databases.
     */
    public function activeServer(string $slug): DatabaseServer
    {
        $server = $this->server($slug);
        $this->assertActive($server);
        $this->assertHoldsDatabases($server);
        if ($server->driver !== DatabaseDriver::Mysql) {
            throw new ResourceOperationException('database.driver_unsupported', 'Instance-owned databases require MySQL.', 422);
        }

        return $server;
    }

    private function server(string $slug): DatabaseServer
    {
        $server = DatabaseServer::query()->with(['node', 'process'])->where('slug', $slug)->lockForUpdate()->first();

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
