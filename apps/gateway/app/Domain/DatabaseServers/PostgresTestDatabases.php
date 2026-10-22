<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\Shared\ResourceOperationException;

/** Exact names shared by PostgreSQL pool creation, conflict checks, rollback and destruction. */
final readonly class PostgresTestDatabases
{
    public const int DEFAULT_PROCESSES = 8;

    public const int MAX_PROCESSES = 32;

    /** Leave room for `_test_test_32` within PostgreSQL's 63-byte identifier limit. */
    public function database(string $normalized): string
    {
        $maximum = 63 - strlen('_test_test_'.self::MAX_PROCESSES);

        return strlen($normalized) <= $maximum
            ? $normalized
            : substr($normalized, 0, $maximum - 9).'_'.substr(sha1($normalized), 0, 8);
    }

    /** @return non-empty-list<string> The serial test database followed by its worker databases. */
    public function names(string $testDatabase, int $processes): array
    {
        if ($processes < 1 || $processes > self::MAX_PROCESSES) {
            throw new ResourceOperationException('validation.failed', 'Test processes must be an integer from 1 through 32.', 422);
        }

        $names = [$testDatabase];
        for ($token = 1; $token <= $processes; $token++) {
            $names[] = $testDatabase.'_test_'.$token;
        }

        return $names;
    }
}
