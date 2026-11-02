<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Shared\ResourceOperationException;

/**
 * The statements a database run sends on standard input, one `docker exec -i` per action (Q-DBAUTH).
 * Rendered at admission from validated names, so the run builds no SQL and the intent shows what runs.
 * A statement that carries a password never comes from here; it goes Gateway → vault → stdin.
 */
final readonly class DatabaseStatements
{
    private const string NAME = '/\A[A-Za-z0-9_]{1,64}\z/D';

    /**
     * @param  list<string>  $databases
     * @param  list<string>  $users
     * @param  list<string>  $testPrefixes
     * @return array{inventory: string, blockers: string, drop: string, gone: string}
     */
    public function destroy(DatabaseDriver $driver, array $databases, array $users, array $testPrefixes): array
    {
        $users = array_values(array_filter($users, static fn (string $user): bool => $user !== 'default'));
        if ($driver === DatabaseDriver::Redis) {
            foreach ($users as $user) {
                if (preg_match('/\A[^\s\x00-\x1F]{1,128}\z/D', $user) !== 1) {
                    $this->invalid();
                }
            }

            return ['inventory' => '', 'blockers' => '',
                'drop' => implode('', array_map(static fn (string $user): string => "ACL DELUSER {$user}\n", $users))."ACL SAVE\n",
                'gone' => implode('', array_map(static fn (string $user): string => "ACL GETUSER {$user}\n", $users))];
        }
        foreach ([...$databases, ...$users, ...$testPrefixes] as $name) {
            if (preg_match(self::NAME, $name) !== 1) {
                $this->invalid();
            }
        }
        $pg = $driver === DatabaseDriver::Pgsql;
        $names = $this->list($databases);
        $userList = $this->list($users);
        // MySQL test databases under the test prefix; only plain names, so the drop the run builds from them stays safe.
        $inventory = $pg || $testPrefixes === [] ? '' : 'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE ('
            .implode(' OR ', array_map(static fn (string $prefix): string => 'BINARY LEFT(SCHEMA_NAME,'.strlen($prefix).")=BINARY '{$prefix}'", $testPrefixes))
            .") AND SCHEMA_NAME REGEXP '^[A-Za-z0-9_]+$' ORDER BY SCHEMA_NAME;\n";
        $blockers = $pg ? <<<SQL
            SELECT DISTINCT blocker FROM (
             SELECT datname::text AS blocker FROM pg_stat_activity WHERE datname IN ({$names})
             UNION ALL SELECT database::text FROM pg_replication_slots WHERE database IN ({$names})
             UNION ALL SELECT database::text FROM pg_prepared_xacts WHERE database IN ({$names})
             UNION ALL SELECT d.datname::text FROM pg_subscription s JOIN pg_database d ON d.oid=s.subdbid WHERE d.datname IN ({$names})
             UNION ALL SELECT COALESCE(d.datname::text,owned.datname::text,r.rolname::text)
             FROM pg_shdepend dep JOIN pg_roles r ON dep.refclassid='pg_authid'::regclass AND dep.refobjid=r.oid
             LEFT JOIN pg_database d ON d.oid=dep.dbid
             LEFT JOIN pg_database owned ON dep.dbid=0 AND dep.classid='pg_database'::regclass AND dep.objid=owned.oid
             WHERE r.rolname IN ({$userList}) AND (dep.dbid<>0 AND d.datname NOT IN ({$names}) OR dep.dbid=0 AND (owned.oid IS NULL OR owned.datname NOT IN ({$names})))
            ) blockers ORDER BY blocker;

            SQL : $this->mysqlBlockers($names, $this->list(array_map(static fn (string $user): string => $user.'@%', $users)));
        $drop = implode('', array_map(static fn (string $name): string => $pg ? "DROP DATABASE IF EXISTS \"{$name}\";\n" : "DROP DATABASE IF EXISTS `{$name}`;\n", $databases))
            .implode('', array_map(static fn (string $user): string => $pg ? "DROP ROLE IF EXISTS \"{$user}\";\n" : "DROP USER IF EXISTS '{$user}'@'%';\n", $users));
        $gone = $pg
            ? "SELECT datname FROM pg_database WHERE datname IN ({$names}) UNION ALL SELECT rolname FROM pg_roles WHERE rolname IN ({$userList});\n"
            : "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME IN ({$names})"
                .($testPrefixes === [] ? '' : ' OR ('.implode(' OR ', array_map(static fn (string $prefix): string => 'BINARY LEFT(SCHEMA_NAME,'.strlen($prefix).")=BINARY '{$prefix}'", $testPrefixes)).')')
                ." UNION ALL SELECT User FROM mysql.user WHERE User IN ({$userList}) AND Host='%';\n";

        return ['inventory' => $inventory, 'blockers' => $blockers, 'drop' => $drop, 'gone' => $gone];
    }

    /**
     * The admin client argv for one `docker exec -i` with the statement on stdin. MySQL and Valkey read the admin login
     * from the env file a step writes with cfgmgmt/file; PostgreSQL uses local trust inside the container.
     *
     * @return list<string>
     */
    public static function client(DatabaseDriver $driver, string $container, string $login): array
    {
        return match ($driver) {
            DatabaseDriver::Pgsql => ['docker', 'exec', '-i', $container, 'psql', '--no-psqlrc', '--username=postgres', '--dbname=postgres',
                '--no-align', '--tuples-only', '--quiet', '--set=ON_ERROR_STOP=1'],
            DatabaseDriver::Redis => ['docker', 'exec', '-i', '--env-file', $login, $container, 'valkey-cli', '--user', 'default', '--no-auth-warning'],
            default => ['docker', 'exec', '-i', '--env-file', $login, $container, 'mysql', '--protocol=TCP', '--host=127.0.0.1', '--user=root',
                '--batch', '--skip-column-names'],
        };
    }

    /** @param list<string> $names */
    private function list(array $names): string
    {
        return $names === [] ? 'NULL' : implode(',', array_map(static fn (string $name): string => "'{$name}'", $names));
    }

    private function mysqlBlockers(string $names, string $definers): string
    {
        return <<<SQL
            SELECT DISTINCT blocker FROM (
             SELECT DB AS blocker FROM information_schema.PROCESSLIST WHERE DB IN ({$names}) AND ID<>CONNECTION_ID()
             UNION ALL SELECT locks.OBJECT_SCHEMA FROM performance_schema.metadata_locks locks LEFT JOIN performance_schema.threads threads ON threads.THREAD_ID=locks.OWNER_THREAD_ID WHERE locks.OBJECT_SCHEMA IN ({$names}) AND (threads.PROCESSLIST_ID IS NULL OR threads.PROCESSLIST_ID<>CONNECTION_ID())
             UNION ALL SELECT TABLE_SCHEMA FROM information_schema.VIEWS WHERE DEFINER IN ({$definers}) AND TABLE_SCHEMA NOT IN ({$names})
             UNION ALL SELECT ROUTINE_SCHEMA FROM information_schema.ROUTINES WHERE DEFINER IN ({$definers}) AND ROUTINE_SCHEMA NOT IN ({$names})
             UNION ALL SELECT TRIGGER_SCHEMA FROM information_schema.TRIGGERS WHERE DEFINER IN ({$definers}) AND TRIGGER_SCHEMA NOT IN ({$names})
             UNION ALL SELECT EVENT_SCHEMA FROM information_schema.EVENTS WHERE DEFINER IN ({$definers}) AND EVENT_SCHEMA NOT IN ({$names})
             UNION ALL SELECT TABLE_SCHEMA FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA IN ({$names}) AND TABLE_SCHEMA<>REFERENCED_TABLE_SCHEMA
            ) blockers ORDER BY blocker;

            SQL;
    }

    private function invalid(): never
    {
        throw new ResourceOperationException('database.server_admin_invalid', 'A database or user name is not a plain identifier.', 422);
    }
}
