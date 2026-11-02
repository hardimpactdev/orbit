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
     * database:create. `probe` and `login` are public; `create` carries the owner's password (or its SCRAM verifier,
     * or its ACL hash) and goes Gateway → vault → stdin; `owner_env` is the owner's login as a Docker env file.
     *
     * @param  array{driver: string, username: string, databases: list<string>, test_prefix: ?string, locale: ?string, index: ?int, patterns: list<string>, allow: list<string>}  $input
     * @return array{probe: string, login: string, create: string, owner_env: string}
     */
    public function create(array $input, #[\SensitiveParameter] string $password, string $salt): array
    {
        $driver = DatabaseDriver::from($input['driver']);
        $user = $input['username'];
        if ($driver === DatabaseDriver::Redis) {
            if (preg_match('/\A[A-Za-z0-9_-]{1,63}\z/D', $user) !== 1) {
                $this->invalid();
            }
            $rules = ['reset', 'on', '#'.hash('sha256', $password), ...array_map(static fn (string $p): string => '~'.$p, $input['patterns']),
                'resetchannels', '-@all', ...array_map(static fn (string $r): string => '+'.$r, $input['allow']), '-@admin', '-@dangerous', '-function', '-script',
                ...(in_array('flushdb', $input['allow'], true) ? ['+flushdb'] : [])];
            foreach ($rules as $rule) {
                if (preg_match('/\A[^\s\x00-\x1F"]{1,1024}\z/D', $rule) !== 1) {
                    $this->invalid();
                }
            }

            return ['probe' => "CONFIG GET databases\nACL GETUSER {$user}\n", 'login' => "PING\n",
                'create' => "ACL SETUSER {$user} ".implode(' ', $rules)."\nACL SAVE\n", 'owner_env' => "REDISCLI_AUTH={$password}\n"];
        }
        foreach ([$user, ...$input['databases'], ...($input['test_prefix'] === null ? [] : [$input['test_prefix']])] as $name) {
            if (preg_match(self::NAME, $name) !== 1) {
                $this->invalid();
            }
        }
        $pg = $driver === DatabaseDriver::Pgsql;
        $names = $this->list($input['databases']);
        if ($pg) {
            $locale = $input['locale'] === null ? '' : " TEMPLATE template0 ENCODING 'UTF8' LOCALE_PROVIDER builtin BUILTIN_LOCALE '{$input['locale']}'";
            $create = "DO \$orbit\$ BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname='{$user}') THEN CREATE ROLE \"{$user}\" LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD '".self::scram($password, $salt)."'; END IF; END \$orbit\$;\n";
            foreach ($input['databases'] as $name) {
                // psql \\gexec runs CREATE DATABASE outside a transaction; the statement is a literal, so its quotes double.
                $statement = str_replace("'", "''", "CREATE DATABASE \"{$name}\" OWNER \"{$user}\"{$locale}");
                $create .= "SELECT '{$statement}' WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname='{$name}')\\gexec\n";
            }
            foreach ($input['databases'] as $name) {
                $create .= "REVOKE ALL ON DATABASE \"{$name}\" FROM PUBLIC;\n\\connect {$name}\nREVOKE CREATE ON SCHEMA public FROM PUBLIC;\nGRANT ALL ON SCHEMA public TO \"{$user}\";\n\\connect postgres\n";
            }
            // A database that exists with another owner, or a role that exists with superuser-like rights, is not ours to reuse.
            $probe = "SELECT datname FROM pg_database WHERE datname IN ({$names}) AND pg_get_userbyid(datdba) <> '{$user}' UNION ALL SELECT rolname FROM pg_roles WHERE rolname='{$user}' AND (rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication);\n";

            return ['probe' => $probe, 'login' => "SELECT current_user;\n", 'create' => $create, 'owner_env' => "PGPASSWORD={$password}\n"];
        }
        $prefix = $input['test_prefix'];
        $create = "CREATE USER IF NOT EXISTS '{$user}'@'%' IDENTIFIED BY '".str_replace(['\\', "'"], ['\\\\', "''"], $password)."';\n";
        foreach ($input['databases'] as $name) {
            $create .= "CREATE DATABASE IF NOT EXISTS `{$name}`;\nGRANT ALL PRIVILEGES ON `".str_replace('_', '\\_', $name)."`.* TO '{$user}'@'%';\n";
        }
        if ($prefix !== null) {
            $create .= 'GRANT ALL PRIVILEGES ON `'.str_replace('_', '\\_', $prefix)."%`.* TO '{$user}'@'%';\n";
        }
        // An existing database the owner holds no full grant on belongs to someone else.
        $grant = "d.Db=REPLACE(s.SCHEMA_NAME,'_','\\\\_')";
        $probe = "SELECT s.SCHEMA_NAME FROM information_schema.SCHEMATA s WHERE s.SCHEMA_NAME IN ({$names}) AND NOT EXISTS (SELECT 1 FROM mysql.db d WHERE d.User='{$user}' AND d.Host='%' AND {$grant} AND d.Create_priv='Y');\n";

        return ['probe' => $probe, 'login' => "SELECT CURRENT_USER();\n", 'create' => $create, 'owner_env' => "MYSQL_PWD={$password}\n"];
    }

    /** PostgreSQL's SCRAM-SHA-256 verifier, so the plaintext password never reaches the server's SQL log. */
    public static function scram(#[\SensitiveParameter] string $password, string $salt): string
    {
        $salted = hash_pbkdf2('sha256', $password, base64_decode($salt, true) ?: '', 4096, 32, true);
        $stored = hash('sha256', hash_hmac('sha256', 'Client Key', $salted, true), true);
        $server = hash_hmac('sha256', 'Server Key', $salted, true);

        return "SCRAM-SHA-256\$4096:{$salt}\$".base64_encode($stored).':'.base64_encode($server);
    }

    /**
     * The owner's client argv for the login check, over TCP so the password itself is proven.
     *
     * @return list<string>
     */
    public static function ownerClient(DatabaseDriver $driver, string $container, string $login, string $user, string $database): array
    {
        return match ($driver) {
            DatabaseDriver::Pgsql => ['docker', 'exec', '-i', '--env-file', $login, $container, 'psql', '--no-psqlrc', '--host=127.0.0.1', "--username={$user}",
                "--dbname={$database}", '--no-align', '--tuples-only', '--quiet', '--set=ON_ERROR_STOP=1'],
            DatabaseDriver::Redis => ['docker', 'exec', '-i', '--env-file', $login, $container, 'valkey-cli', '--user', $user, '--no-auth-warning'],
            default => ['docker', 'exec', '-i', '--env-file', $login, $container, 'mysql', '--protocol=TCP', '--host=127.0.0.1', "--user={$user}",
                '--batch', '--skip-column-names'],
        };
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
