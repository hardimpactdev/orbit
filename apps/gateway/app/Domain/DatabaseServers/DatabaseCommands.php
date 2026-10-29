<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Models\DatabaseServer;
use App\Models\Process;
use SensitiveParameter;

/** Renders fixed command intent. Only Swamp invokes it through Kit v2.
 * @phpstan-import-type Command from \App\Models\DatabaseExecutionIntent
 */
final readonly class DatabaseCommands
{
    /** @param list<string> $redactions
     * @return Command
     */
    public static function sql(DatabaseServer $server, #[SensitiveParameter] string $sql, string $backend = 'active', bool $expectEmpty = false, string $refusal = 'database.name_conflict', array $redactions = []): array
    {
        $process = $server->process;
        if (! $process instanceof Process) {
            throw new ResourceOperationException('database.server_command_failed', 'The database server has no Process.', 502);
        }

        return ['name' => 'database.server-admin', 'input' => [
            'operation' => 'execute', 'container' => app(DockerProcessRenderer::class)->containerName($process),
            'password' => $server->root_password,
            'password_variable' => $server->driver === DatabaseDriver::Pgsql ? 'PGPASSWORD' : 'MYSQL_PWD',
            'client' => self::client($server), 'sql' => $sql,
        ], 'protected' => ['password', 'sql'], 'backend' => $backend, 'expect_empty' => $expectEmpty, 'refusal' => $refusal, 'redactions' => [$server->root_password, ...$redactions]];
    }

    /** @return list<string> */
    public static function client(DatabaseServer $server): array
    {
        return $server->driver === DatabaseDriver::Pgsql
            ? ['psql', '--no-psqlrc', '--host=127.0.0.1', '--username=postgres', '--dbname=postgres', '--no-align', '--tuples-only', '--quiet', '--set=ON_ERROR_STOP=1']
            : ['mysql', '--user=root', '--batch', '--skip-column-names'];
    }

    /** @return Command */
    public static function ready(DatabaseServer $server): array
    {
        if ($server->driver === DatabaseDriver::Redis) {
            return self::valkey($server, 'ready');
        }
        $command = self::sql($server, '');
        unset($command['input']['sql']);
        $command['input']['operation'] = 'ready';
        $command['input']['timeout_seconds'] = 120;
        if ($server->driver === DatabaseDriver::Mysql) {
            $command['input']['client'] = ['mysql', '--protocol=TCP', '--host=127.0.0.1', '--user=root', '--batch', '--skip-column-names'];
        }
        $command['protected'] = ['password'];

        return $command;
    }

    /** @param array<string, mixed> $payload
     * @return Command
     */
    public static function valkey(DatabaseServer $server, string $action, #[SensitiveParameter] array $payload = [], string $backend = 'active'): array
    {
        return ['transport' => 'remote_program', 'name' => 'database.valkey', 'input' => [
            'host' => $server->node->wireguard_ip, 'port' => $server->port,
            'password' => $server->root_password, 'default_open' => $server->default_open, 'action' => $action, 'payload' => (object) $payload,
        ], 'protected' => ['password', 'payload'], 'backend' => $backend, 'redactions' => [$server->root_password]];
    }

    /** @param non-empty-list<string> $names
     * @param  list<string>  $owned
     * @return array{commands:array<string,Command>,creates:array<string,string>}
     */
    public static function mysqlCreate(DatabaseServer $server, array $names, string $username, #[SensitiveParameter] string $password, array $owned = [], ?string $testPrefix = null): array
    {
        $statements = new MysqlStatements;
        $commands = [];
        $creates = [];
        $query = $statements->databasesNamed($names[0], $testPrefix);
        if ($owned !== []) {
            $query = rtrim(trim($query), ';');
            $query = 'SELECT SCHEMA_NAME FROM ('.$query.') existing_names WHERE SCHEMA_NAME NOT IN ('.implode(', ', array_map($statements->string(...), $owned)).");\n";
        }
        $commands['preflight'] = [...self::sql($server, $query, expectEmpty: true), 'read_only' => true];
        foreach ($names as $index => $name) {
            $key = 'create-database-'.$index;
            $commands[$key] = [...self::sql($server, (in_array($name, $owned, true) ? $statements->dropDatabase($name) : '').$statements->createDatabase($name)),
                ...(in_array($name, $owned, true) ? ['reset_database' => $name] : [])];
            $creates[$key] = $name;
        }
        $commands['prepare-owner'] = self::sql($server, $statements->ensureUser($username, $password), redactions: [$password]);
        $grant = $statements->grantAll($names[0], $username).($testPrefix === null ? '' : $statements->grantAllWithPrefix($testPrefix, $username));
        $commands['database-apply'] = [...self::sql($server, $grant), ...($testPrefix === null ? [] : ['grant_prefix' => $testPrefix])];

        return ['commands' => $commands, 'creates' => $creates];
    }

    public static function createSqlDatabase(DatabaseServer $server, string $name, string $username, ?string $locale): string
    {
        if ($server->driver === DatabaseDriver::Mysql) {
            return (new MysqlStatements)->createDatabase($name);
        }
        $statements = new PostgresStatements;
        $create = trim($statements->createDatabase($name, $locale, $username));

        return $statements->assertDatabaseOwner($name, $username)."SELECT '".str_replace("'", "''", $create)."' WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '".$name."')\n\\gexec\n";
    }
}
