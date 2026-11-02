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
     * database:destroy, in the run's order: `fence` (PostgreSQL: journal each database's ALLOW_CONNECTIONS and close it to
     * new clients, in one transaction), `drop` (refuses on a blocker with `database.in_use` before the first drop, then
     * drops the planned names), `gone` (fails while a planned name remains) and `restore` (PostgreSQL: gives each
     * surviving database its journaled value back). Each follows the admin password line on stdin.
     *
     * @param  list<string>  $databases
     * @param  list<string>  $users
     * @return array{fence: string, drop: string, gone: string, restore: string}
     */
    public function destroy(DatabaseDriver $driver, array $databases, array $users): array
    {
        $users = array_values(array_filter($users, static fn (string $user): bool => $user !== 'default'));
        if ($driver === DatabaseDriver::Redis) {
            foreach ($users as $user) {
                if (preg_match('/\A[^\s\x00-\x1F]{1,128}\z/D', $user) !== 1) {
                    $this->invalid();
                }
            }

            return ['fence' => '', 'restore' => '',
                'drop' => implode('', array_map(static fn (string $user): string => "ACL DELUSER {$user}\n", $users))."ACL SAVE\n",
                'gone' => implode('', array_map(static fn (string $user): string => "ACL GETUSER {$user}\n", $users))];
        }
        foreach ([...$databases, ...$users] as $name) {
            if (preg_match(self::NAME, $name) !== 1) {
                $this->invalid();
            }
        }
        $names = $this->list($databases);
        $userList = $this->list($users);
        if ($driver === DatabaseDriver::Pgsql) {
            $blockers = "SELECT datname::text FROM pg_stat_activity WHERE datname IN ({$names})"
                ." UNION ALL SELECT database::text FROM pg_replication_slots WHERE database IN ({$names})"
                ." UNION ALL SELECT database::text FROM pg_prepared_xacts WHERE database IN ({$names})"
                ." UNION ALL SELECT d.datname::text FROM pg_subscription s JOIN pg_database d ON d.oid=s.subdbid WHERE d.datname IN ({$names})"
                ." UNION ALL SELECT r.rolname::text FROM pg_shdepend dep JOIN pg_roles r ON dep.refclassid='pg_authid'::regclass AND dep.refobjid=r.oid"
                ." LEFT JOIN pg_database d ON d.oid=dep.dbid LEFT JOIN pg_database owned ON dep.dbid=0 AND dep.classid='pg_database'::regclass AND dep.objid=owned.oid"
                ." WHERE r.rolname IN ({$userList}) AND (dep.dbid<>0 AND d.datname NOT IN ({$names}) OR dep.dbid=0 AND (owned.oid IS NULL OR owned.datname NOT IN ({$names})))";
            $journal = 'orbit_admin.database_removal_fences';
            $fence = "BEGIN;\nCREATE SCHEMA IF NOT EXISTS orbit_admin;\nCREATE TABLE IF NOT EXISTS {$journal} (oid oid PRIMARY KEY, datname name NOT NULL, allow_connections boolean NOT NULL);\n"
                ."INSERT INTO {$journal} SELECT oid, datname, datallowconn FROM pg_database WHERE datname IN ({$names}) ON CONFLICT (oid) DO NOTHING;\n"
                .implode('', array_map(static fn (string $name): string => "SELECT 'ALTER DATABASE \"{$name}\" ALLOW_CONNECTIONS false' WHERE EXISTS (SELECT FROM pg_database WHERE datname='{$name}')\\gexec\n", $databases))
                ."COMMIT;\n";
            $drop = "SELECT 'database.in_use' WHERE EXISTS ({$blockers});\n"
                ."DO \$orbit\$ BEGIN IF EXISTS ({$blockers}) THEN RAISE EXCEPTION 'database.in_use'; END IF; END \$orbit\$;\n"
                .implode('', array_map(static fn (string $name): string => "DROP DATABASE IF EXISTS \"{$name}\";\n", $databases))
                .implode('', array_map(static fn (string $user): string => "DROP ROLE IF EXISTS \"{$user}\";\n", $users))
                ."DELETE FROM {$journal} WHERE datname IN ({$names});\n";
            // The journal holds the database OID, so a replacement database with the same name is never changed.
            $restore = "SELECT format('ALTER DATABASE %I ALLOW_CONNECTIONS %s', d.datname, j.allow_connections) FROM {$journal} j JOIN pg_database d ON d.oid=j.oid WHERE j.datname IN ({$names})\\gexec\n"
                ."DELETE FROM {$journal} WHERE datname IN ({$names});\n";

            $left = "SELECT datname FROM pg_database WHERE datname IN ({$names}) UNION ALL SELECT rolname FROM pg_roles WHERE rolname IN ({$userList})";

            // The end check fails the run while a planned name remains, so a parent run sees the refusal too.
            return ['fence' => $fence, 'drop' => $drop, 'restore' => $restore,
                'gone' => "DO \$orbit\$ BEGIN IF EXISTS ({$left}) THEN RAISE EXCEPTION 'database.destroy_incomplete'; END IF; END \$orbit\$;\n"];
        }
        $blockers = $this->mysqlBlockers($names, $this->list(array_map(static fn (string $user): string => $user.'@%', $users)));
        // A blocker stops the batch before the first drop: the prepared statement reads a table that does not exist.
        $drop = "SELECT 'database.in_use' FROM DUAL WHERE EXISTS ({$blockers});\n"
            ."SET @orbit_guard = IF(EXISTS ({$blockers}), 'SELECT 1 FROM orbit_in_use.refused', 'DO 0');\n"
            ."PREPARE orbit_guard FROM @orbit_guard;\nEXECUTE orbit_guard;\nDEALLOCATE PREPARE orbit_guard;\n"
            .implode('', array_map(static fn (string $name): string => "DROP DATABASE IF EXISTS `{$name}`;\n", $databases))
            .implode('', array_map(static fn (string $user): string => "DROP USER IF EXISTS '{$user}'@'%';\n", $users));

        $left = "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME IN ({$names}) UNION ALL SELECT User FROM mysql.user WHERE User IN ({$userList}) AND Host='%'";

        // The end check fails the run while a planned name remains, so a parent run sees the refusal too.
        return ['fence' => '', 'restore' => '', 'drop' => $drop,
            'gone' => "SET @orbit_left = IF(EXISTS ({$left}), 'SELECT 1 FROM orbit_destroy_incomplete.refused', 'DO 0');\n"
                ."PREPARE orbit_left FROM @orbit_left;\nEXECUTE orbit_left;\nDEALLOCATE PREPARE orbit_left;\n"];
    }

    /**
     * database:create. `create` carries the owner's password (or its SCRAM verifier, or its ACL hash) and refuses a
     * foreign name by itself; `login` is the owner check. Both go Gateway → vault → stdin behind a password line.
     *
     * @param  array{driver: string, username: string, databases: list<string>, test_prefix: ?string, locale: ?string, index: ?int, patterns: list<string>, allow: list<string>}  $input
     * @return array{login: string, create: string, owner_password: string}
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

            // The batch reads the server's database count first; the Gateway checks the index against it.
            return ['login' => "PING\n", 'owner_password' => $password,
                'create' => "CONFIG GET databases\nACL SETUSER {$user} ".implode(' ', $rules)."\nACL SAVE\n"];
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
            $foreign = "SELECT datname FROM pg_database WHERE datname IN ({$names}) AND pg_get_userbyid(datdba) <> '{$user}' UNION ALL SELECT rolname FROM pg_roles WHERE rolname='{$user}' AND (rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication)";
            // A name the server holds for someone else stops the batch before any change; the marker names the refusal.
            $guard = "SELECT 'database.name_conflict' WHERE EXISTS ({$foreign});\nDO \$orbit\$ BEGIN IF EXISTS ({$foreign}) THEN RAISE EXCEPTION 'database.name_conflict'; END IF; END \$orbit\$;\n";

            return ['login' => "SELECT current_user;\n", 'owner_password' => $password, 'create' => $guard.$create];
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
        $foreign = "SELECT 1 FROM information_schema.SCHEMATA s WHERE s.SCHEMA_NAME IN ({$names}) AND NOT EXISTS (SELECT 1 FROM mysql.db d WHERE d.User='{$user}' AND d.Host='%' AND {$grant} AND d.Create_priv='Y')";
        // A name the server holds for someone else stops the batch before any change: the prepared statement reads a
        // table that does not exist. The marker line names the refusal for the Gateway.
        $guard = "SELECT 'database.name_conflict' FROM DUAL WHERE EXISTS ({$foreign});\n"
            ."SET @orbit_guard = IF(EXISTS ({$foreign}), 'SELECT 1 FROM orbit_name_conflict.refused', 'DO 0');\n"
            ."PREPARE orbit_guard FROM @orbit_guard;\nEXECUTE orbit_guard;\nDEALLOCATE PREPARE orbit_guard;\n";

        return ['login' => "SELECT CURRENT_USER();\n", 'owner_password' => $password, 'create' => $guard.$create];
    }

    /**
     * The statements of `database:user:create`: `apply` (the WAL and name checks, the login and exact grants in one
     * batch, with the password, so it travels Gateway → vault → stdin) and the user's own login check (Q-DBAUTH).
     *
     * @param  array{driver: string, username: string, database: string, owner_username: string, read_only: bool, replication: bool, known_user: bool}  $input
     * @return array{apply: string, check: string}
     */
    public function user(array $input, #[\SensitiveParameter] string $password, string $salt): array
    {
        foreach ([$input['username'], $input['database'], $input['owner_username']] as $name) {
            if (preg_match(self::NAME, $name) !== 1) {
                $this->invalid();
            }
        }
        ['username' => $user, 'database' => $database, 'owner_username' => $owner] = $input;
        if ($input['driver'] === DatabaseDriver::Mysql->value) {
            $account = "'{$user}'@'%'";
            $secret = "'".str_replace(['\\', "'"], ['\\\\', "''"], $password)."'";
            $grant = '`'.str_replace('_', '\\_', $database).'`.*';
            $privilege = $input['read_only'] ? 'SELECT' : 'ALL PRIVILEGES';

            return ['apply' => "CREATE USER IF NOT EXISTS {$account} IDENTIFIED BY {$secret};\nALTER USER {$account} IDENTIFIED BY {$secret};\n"
                ."GRANT SELECT ON {$grant} TO {$account};\nREVOKE ALL PRIVILEGES ON {$grant} FROM {$account};\nGRANT {$privilege} ON {$grant} TO {$account};\n",
                'check' => "SELECT CURRENT_USER();\n"];
        }
        $verifier = self::scram($password, $salt);
        // A role this connection does not know, with another password, belongs to someone else.
        // A role this connection does not know, with another password, belongs to someone else: the batch prints the
        // refusal for the Gateway and stops before any change.
        $foreign = "SELECT FROM pg_authid WHERE rolname='{$user}' AND rolpassword IS DISTINCT FROM '{$verifier}'";
        $guard = $input['known_user'] ? '' : "SELECT 'database.name_conflict' WHERE EXISTS ({$foreign});\nDO \$orbit\$ BEGIN IF EXISTS ({$foreign}) THEN RAISE EXCEPTION 'database.name_conflict'; END IF; END \$orbit\$;\n";
        // A replication login needs live wal_level=logical; Orbit never changes server configuration.
        $wal = "current_setting('wal_level') <> 'logical'";
        $guard = ($input['replication'] ? "SELECT 'database.wal_level_not_logical' WHERE {$wal};\nDO \$orbit\$ BEGIN IF {$wal} THEN RAISE EXCEPTION 'database.wal_level_not_logical'; END IF; END \$orbit\$;\n" : '').$guard;
        $attributes = 'LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE '.($input['replication'] ? 'REPLICATION' : 'NOREPLICATION')." PASSWORD '{$verifier}'";
        $tables = $input['read_only'] ? 'SELECT' : 'SELECT, INSERT, UPDATE, DELETE, TRUNCATE, REFERENCES, TRIGGER';
        $sequences = $input['read_only'] ? 'SELECT' : 'USAGE, SELECT, UPDATE';

        return ['apply' => $guard."DO \$orbit\$ BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname='{$user}') THEN CREATE ROLE \"{$user}\" {$attributes}; ELSE ALTER ROLE \"{$user}\" {$attributes}; END IF; END \$orbit\$;\n"
                ."BEGIN;\nREVOKE ALL ON DATABASE \"{$database}\" FROM \"{$user}\";\nGRANT CONNECT ON DATABASE \"{$database}\" TO \"{$user}\";\nCOMMIT;\n\\connect {$database}\n"
                ."BEGIN;\nREVOKE ALL ON SCHEMA public FROM \"{$user}\";\nGRANT USAGE ON SCHEMA public TO \"{$user}\";\n"
                ."REVOKE ALL ON ALL TABLES IN SCHEMA public FROM \"{$user}\";\nREVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM \"{$user}\";\n"
                ."ALTER DEFAULT PRIVILEGES FOR ROLE \"{$owner}\" IN SCHEMA public REVOKE ALL ON TABLES FROM \"{$user}\";\n"
                ."ALTER DEFAULT PRIVILEGES FOR ROLE \"{$owner}\" IN SCHEMA public REVOKE ALL ON SEQUENCES FROM \"{$user}\";\n"
                ."GRANT {$tables} ON ALL TABLES IN SCHEMA public TO \"{$user}\";\nGRANT {$sequences} ON ALL SEQUENCES IN SCHEMA public TO \"{$user}\";\n"
                ."ALTER DEFAULT PRIVILEGES FOR ROLE \"{$owner}\" IN SCHEMA public GRANT {$tables} ON TABLES TO \"{$user}\";\n"
                ."ALTER DEFAULT PRIVILEGES FOR ROLE \"{$owner}\" IN SCHEMA public GRANT {$sequences} ON SEQUENCES TO \"{$user}\";\nCOMMIT;\n",
            'check' => "SELECT current_user;\n"];
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
     * One `docker exec -i` login that reads the password from the first stdin line inside the container, then runs the
     * rest of stdin (Q-DBAUTH): no login file and no password in argv. Stdin is the vault value plus the statements.
     *
     * @return list<string>
     */
    public static function login(DatabaseDriver $driver, string $container, string $user, string $database): array
    {
        $client = match ($driver) {
            DatabaseDriver::Pgsql => 'PGPASSWORD && exec psql --no-psqlrc --host=127.0.0.1 --username="$1" --dbname="$2" --no-align --tuples-only --quiet --set=ON_ERROR_STOP=1',
            DatabaseDriver::Redis => 'REDISCLI_AUTH && exec valkey-cli -e --user "$1" --no-auth-warning',
            default => 'MYSQL_PWD && exec mysql --protocol=TCP --host=127.0.0.1 --user="$1" --database="$2" --batch --skip-column-names',
        };
        $variable = strtok($client, ' ');

        return ['docker', 'exec', '-i', $container, 'sh', '-c', "IFS= read -r {$variable} && export {$client}", 'sh', $user, $database];
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
        return "SELECT DB FROM information_schema.PROCESSLIST WHERE DB IN ({$names}) AND ID<>CONNECTION_ID()"
            ." UNION ALL SELECT locks.OBJECT_SCHEMA FROM performance_schema.metadata_locks locks LEFT JOIN performance_schema.threads threads ON threads.THREAD_ID=locks.OWNER_THREAD_ID WHERE locks.OBJECT_SCHEMA IN ({$names}) AND (threads.PROCESSLIST_ID IS NULL OR threads.PROCESSLIST_ID<>CONNECTION_ID())"
            ." UNION ALL SELECT TABLE_SCHEMA FROM information_schema.VIEWS WHERE DEFINER IN ({$definers}) AND TABLE_SCHEMA NOT IN ({$names})"
            ." UNION ALL SELECT ROUTINE_SCHEMA FROM information_schema.ROUTINES WHERE DEFINER IN ({$definers}) AND ROUTINE_SCHEMA NOT IN ({$names})"
            ." UNION ALL SELECT TRIGGER_SCHEMA FROM information_schema.TRIGGERS WHERE DEFINER IN ({$definers}) AND TRIGGER_SCHEMA NOT IN ({$names})"
            ." UNION ALL SELECT EVENT_SCHEMA FROM information_schema.EVENTS WHERE DEFINER IN ({$definers}) AND EVENT_SCHEMA NOT IN ({$names})"
            ." UNION ALL SELECT TABLE_SCHEMA FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA IN ({$names}) AND TABLE_SCHEMA<>REFERENCED_TABLE_SCHEMA";
    }

    private function invalid(): never
    {
        throw new ResourceOperationException('database.server_admin_invalid', 'A database or user name is not a plain identifier.', 422);
    }
}
