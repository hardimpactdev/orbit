<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Shared\ResourceOperationException;
use SensitiveParameter;

/**
 * Renders the SQL that Orbit runs as root on a Database server. Every name is validated against a
 * narrow identifier pattern before it is quoted, and every password must be a single line.
 */
final readonly class MysqlStatements
{
    public const string USERNAME_PATTERN = '/\A[A-Za-z_][A-Za-z0-9_]{0,31}\z/D';

    private const string DATABASE_NAME_PATTERN = '/\A[A-Za-z0-9_]{1,64}\z/D';

    private const string USER_NAME_PATTERN = '/\A[A-Za-z0-9_]{1,32}\z/D';

    public function forDriver(DatabaseDriver $driver): self|PostgresStatements
    {
        return $driver === DatabaseDriver::Pgsql ? new PostgresStatements : $this;
    }

    public function ensureUser(string $username, #[SensitiveParameter] string $password): string
    {
        $user = $this->account($username);
        $secret = $this->password($password);

        return $this->lines([
            "CREATE USER IF NOT EXISTS {$user} IDENTIFIED BY {$secret};",
            "ALTER USER {$user} IDENTIFIED BY {$secret};",
        ]);
    }

    public function createDatabase(string $database): string
    {
        return $this->lines(['CREATE DATABASE '.$this->database($database).';']);
    }

    public function grantAll(string $database, string $username): string
    {
        return $this->lines([
            'GRANT ALL PRIVILEGES ON '.$this->database($database).'.* TO '.$this->account($username).';',
        ]);
    }

    /** Grant every privilege on each database whose name starts with `$prefix`. */
    public function grantAllWithPrefix(string $prefix, string $username): string
    {
        return $this->lines([
            'GRANT ALL PRIVILEGES ON '.$this->databasePattern($prefix).'.* TO '.$this->account($username).';',
        ]);
    }

    /** Replace the user's privileges on one database. The first grant makes the revoke safe for a new user. */
    public function replaceGrant(string $database, string $username, bool $readOnly, ?string $owner = null): string
    {
        $quoted = $this->database($database);
        $user = $this->account($username);
        $privileges = $readOnly ? 'SELECT' : 'ALL PRIVILEGES';

        return $this->lines([
            "GRANT SELECT ON {$quoted}.* TO {$user};",
            "REVOKE ALL PRIVILEGES ON {$quoted}.* FROM {$user};",
            "GRANT {$privileges} ON {$quoted}.* TO {$user};",
        ]);
    }

    /**
     * @param  list<string>  $databases
     * @param  list<string>  $usernames
     */
    public function removalBlockers(array $databases, array $usernames): string
    {
        $names = implode(', ', array_map($this->string(...), $databases)) ?: 'NULL';
        $definers = implode(', ', array_map(fn (string $username): string => $this->string($username.'@%'), $usernames)) ?: 'NULL';

        return <<<SQL
            SELECT DISTINCT blocker FROM (
                SELECT DB AS blocker FROM information_schema.PROCESSLIST WHERE DB IN ({$names}) AND ID <> CONNECTION_ID()
                UNION ALL
                SELECT locks.OBJECT_SCHEMA FROM performance_schema.metadata_locks locks
                LEFT JOIN performance_schema.threads threads ON threads.THREAD_ID = locks.OWNER_THREAD_ID
                WHERE locks.OBJECT_SCHEMA IN ({$names}) AND (threads.PROCESSLIST_ID IS NULL OR threads.PROCESSLIST_ID <> CONNECTION_ID())
                UNION ALL
                SELECT TABLE_SCHEMA FROM information_schema.VIEWS WHERE DEFINER IN ({$definers}) AND TABLE_SCHEMA NOT IN ({$names})
                UNION ALL
                SELECT ROUTINE_SCHEMA FROM information_schema.ROUTINES WHERE DEFINER IN ({$definers}) AND ROUTINE_SCHEMA NOT IN ({$names})
                UNION ALL
                SELECT TRIGGER_SCHEMA FROM information_schema.TRIGGERS WHERE DEFINER IN ({$definers}) AND TRIGGER_SCHEMA NOT IN ({$names})
                UNION ALL
                SELECT EVENT_SCHEMA FROM information_schema.EVENTS WHERE DEFINER IN ({$definers}) AND EVENT_SCHEMA NOT IN ({$names})
                UNION ALL
                SELECT TABLE_SCHEMA FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA IN ({$names}) AND TABLE_SCHEMA <> REFERENCED_TABLE_SCHEMA
            ) blockers ORDER BY blocker;

            SQL;
    }

    public function dropDatabase(string $database): string
    {
        return $this->lines(['DROP DATABASE IF EXISTS '.$this->database($database).';']);
    }

    public function dropUser(string $username): string
    {
        return $this->lines(['DROP USER IF EXISTS '.$this->account($username).';']);
    }

    /** List the existing databases named `$database`, plus those that start with `$prefix` when given. */
    public function databasesNamed(string $database, ?string $prefix = null): string
    {
        $condition = 'SCHEMA_NAME = '.$this->string($this->checkedDatabase($database));

        if ($prefix !== null) {
            $condition .= ' OR SCHEMA_NAME LIKE '.$this->string($this->likePrefix($prefix));
        }

        return $this->lines([
            "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE {$condition} ORDER BY SCHEMA_NAME;",
        ]);
    }

    /** Read-only ownership proof for an explicit operator adoption. */
    public function ownershipProof(string $database, string $username, ?string $prefix): string
    {
        $this->checkedDatabase($database);
        $this->checkedUser($username);
        $user = $this->string($username);
        $db = $this->string($database);
        $privileges = ['Select', 'Insert', 'Update', 'Delete', 'Create', 'Drop', 'References', 'Index', 'Alter',
            'Create_tmp_table', 'Lock_tables', 'Create_view', 'Show_view', 'Create_routine', 'Alter_routine', 'Execute', 'Event', 'Trigger'];
        $all = implode(' AND ', array_map(static fn (string $name): string => $name."_priv = 'Y'", $privileges));
        $grant = fn (string $scope): string => "EXISTS (SELECT 1 FROM mysql.db WHERE BINARY User = BINARY {$user} AND Host = '%' AND BINARY Db = BINARY ".$this->string($scope)." AND {$all})";

        return "SELECT 'owned' WHERE EXISTS (SELECT 1 FROM information_schema.SCHEMATA WHERE BINARY SCHEMA_NAME = BINARY {$db})"
            ." AND EXISTS (SELECT 1 FROM mysql.user WHERE BINARY User = BINARY {$user} AND Host = '%')"
            .' AND '.$grant($database).($prefix === null ? '' : ' AND '.$grant($this->likePrefix($prefix))).";\n";
    }

    public function userNamed(string $username): string
    {
        return $this->lines([
            'SELECT User FROM mysql.user WHERE User = '.$this->string($this->checkedUser($username)).';',
        ]);
    }

    /** The privilege text that Orbit records for a user, for example ALL PRIVILEGES ON `app`.*. */
    public function privilegeText(string $database, bool $readOnly = false, ?string $prefix = null): string
    {
        $text = ($readOnly ? 'SELECT' : 'ALL PRIVILEGES').' ON '.$this->database($database).'.*';

        if ($prefix !== null) {
            $text .= ', '.$this->databasePattern($prefix).'.*';
        }

        return $text;
    }

    public function likePrefix(string $prefix): string
    {
        return str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $this->checkedDatabase($prefix)).'%';
    }

    /** @return array{} */
    public function __debugInfo(): array
    {
        return [];
    }

    private function database(string $database): string
    {
        return '`'.$this->checkedDatabase($database).'`';
    }

    private function databasePattern(string $prefix): string
    {
        return '`'.$this->likePrefix($prefix).'`';
    }

    private function account(string $username): string
    {
        return $this->string($this->checkedUser($username))."@'%'";
    }

    private function password(#[SensitiveParameter] string $password): string
    {
        if ($password === '' || preg_match('/[\x00\r\n]/', $password) === 1) {
            throw new ResourceOperationException(
                errorCode: 'validation.failed',
                message: 'A MySQL password must be a non-empty single line.',
                status: 422,
            );
        }

        return $this->string($password);
    }

    private function checkedDatabase(string $database): string
    {
        if (preg_match(self::DATABASE_NAME_PATTERN, $database) !== 1) {
            throw new ResourceOperationException(
                errorCode: 'validation.failed',
                message: 'A MySQL database name must be 1-64 letters, digits, or underscores.',
                status: 422,
            );
        }

        return $database;
    }

    private function checkedUser(string $username): string
    {
        if (preg_match(self::USER_NAME_PATTERN, $username) !== 1) {
            throw new ResourceOperationException(
                errorCode: 'validation.failed',
                message: 'A MySQL user name must be 1-32 letters, digits, or underscores.',
                status: 422,
            );
        }

        return $username;
    }

    public function string(#[SensitiveParameter] string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }

    /** @param list<string> $lines */
    private function lines(#[SensitiveParameter] array $lines): string
    {
        return implode("\n", $lines)."\n";
    }
}
