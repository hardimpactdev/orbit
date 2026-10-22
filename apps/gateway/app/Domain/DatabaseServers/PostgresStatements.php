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

    public function createDatabase(string $database, ?string $locale = null): string
    {
        if ($locale !== null && ! in_array($locale, ['C', 'C.UTF-8'], true)) {
            throw new ResourceOperationException('validation.failed', 'PostgreSQL builtin locale must be C or C.UTF-8.', 422);
        }

        return 'CREATE DATABASE '.$this->identifier($database)
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
