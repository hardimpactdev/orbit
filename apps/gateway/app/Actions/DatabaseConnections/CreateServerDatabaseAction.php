<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Actions\DatabaseServers\RecordDatabaseOperationAction;
use App\Data\DatabaseConnections\CreateServerDatabaseData;
use App\Data\DatabaseConnections\DatabaseConnectionData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseServers\DatabaseCommands;
use App\Domain\DatabaseServers\DatabaseOwnership;
use App\Domain\DatabaseServers\DatabaseRemovalLock;
use App\Domain\DatabaseServers\DatabaseServerAdmin;
use App\Domain\DatabaseServers\MysqlNames;
use App\Domain\DatabaseServers\MysqlStatements;
use App\Domain\DatabaseServers\PostgresStatements;
use App\Domain\DatabaseServers\PostgresTestDatabases;
use App\Domain\DatabaseServers\PreparedDatabaseOperation;
use App\Domain\DatabaseServers\ValkeyAclLock;
use App\Domain\DatabaseServers\ValkeyServerAdmin;
use App\Domain\Instances\DatabaseClone\DatabaseCloneStep;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use App\Models\DatabaseUser;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use Throwable;

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
        private DatabaseServerAdmin $admin,
        private MysqlNames $names,
        private MysqlStatements $statements,
        private RecordEventBroadcaster $broadcaster,
        private ValkeyServerAdmin $valkey,
        private PostgresTestDatabases $postgresTests,
        private RepairPostgresTestDatabasesAction $repairTests,
        private RecordDatabaseOperationAction $operations,
        private RetryDatabaseCreateAction $retries,
        private DatabaseRemovalLock $lock,
        private DatabaseOwnership $ownership,
    ) {}

    public function execute(CreateServerDatabaseData $data, ?string $createdBy): Operation
    {
        return DB::transaction(fn (): Operation => $this->operations->start($this->prepare($data, $createdBy)));
    }

    public function prepare(CreateServerDatabaseData $data, ?string $createdBy): PreparedDatabaseOperation
    {
        $server = $this->server($data->server);
        $this->assertActive($server);
        if (($retry = $this->retries->prepare($data)) !== null) {
            $connection = DatabaseConnection::query()->findOrFail(ValidatedData::integer($retry->input['connection_id']));
            $this->assertNamesFree($server, $connection->database ?? '', $connection->test_database,
                ['username' => $connection->username ?? '', 'password' => $connection->password ?? '', 'new' => false], $connection->test_processes, $connection->id);

            return $retry;
        }

        if ($data->testProcesses !== null && ($server->driver !== DatabaseDriver::Pgsql || $data->instanceId === null)) {
            throw new ResourceOperationException('validation.failed', 'Test processes require a PostgreSQL server and an Instance.', 422);
        }

        if ($server->driver === DatabaseDriver::Redis && $data->instanceId === null) {
            return ValkeyAclLock::run($server, fn (): PreparedDatabaseOperation => $this->createAclUser($server->refresh(), $data, $createdBy));
        }

        $instance = $data->instanceId === null
            ? null
            : Instance::query()->with('project')->findOrFail($data->instanceId);

        return $this->retries->record($this->prepareOnServer(
            server: $server,
            slug: $data->slug,
            database: $server->driver === DatabaseDriver::Pgsql ? $this->postgresName($data->slug) : $this->names->database($data->slug),
            instance: $instance,
            createdBy: $createdBy,
            locale: $data->locale,
            testProcesses: $data->testProcesses,
        ), $data);
    }

    public function prepareOnServer(DatabaseServer $server, string $slug, string $database, ?Instance $instance, ?string $createdBy, ?string $locale = null, ?int $testProcesses = null): PreparedDatabaseOperation
    {
        return $this->lock->run([$server->id], fn (): PreparedDatabaseOperation => $this->prepareOnServerLocked($server, $slug, $database, $instance, $createdBy, $locale, $testProcesses));
    }

    private function prepareOnServerLocked(
        DatabaseServer $server,
        string $slug,
        string $database,
        ?Instance $instance,
        ?string $createdBy,
        ?string $locale = null,
        ?int $testProcesses = null,
    ): PreparedDatabaseOperation {
        $this->assertActive($server);
        $this->assertHoldsDatabases($server);
        if ($server->driver === DatabaseDriver::Pgsql && $instance !== null) {
            $database = $this->postgresTests->database($database);
            $testProcesses ??= PostgresTestDatabases::DEFAULT_PROCESSES;
        } elseif ($testProcesses !== null) {
            throw new ResourceOperationException('validation.failed', 'Test processes require a PostgreSQL server and an Instance.', 422);
        }

        $existing = DatabaseConnection::query()->where('slug', $slug)->first();
        if ($existing !== null) {
            if ($server->driver === DatabaseDriver::Pgsql
                && $existing->driver === DatabaseDriver::Pgsql
                && $instance !== null
                && $existing->database_server_id === $server->id
                && $existing->owner_instance_id === $instance->id
                && $existing->database === $database
                && $existing->test_database === $this->names->testDatabase($database)
                && $existing->test_processes === $testProcesses
                && $existing->locale === $locale) {
                return $this->repairTests->prepareLocked($server, $existing);
            }

            throw $this->slugConflict($slug);
        }

        $testDatabase = $instance instanceof Instance ? $this->names->testDatabase($database) : null;
        $credentials = $this->credentials($server, $database, $instance);

        if ($locale !== null && $server->driver !== DatabaseDriver::Pgsql) {
            throw new ResourceOperationException('validation.failed', 'A locale requires a PostgreSQL server.', 422);
        }
        $statements = $this->statements->forDriver($server->driver);
        $createDatabase = DatabaseCommands::createSqlDatabase($server, $database, $credentials['username'], $locale);

        $this->assertNamesFree($server, $database, $testDatabase, $credentials, $testProcesses);

        $guard = '';
        if ($statements instanceof PostgresStatements) {
            foreach ([$database, ...($testDatabase !== null && $testProcesses !== null ? $this->postgresTests->names($testDatabase, $testProcesses) : [])] as $name) {
                $guard .= $statements->assertDatabaseOwner($name, $credentials['username']);
            }
        }
        $sql = $guard.$statements->ensureUser($credentials['username'], $credentials['password'])
            .$createDatabase
            .$statements->grantAll($database, $credentials['username']);

        if ($testDatabase !== null) {
            if ($statements instanceof PostgresStatements && $testProcesses !== null) {
                foreach ($this->postgresTests->names($testDatabase, $testProcesses) as $name) {
                    $sql .= DatabaseCommands::createSqlDatabase($server, $name, $credentials['username'], $locale).$statements->grantAll($name, $credentials['username']);
                }
            } else {
                $sql .= DatabaseCommands::createSqlDatabase($server, $testDatabase, $credentials['username'], null)
                    .$this->statements->grantAllWithPrefix($testDatabase, $credentials['username']);
            }
        }

        try {
            $connection = DB::transaction(function () use (
                $slug,
                $server,
                $instance,
                $database,
                $testDatabase,
                $credentials,
                $createdBy,
                $locale,
                $testProcesses,
            ): DatabaseConnection {
                $connection = DatabaseConnection::query()->create([
                    'slug' => $slug,
                    'driver' => $server->driver->value,
                    'node_id' => $server->node_id,
                    'database_server_id' => $server->id,
                    'owner_instance_id' => $instance?->id,
                    'host' => $server->node->wireguard_ip,
                    'port' => $server->port,
                    'database' => $database,
                    'locale' => $locale,
                    'test_processes' => $testProcesses,
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

        $plan = $server->driver === DatabaseDriver::Mysql
            ? DatabaseCommands::mysqlCreate($server, [$database, ...($testDatabase === null ? [] : [$testDatabase])], $credentials['username'], $credentials['password'], testPrefix: $testDatabase)
            : ['commands' => ['preflight' => [...DatabaseCommands::sql($server, (new PostgresStatements)->databasesNotOwnedBy(
                [$database, ...($testDatabase !== null && $testProcesses !== null ? $this->postgresTests->names($testDatabase, $testProcesses) : [])], $credentials['username']), expectEmpty: true), 'read_only' => true],
                'database-apply' => DatabaseCommands::sql($server, $sql, redactions: [$credentials['password']])], 'creates' => []];

        return $this->operations->prepare('database.create', 'database_server', $server->id, $server->node_id,
            ['connection_id' => $connection->id, 'driver' => $server->driver->value], $plan['commands'],
            ['created_connection' => $connection->id, 'attach_instance' => $instance?->id, 'database_creates' => $plan['creates']]);
    }

    /**
     * Record an Instance's database before it exists on the server, with the names and the
     * credentials it will get. A retry reads them back, so the copy keeps one user and password.
     *
     * @param  array<string, mixed>  $attributes
     */
    /** @param array<string,mixed> $attributes */
    public function reserve(DatabaseServer $server, string $slug, string $database, Instance $instance, ?string $createdBy, array $attributes = []): DatabaseConnection
    {
        return $this->lock->run([$server->id], fn (): DatabaseConnection => $this->reserveLocked($server, $slug, $database, $instance, $createdBy, $attributes));
    }

    /** @param array<string,mixed> $attributes */
    private function reserveLocked(
        DatabaseServer $server,
        string $slug,
        string $database,
        Instance $instance,
        ?string $createdBy,
        array $attributes = [],
    ): DatabaseConnection {
        $this->assertActive($server);
        $this->assertHoldsDatabases($server);
        if ($server->driver === DatabaseDriver::Pgsql) {
            throw new ResourceOperationException('database.driver_unsupported', 'Instance-owned databases require MySQL.', 422);
        }

        $existing = DatabaseConnection::query()->where('slug', $slug)->first();
        if ($existing !== null) {
            if ($existing->owner_instance_id === $instance->id && $existing->database_server_id === $server->id
                && $existing->database === $database && $existing->clone_step !== null && $existing->clone_step !== DatabaseCloneStep::Complete) {
                $this->assertNamesFree($server, $database, $existing->test_database,
                    ['username' => $existing->username ?? '', 'password' => $existing->password ?? '', 'new' => false], null,
                    $existing->id, $this->ownership->mysqlNames($existing));

                return $existing;
            }
            throw $this->slugConflict($slug);
        }

        $testDatabase = $this->names->testDatabase($database);
        $credentials = $this->credentials($server, $database, $instance);

        $this->assertNamesFree($server, $database, $testDatabase, $credentials);

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
     * Make the recorded user, an empty database, and an empty test database on the server. Any
     * database already there is dropped first, so a retry never trusts a partial copy.
     */
    public function prepareEmpty(DatabaseConnection $connection): PreparedDatabaseOperation
    {
        $server = $connection->server;
        if (! $server instanceof DatabaseServer || $connection->database === null || $connection->username === null || $connection->password === null) {
            throw new ResourceOperationException('database.server_required', 'The connection needs a Database server.', 422);
        }
        if ($server->driver !== DatabaseDriver::Mysql) {
            throw new ResourceOperationException('database.driver_unsupported', 'Clone reset requires a managed MySQL database.', 422);
        }
        $plan = DatabaseCommands::mysqlCreate($server, [$connection->database, ...($connection->test_database === null ? [] : [$connection->test_database])],
            $connection->username, $connection->password, $this->ownership->mysqlNames($connection), $connection->test_database);

        return $this->operations->prepare('database.create', 'database_server', $server->id, $server->node_id,
            ['connection_id' => $connection->id, 'driver' => $server->driver->value], $plan['commands'], ['database_creates' => $plan['creates']]);
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

    /**
     * Create the ACL user `<slug>` on a Valkey server and record the `redis` connection that logs in
     * as it, with the rules that Valkey reports. Orbit adds the reset, the password hash, no
     * channels, and the final deny of `@admin` and `@dangerous`. A create that fails removes the
     * user and records nothing.
     */
    private function createAclUser(DatabaseServer $server, CreateServerDatabaseData $data, ?string $createdBy): PreparedDatabaseOperation
    {
        $this->assertActive($server);
        if ($data->database !== null && $data->database >= $this->valkey->databaseCount($server)) {
            throw ValidationException::withMessages(['database' => ['The database index is outside the range configured on this server.']]);
        }
        $slug = $data->slug;

        if (DatabaseConnection::query()->where('slug', $slug)->exists()) {
            throw $this->slugConflict($slug);
        }

        $recorded = DatabaseConnection::query()
            ->where('database_server_id', $server->id)
            ->where('username', $slug)
            ->exists();

        if ($recorded || $this->valkey->userExists($server, $slug)) {
            throw $this->nameConflict($server, $slug);
        }

        $password = Str::random(self::PASSWORD_LENGTH);

        $rules = implode(' ', ['on', ...array_map(static fn (string $pattern): string => '~'.$pattern, $data->patterns),
            'resetchannels', '-@all', ...array_map(static fn (string $rule): string => '+'.$rule, $data->allow),
            '-@admin', '-@dangerous', '-function', '-script']);

        try {
            $connection = DB::transaction(function () use ($slug, $server, $data, $password, $rules, $createdBy): DatabaseConnection {
                $connection = DatabaseConnection::query()->create([
                    'slug' => $slug,
                    'driver' => DatabaseDriver::Redis->value,
                    'node_id' => $server->node_id,
                    'database_server_id' => $server->id,
                    'owner_instance_id' => null,
                    'host' => $server->node->wireguard_ip,
                    'port' => $server->port,
                    'database' => $data->database === null ? null : (string) $data->database,
                    'test_database' => null,
                    'path' => null,
                    'username' => $slug,
                    'password' => $password,
                ]);

                DatabaseUser::query()->create([
                    'database_connection_id' => $connection->id,
                    'username' => $slug,
                    'privileges' => $rules,
                    'created_by' => $createdBy,
                ]);

                return $connection;
            });
        } catch (Throwable $exception) {
            throw $exception instanceof UniqueConstraintViolationException ? $this->slugConflict($slug) : $exception;
        }

        return $this->operations->prepare('database.create', 'database_server', $server->id, $server->node_id,
            ['connection_id' => $connection->id, 'driver' => 'redis'],
            ['database-apply' => DatabaseCommands::valkey($server, 'create', ['username' => $slug,
                'hash' => hash('sha256', $password), 'patterns' => $data->patterns, 'allow' => $data->allow])],
            ['created_connection' => $connection->id]);
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

    /** @param array{username: string, password: string, new: bool} $credentials
     * @param  list<string>  $allowedDatabases
     */
    private function assertNamesFree(
        DatabaseServer $server,
        string $database,
        ?string $testDatabase,
        #[SensitiveParameter]
        array $credentials,
        ?int $testProcesses = null,
        ?int $retryConnectionId = null,
        array $allowedDatabases = [],
    ): void {
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

        $statements = $this->statements->forDriver($server->driver);
        $query = $statements instanceof PostgresStatements
            ? ($retryConnectionId === null ? $statements->databasesIn($planned) : $statements->databasesNotOwnedBy($planned, $credentials['username']))
            : $statements->databasesNamed($database, $testDatabase);
        if (array_diff($this->admin->execute($server, $query), $allowedDatabases) !== []) {
            throw $this->nameConflict($server, $database);
        }

        if (! $credentials['new']) {
            return;
        }

        $username = $credentials['username'];
        $recorded = DatabaseConnection::query()
            ->where('database_server_id', $server->id)
            ->where('username', $username)
            ->exists()
            || DatabaseUser::query()
                ->where('username', $username)
                ->whereIn('database_connection_id', $connections->pluck('id'))
                ->exists();

        if ($recorded || $this->admin->execute($server, $this->statements->forDriver($server->driver)->userNamed($username)) !== []) {
            throw $this->nameConflict($server, $username);
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
