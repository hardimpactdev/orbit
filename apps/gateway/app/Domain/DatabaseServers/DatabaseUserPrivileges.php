<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Shared\ResourceOperationException;

/** Public grant text stored with a user; Swamp owns SQL policy and execution. */
final readonly class DatabaseUserPrivileges
{
    public const string USERNAME_PATTERN = '/\A[A-Za-z_][A-Za-z0-9_]{0,31}\z/D';

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
