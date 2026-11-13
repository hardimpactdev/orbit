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
            $restore = "SELECT format('ALTER DATABASE %I ALLOW_CONNECTIONS %s', d.datname, CASE WHEN j.allow_connections THEN 'true' ELSE 'false' END) FROM {$journal} j JOIN pg_database d ON d.oid=j.oid WHERE j.datname IN ({$names})\\gexec\n"
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

        return ['login' => "SELECT CURRENT_USER();\n", 'owner_password' => $password, 'create' => self::mysqlGuard($foreign).$create];
    }

    /**
     * The statements of `database:user:create`: `wal` (the live WAL level, read before a PostgreSQL replication login),
     * `apply` (the name check, the login and exact grants in one batch, with the password, so it travels Gateway →
     * vault → stdin) and `check`, which the user runs with its own login (Q-DBAUTH) to read back its login, attributes
     * and grants, as {@see userCheckLines()} lists.
     *
     * @param  array{driver: string, username: string, database: string, owner_username: string, read_only: bool, replication: bool, known_user: bool}  $input
     * @return array{wal: string, apply: string, check: string}
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

            // An account of this name that the connection did not record (any host) belongs to someone else: the batch
            // prints the refusal for the Gateway and stops before any change, as create() does.
            $foreign = "SELECT 1 FROM mysql.user WHERE User='{$user}'";
            $guard = $input['known_user'] ? '' : self::mysqlGuard($foreign);

            return ['wal' => "SELECT 1;\n", 'apply' => $guard."CREATE USER IF NOT EXISTS {$account} IDENTIFIED BY {$secret};\nALTER USER {$account} IDENTIFIED BY {$secret};\n"
                ."GRANT SELECT ON {$grant} TO {$account};\nREVOKE ALL PRIVILEGES ON {$grant} FROM {$account};\nGRANT {$privilege} ON {$grant} TO {$account};\n",
                'check' => "SELECT CURRENT_USER();\nSHOW GRANTS;\n"];
        }
        $verifier = self::scram($password, $salt);
        // A role this connection does not know, with another password, belongs to someone else: the batch prints the
        // refusal for the Gateway and stops before any change.
        $foreign = "SELECT FROM pg_authid WHERE rolname='{$user}' AND rolpassword IS DISTINCT FROM '{$verifier}'";
        $guard = $input['known_user'] ? '' : "SELECT 'database.name_conflict' WHERE EXISTS ({$foreign});\nDO \$orbit\$ BEGIN IF EXISTS ({$foreign}) THEN RAISE EXCEPTION 'database.name_conflict'; END IF; END \$orbit\$;\n";
        $attributes = 'LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE '.($input['replication'] ? 'REPLICATION' : 'NOREPLICATION')." PASSWORD '{$verifier}'";
        $tables = $input['read_only'] ? 'SELECT' : 'SELECT, INSERT, UPDATE, DELETE, TRUNCATE, REFERENCES, TRIGGER';
        $sequences = $input['read_only'] ? 'SELECT' : 'USAGE, SELECT, UPDATE';
        // The owner's default table privileges hold the user's entry, as PostgreSQL prints an aclitem.
        $quote = static fn (string $name): string => preg_match('/\A[a-z_][a-z0-9_]*\z/D', $name) === 1 ? $name : "\"{$name}\"";
        $entry = $quote($user).'='.($input['read_only'] ? 'r' : 'arwdDxt').'/'.$quote($owner);

        return ['apply' => $guard."DO \$orbit\$ BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname='{$user}') THEN CREATE ROLE \"{$user}\" {$attributes}; ELSE ALTER ROLE \"{$user}\" {$attributes}; END IF; END \$orbit\$;\n"
                ."BEGIN;\nREVOKE ALL ON DATABASE \"{$database}\" FROM \"{$user}\";\nGRANT CONNECT ON DATABASE \"{$database}\" TO \"{$user}\";\nCOMMIT;\n\\connect {$database}\n"
                ."BEGIN;\nREVOKE ALL ON SCHEMA public FROM \"{$user}\";\nGRANT USAGE ON SCHEMA public TO \"{$user}\";\n"
                ."REVOKE ALL ON ALL TABLES IN SCHEMA public FROM \"{$user}\";\nREVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM \"{$user}\";\n"
                ."ALTER DEFAULT PRIVILEGES FOR ROLE \"{$owner}\" IN SCHEMA public REVOKE ALL ON TABLES FROM \"{$user}\";\n"
                ."ALTER DEFAULT PRIVILEGES FOR ROLE \"{$owner}\" IN SCHEMA public REVOKE ALL ON SEQUENCES FROM \"{$user}\";\n"
                ."GRANT {$tables} ON ALL TABLES IN SCHEMA public TO \"{$user}\";\nGRANT {$sequences} ON ALL SEQUENCES IN SCHEMA public TO \"{$user}\";\n"
                ."ALTER DEFAULT PRIVILEGES FOR ROLE \"{$owner}\" IN SCHEMA public GRANT {$tables} ON TABLES TO \"{$user}\";\n"
                ."ALTER DEFAULT PRIVILEGES FOR ROLE \"{$owner}\" IN SCHEMA public GRANT {$sequences} ON SEQUENCES TO \"{$user}\";\nCOMMIT;\n",
            // A replication login needs live wal_level=logical; Orbit never changes server configuration.
            'wal' => "SHOW wal_level;\n",
            'check' => "SELECT current_user;\nSELECT rolreplication FROM pg_roles WHERE rolname = current_user;\n"
                ."SELECT has_database_privilege(current_database(), 'CONNECT') AND has_schema_privilege('public', 'USAGE');\n"
                ."SELECT '{$entry}' = ANY (SELECT unnest(d.defaclacl)::text FROM pg_default_acl d JOIN pg_roles o ON o.oid = d.defaclrole "
                ."JOIN pg_namespace n ON n.oid = d.defaclnamespace WHERE o.rolname = '{$owner}' AND n.nspname = 'public' AND d.defaclobjtype = 'r');\n"];
    }

    /**
     * The lines the user's own check prints when its login, attributes and grants are exactly the requested ones.
     *
     * @param  array{driver: string, username: string, database: string, read_only: bool, replication: bool}  $input
     * @return list<string>
     */
    public static function userCheckLines(array $input): array
    {
        $user = $input['username'];
        if ($input['driver'] === DatabaseDriver::Mysql->value) {
            $privilege = $input['read_only'] ? 'SELECT' : 'ALL PRIVILEGES';
            $grant = '`'.str_replace('_', '\\_', $input['database']).'`.*';

            // mysql --batch doubles each backslash in its output, such as the escaped underscore of the grant.
            return ["{$user}@%", "GRANT USAGE ON *.* TO `{$user}`@`%`", str_replace('\\', '\\\\', "GRANT {$privilege} ON {$grant} TO `{$user}`@`%`")];
        }

        return [$user, $input['replication'] ? 't' : 'f', 't', 't'];
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
     * A container clone's PostgreSQL database (docs/reference/development-containers.md#clone-the-database): a template copy of the
     * default's database on the same server, inside the server's container, with the admin login from the first stdin line.
     * PostgreSQL copies only from a database without sessions, so the run closes the default database to new connections and ends
     * its sessions first; a trap opens it again whatever happens. The empty database that the create child made is replaced, and
     * the copy's objects pass from the default's owner to the clone's owner. The names are validated identifiers, and each SQL
     * batch is one argument, so the shell quotes nothing.
     *
     * @return list<string>
     */
    public static function template(string $container, string $admin, string $source, string $sourceOwner, string $target, string $targetOwner): array
    {
        // Lower case only: the owners are named as `'<name>'::regrole`, which folds case.
        foreach ([$admin, $source, $sourceOwner, $target, $targetOwner] as $name) {
            if (preg_match('/\A[a-z0-9_]{1,63}\z/D', $name) !== 1) {
                throw new ResourceOperationException('database.server_admin_invalid', 'A database or user name is not a plain identifier.', 422);
            }
        }
        $owned = static fn (string $from): string => "{$from} AND n.nspname NOT IN ('pg_catalog', 'information_schema') AND n.nspname NOT LIKE 'pg\\_toast%'";
        $own = "DO \$orbit\$ DECLARE r record; BEGIN\n"
            .'FOR r IN SELECT c.oid::regclass AS name, c.relkind FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE '
            .$owned("c.relowner = '{$sourceOwner}'::regrole AND c.relkind IN ('r', 'p', 'v', 'm', 'f', 'S')")
            // A sequence that belongs to a column changes owner with its table.
            ." AND NOT (c.relkind = 'S' AND EXISTS (SELECT FROM pg_depend d WHERE d.classid = 'pg_class'::regclass AND d.objid = c.oid AND d.deptype IN ('a', 'i')))\n"
            ."LOOP EXECUTE format('ALTER %s %s OWNER TO %I', CASE r.relkind WHEN 'S' THEN 'SEQUENCE' WHEN 'v' THEN 'VIEW' WHEN 'm' THEN 'MATERIALIZED VIEW' WHEN 'f' THEN 'FOREIGN TABLE' ELSE 'TABLE' END, r.name, '{$targetOwner}'); END LOOP;\n"
            .'FOR r IN SELECT p.oid::regprocedure AS name, p.prokind FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE '
            .$owned("p.proowner = '{$sourceOwner}'::regrole")
            ." AND NOT EXISTS (SELECT FROM pg_depend d WHERE d.classid = 'pg_proc'::regclass AND d.objid = p.oid AND d.deptype = 'e')\n"
            ."LOOP EXECUTE format('ALTER %s %s OWNER TO %I', CASE r.prokind WHEN 'p' THEN 'PROCEDURE' WHEN 'a' THEN 'AGGREGATE' ELSE 'FUNCTION' END, r.name, '{$targetOwner}'); END LOOP;\n"
            .'FOR r IN SELECT t.oid::regtype AS name, t.typtype FROM pg_type t JOIN pg_namespace n ON n.oid = t.typnamespace WHERE '
            .$owned("t.typowner = '{$sourceOwner}'::regrole AND (t.typtype IN ('e', 'd', 'r') OR t.typtype = 'c' AND (SELECT relkind FROM pg_class WHERE oid = t.typrelid) = 'c')")
            ."\nLOOP EXECUTE format('ALTER %s %s OWNER TO %I', CASE r.typtype WHEN 'd' THEN 'DOMAIN' ELSE 'TYPE' END, r.name, '{$targetOwner}'); END LOOP;\n"
            ."FOR r IN SELECT nspname FROM pg_namespace WHERE nspowner = '{$sourceOwner}'::regrole LOOP EXECUTE format('ALTER SCHEMA %I OWNER TO %I', r.nspname, '{$targetOwner}'); END LOOP;\n"
            ."END \$orbit\$;\n"
            ."REVOKE ALL ON DATABASE \"{$target}\" FROM PUBLIC;\nREVOKE CREATE ON SCHEMA public FROM PUBLIC;\n"
            ."REVOKE ALL ON SCHEMA public FROM \"{$sourceOwner}\";\nGRANT ALL ON SCHEMA public TO \"{$targetOwner}\";\n";

        return ['docker', 'exec', '-i', $container, 'sh', '-c',
            'set -eu; IFS= read -r PGPASSWORD && export PGPASSWORD; admin=$1; '
            .'pg() { psql --no-psqlrc --host=127.0.0.1 --username="$admin" --quiet --set=ON_ERROR_STOP=1 "$@" >/dev/null; }; '
            .'trap \'pg --dbname=postgres --command="$4"\' EXIT; '
            .'pg --dbname=postgres --command="$5"; pg --dbname=postgres --command="$6"; pg --dbname=postgres --command="$7"; pg --dbname="$3" --command="$8"',
            'sh', $admin, $source, $target,
            "ALTER DATABASE \"{$source}\" ALLOW_CONNECTIONS true",
            "ALTER DATABASE \"{$source}\" ALLOW_CONNECTIONS false; SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '{$source}' AND pid <> pg_backend_pid()",
            "DROP DATABASE IF EXISTS \"{$target}\" WITH (FORCE)",
            "CREATE DATABASE \"{$target}\" TEMPLATE \"{$source}\" OWNER \"{$targetOwner}\"",
            $own];
    }

    /**
     * The copy of one MySQL database into another inside the server's container: the root login from the first stdin
     * line, `mysqldump --single-transaction` piped into `mysql` (ADR 0191). The dump drops and recreates each table.
     *
     * @return list<string>
     */
    public static function copy(string $container, string $source, string $target): array
    {
        return ['docker', 'exec', '-i', $container, 'bash', '-c',
            'set -eo pipefail; IFS= read -r MYSQL_PWD && export MYSQL_PWD && mysqldump --protocol=TCP --host=127.0.0.1 --user=root '
            .'--single-transaction --routines --triggers --no-tablespaces "$1" | mysql --protocol=TCP --host=127.0.0.1 --user=root "$2"',
            'bash', $source, $target];
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

    /**
     * Stops a MySQL batch before any change when `$foreign` finds a row: a name the server holds for someone else. The
     * marker line names the refusal for the Gateway. The stop statement fails on any server ("Subquery returns more
     * than 1 row"), so no table or grant a user can create lets the batch go on.
     */
    private static function mysqlGuard(string $foreign): string
    {
        return "SELECT 'database.name_conflict' FROM DUAL WHERE EXISTS ({$foreign});\n"
            ."SET @orbit_guard = IF(EXISTS ({$foreign}), 'DO (SELECT 1 UNION ALL SELECT 2)', 'DO 0');\n"
            ."PREPARE orbit_guard FROM @orbit_guard;\nEXECUTE orbit_guard;\nDEALLOCATE PREPARE orbit_guard;\n";
    }

    private function invalid(): never
    {
        throw new ResourceOperationException('database.server_admin_invalid', 'A database or user name is not a plain identifier.', 422);
    }
}
