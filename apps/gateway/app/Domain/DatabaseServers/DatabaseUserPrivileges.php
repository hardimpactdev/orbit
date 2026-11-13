<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Shared\ResourceOperationException;

/** Public grant text stored with a user; Swamp owns SQL policy and execution. */
final readonly class DatabaseUserPrivileges
{
    public const string USERNAME_PATTERN = '/\A[A-Za-z_][A-Za-z0-9_]{0,31}\z/D';

    /**
     * Whether the engine keeps the name for itself: the admin login Orbit runs as, or a system role. Orbit never makes
     * or changes such a login for a connection. MySQL's other system accounts (`mysql.sys`, `mysql.session`) contain a
     * dot, which {@see USERNAME_PATTERN} refuses already. Names compare without case, so no near-copy passes either.
     */
    public function reserved(DatabaseDriver $driver, string $username): bool
    {
        $name = strtolower($username);

        return match ($driver) {
            DatabaseDriver::Mysql => in_array($name, ['root', 'mysql', 'sys', 'public', 'mariadb'], true),
            DatabaseDriver::Pgsql => in_array($name, ['postgres', 'public', 'none', 'current_user', 'session_user', 'current_role', 'system_user'], true)
                || str_starts_with($name, 'pg_'),
            default => false,
        };
    }

    public function reservedError(string $username): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'database.user_reserved',
            message: "User [{$username}] is the server's admin login or a system role. Orbit never makes or changes it.",
            status: 422,
        );
    }

    public function text(DatabaseDriver $driver, string $database, bool $readOnly = false, ?string $prefix = null): string
    {
        $limit = $driver === DatabaseDriver::Pgsql ? 63 : 64;
        foreach (array_filter([$database, $prefix], static fn (?string $name): bool => $name !== null) as $name) {
            if (preg_match('/\A[A-Za-z0-9_]{1,'.$limit.'}\z/D', $name) !== 1) {
                throw new ResourceOperationException('validation.failed', 'The database name is invalid.', 422);
            }
        }
        if ($driver === DatabaseDriver::Pgsql) {
            return ($readOnly ? 'CONNECT, USAGE, SELECT' : 'DATA WRITE').' ON "'.$database.'"';
        }
        $text = ($readOnly ? 'SELECT' : 'ALL PRIVILEGES').' ON `'.$database.'`.*';

        return $text.($prefix === null ? '' : ', `'.str_replace('_', '\\_', $prefix).'%`.*');
    }
}
