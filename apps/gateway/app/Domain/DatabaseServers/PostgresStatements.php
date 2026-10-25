<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\Shared\ResourceOperationException;
use SensitiveParameter;

/** PostgreSQL SQL and psql commands. Passwords reach the server only as SCRAM verifiers. */
final readonly class PostgresStatements
{
    public function ensureUser(string $username, #[SensitiveParameter] string $password, bool $replication = false): string
    {
        $name = $this->identifier($username);
        $literal = $this->literal($username);
        $attribute = $replication ? 'REPLICATION' : 'NOREPLICATION';
        if ($password === '' || preg_match('/[^\x20-\x7e]/', $password) === 1) {
            throw new ResourceOperationException('validation.failed', 'A PostgreSQL password must contain printable ASCII characters.', 422);
        }
        $salt = random_bytes(16);
        $salted = hash_pbkdf2('sha256', $password, $salt, 4096, 32, true);
        $clientKey = hash_hmac('sha256', 'Client Key', $salted, true);
        $storedKey = hash('sha256', $clientKey, true);
        $serverKey = hash_hmac('sha256', 'Server Key', $salted, true);
        $verifier = 'SCRAM-SHA-256$4096:'.base64_encode($salt).'$'.base64_encode($storedKey).':'.base64_encode($serverKey);

        return "DO \$orbit\$ BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = {$literal}) THEN CREATE ROLE {$name} LOGIN; END IF; END \$orbit\$;\n"
            ."ALTER ROLE {$name} LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE {$attribute} PASSWORD ".$this->literal($verifier).";\n";
    }

    public function createDatabase(string $database, ?string $locale = null, ?string $owner = null): string
    {
        if ($locale !== null && ! in_array($locale, ['C', 'C.UTF-8'], true)) {
            throw new ResourceOperationException('validation.failed', 'PostgreSQL builtin locale must be C or C.UTF-8.', 422);
        }

        return 'CREATE DATABASE '.$this->identifier($database)
            .($owner === null ? '' : ' OWNER '.$this->identifier($owner))
            .($locale === null ? '' : " TEMPLATE template0 ENCODING 'UTF8' LOCALE_PROVIDER builtin BUILTIN_LOCALE ".$this->literal($locale)).";\n";
    }

    /** Ownership is explicit; only this role's objects receive the default grants. */
    public function grantAll(string $database, string $username): string
    {
        $db = $this->identifier($database);
        $user = $this->identifier($username);

        return "ALTER DATABASE {$db} OWNER TO {$user};\nREVOKE ALL ON DATABASE {$db} FROM PUBLIC;\n"
            .$this->connect($database)."REVOKE CREATE ON SCHEMA public FROM PUBLIC;\n"
            ."GRANT ALL ON SCHEMA public TO {$user};\n\\connect postgres\n";
    }

    public function replaceGrant(string $database, string $username, bool $readOnly, ?string $owner = null): string
    {
        $db = $this->identifier($database);
        $user = $this->identifier($username);
        $ownerName = $this->identifier($owner ?? throw new ResourceOperationException('database.server_required', 'The database owner is missing.', 422));
        $tables = $readOnly ? 'SELECT' : 'SELECT, INSERT, UPDATE, DELETE, TRUNCATE, REFERENCES, TRIGGER';
        $sequences = $readOnly ? 'SELECT' : 'USAGE, SELECT, UPDATE';

        return "REVOKE ALL ON DATABASE {$db} FROM {$user};\nGRANT CONNECT ON DATABASE {$db} TO {$user};\n"
            .$this->connect($database)
            ."REVOKE ALL ON SCHEMA public FROM {$user};\nGRANT USAGE ON SCHEMA public TO {$user};\n"
            ."REVOKE ALL ON ALL TABLES IN SCHEMA public FROM {$user};\nREVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM {$user};\n"
            ."ALTER DEFAULT PRIVILEGES FOR ROLE {$ownerName} IN SCHEMA public REVOKE ALL ON TABLES FROM {$user};\n"
            ."ALTER DEFAULT PRIVILEGES FOR ROLE {$ownerName} IN SCHEMA public REVOKE ALL ON SEQUENCES FROM {$user};\n"
            ."GRANT {$tables} ON ALL TABLES IN SCHEMA public TO {$user};\nGRANT {$sequences} ON ALL SEQUENCES IN SCHEMA public TO {$user};\n"
            ."ALTER DEFAULT PRIVILEGES FOR ROLE {$ownerName} IN SCHEMA public GRANT {$tables} ON TABLES TO {$user};\n"
            ."ALTER DEFAULT PRIVILEGES FOR ROLE {$ownerName} IN SCHEMA public GRANT {$sequences} ON SEQUENCES TO {$user};\n\\connect postgres\n";
    }

    /**
     * @param  list<string>  $databases
     * @param  list<string>  $usernames
     */
    public function removalBlockers(array $databases, array $usernames): string
    {
        $names = implode(', ', array_map($this->literal(...), $databases)) ?: 'NULL';
        $roles = implode(', ', array_map($this->literal(...), $usernames)) ?: 'NULL';

        return <<<SQL
            SELECT DISTINCT blocker FROM (
                SELECT datname::text AS blocker FROM pg_stat_activity WHERE datname IN ({$names})
                UNION ALL
                SELECT database::text FROM pg_replication_slots WHERE database IN ({$names})
                UNION ALL
                SELECT database::text FROM pg_prepared_xacts WHERE database IN ({$names})
                UNION ALL
                SELECT d.datname::text FROM pg_subscription s JOIN pg_database d ON d.oid = s.subdbid WHERE d.datname IN ({$names})
                UNION ALL
                SELECT COALESCE(d.datname::text, owned.datname::text, r.rolname::text)
                FROM pg_shdepend dep
                JOIN pg_roles r ON dep.refclassid = 'pg_authid'::regclass AND dep.refobjid = r.oid
                LEFT JOIN pg_database d ON d.oid = dep.dbid
                LEFT JOIN pg_database owned ON dep.dbid = 0 AND dep.classid = 'pg_database'::regclass AND dep.objid = owned.oid
                WHERE r.rolname IN ({$roles})
                  AND (dep.dbid <> 0 AND d.datname NOT IN ({$names})
                       OR dep.dbid = 0 AND (owned.oid IS NULL OR owned.datname NOT IN ({$names})))
            ) blockers ORDER BY blocker;

            SQL;
    }

    /** Journal and close admission together, preserving the first snapshot across retries.
     * @param  list<string>  $databases
     */
    public function fenceDatabases(array $databases): string
    {
        foreach ($databases as $database) {
            $this->identifier($database);
        }
        $names = implode(', ', array_map($this->literal(...), $databases)) ?: 'NULL';

        return <<<SQL
            BEGIN;
            DO \$lock\$ BEGIN PERFORM pg_advisory_xact_lock(hashtextextended('orbit.database-removal', 0)); END \$lock\$;
            CREATE SCHEMA IF NOT EXISTS orbit_admin;
            REVOKE ALL ON SCHEMA orbit_admin FROM PUBLIC;
            CREATE TABLE IF NOT EXISTS orbit_admin.database_removal_fences (
                database_name text PRIMARY KEY,
                database_oid oid NOT NULL,
                allow_connections boolean NOT NULL
            );
            REVOKE ALL ON orbit_admin.database_removal_fences FROM PUBLIC;
            LOCK TABLE orbit_admin.database_removal_fences IN EXCLUSIVE MODE;
            INSERT INTO orbit_admin.database_removal_fences
                SELECT datname, oid, datallowconn FROM pg_database WHERE datname IN ({$names})
                ON CONFLICT (database_name) DO NOTHING;
            DO \$orbit\$
            DECLARE item record;
            BEGIN
                FOR item IN
                    SELECT f.*, d.oid AS current_oid FROM orbit_admin.database_removal_fences f
                    JOIN pg_database d ON d.datname = f.database_name WHERE f.database_name IN ({$names})
                    ORDER BY f.database_name
                LOOP
                    IF item.database_oid <> item.current_oid THEN
                        RAISE EXCEPTION 'Database identity changed during removal';
                    END IF;
                    EXECUTE format('ALTER DATABASE %I ALLOW_CONNECTIONS false', item.database_name);
                END LOOP;
            END \$orbit\$;
            COMMIT;

            SQL;
    }

    /** Restore surviving databases and forget completed drops in one transaction.
     * @param  list<string>  $databases
     */
    public function restoreDatabaseAdmission(array $databases): string
    {
        foreach ($databases as $database) {
            $this->identifier($database);
        }
        $names = implode(', ', array_map($this->literal(...), $databases)) ?: 'NULL';

        return <<<SQL
            DO \$orbit\$
            DECLARE item record;
            BEGIN
                PERFORM pg_advisory_xact_lock(hashtextextended('orbit.database-removal', 0));
                IF to_regclass('orbit_admin.database_removal_fences') IS NULL THEN
                    RETURN;
                END IF;
                LOCK TABLE orbit_admin.database_removal_fences IN EXCLUSIVE MODE;
                FOR item IN
                    SELECT f.*, d.oid AS current_oid FROM orbit_admin.database_removal_fences f
                    LEFT JOIN pg_database d ON d.datname = f.database_name WHERE f.database_name IN ({$names})
                    ORDER BY f.database_name
                LOOP
                    IF item.current_oid IS NOT NULL THEN
                        IF item.database_oid <> item.current_oid THEN
                            RAISE EXCEPTION 'Database identity changed during removal';
                        END IF;
                        EXECUTE format('ALTER DATABASE %I ALLOW_CONNECTIONS %s', item.database_name, CASE WHEN item.allow_connections THEN 'true' ELSE 'false' END);
                    END IF;
                END LOOP;
                DELETE FROM orbit_admin.database_removal_fences WHERE database_name IN ({$names});
            END \$orbit\$;

            SQL;
    }

    /** Hold a session lock and admission fence through the final check and every drop.
     * @param  list<string>  $databases
     * @param  list<string>  $users
     */
    public function dropFencedDatabases(array $databases, array $users): string
    {
        $blockers = rtrim(trim($this->removalBlockers($databases, $users)), ';');
        $drops = '';
        foreach ($databases as $database) {
            $drops .= $this->dropDatabase($database);
        }
        foreach ($users as $user) {
            $drops .= $this->dropUser($user);
        }

        return "DO \$lock\$ BEGIN PERFORM pg_advisory_lock(hashtextextended('orbit.database-removal', 0)); END \$lock\$;\n"
            .$this->fenceDatabases($databases)
            ."WITH blockers AS ({$blockers}) SELECT EXISTS (SELECT FROM blockers) AS blocked\n\\gset orbit_\n"
            ."\\if :orbit_blocked\n{$blockers};\n\\else\n{$drops}\\endif\n"
            .$this->restoreDatabaseAdmission($databases);
    }

    public function dropDatabase(string $database): string
    {
        return 'DROP DATABASE IF EXISTS '.$this->identifier($database).";\n";
    }

    public function dropUser(string $username): string
    {
        return 'DROP ROLE IF EXISTS '.$this->identifier($username).";\n";
    }

    public function databasesNamed(string $database, ?string $prefix = null): string
    {
        $this->identifier($database);

        return 'SELECT datname FROM pg_database WHERE datname = '.$this->literal($database).";\n";
    }

    /** @param non-empty-list<string> $databases */
    public function databasesIn(array $databases): string
    {
        foreach ($databases as $database) {
            $this->identifier($database);
        }

        return 'SELECT datname FROM pg_database WHERE datname IN ('.implode(', ', array_map($this->literal(...), $databases)).");\n";
    }

    /** @param non-empty-list<string> $databases */
    public function databasesNotOwnedBy(array $databases, string $username): string
    {
        return rtrim(trim($this->databasesIn($databases)), ';')
            .' AND pg_get_userbyid(datdba) <> '.$this->literal($username).";\n";
    }

    public function userNamed(string $username): string
    {
        $this->identifier($username);

        return 'SELECT rolname FROM pg_roles WHERE rolname = '.$this->literal($username).";\n";
    }

    public function replicationAttribute(string $username): string
    {
        $this->identifier($username);

        return 'SELECT rolreplication::text FROM pg_roles WHERE rolname = '.$this->literal($username).";\n";
    }

    public function privilegeText(string $database, bool $readOnly = false, ?string $prefix = null): string
    {
        return ($readOnly ? 'CONNECT, USAGE, SELECT' : 'DATA WRITE').' ON '.$this->identifier($database);
    }

    private function connect(string $database): string
    {
        $this->identifier($database);

        return "\\connect {$database}\n";
    }

    private function identifier(string $name): string
    {
        if (preg_match('/\A[A-Za-z0-9_]{1,63}\z/D', $name) !== 1) {
            throw new ResourceOperationException('validation.failed', 'PostgreSQL names must be 1-63 letters, digits, or underscores.', 422);
        }

        return '"'.$name.'"';
    }

    private function literal(#[SensitiveParameter] string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
