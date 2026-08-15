<?php

declare(strict_types=1);

namespace Orbit\Core\Database;

use Closure;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final readonly class DatabaseReadOnlyGuard
{
    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function run(PDO $database, string $driver, Closure $callback): mixed
    {
        return match ($driver) {
            'mysql', 'pgsql' => $this->runInReadOnlyTransaction($database, $callback),
            'sqlite' => $this->runWithSqliteQueryOnly($database, $callback),
            default => throw new InvalidArgumentException("Unsupported database driver [{$driver}]."),
        };
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function runInReadOnlyTransaction(PDO $database, Closure $callback): mixed
    {
        $database->exec('start transaction read only');

        try {
            return $callback();
        } finally {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
        }
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function runWithSqliteQueryOnly(PDO $database, Closure $callback): mixed
    {
        $queryOnlyStatement = $database->query('pragma query_only');

        if ($queryOnlyStatement === false) {
            throw new RuntimeException('Unable to read the SQLite query-only state.');
        }

        $queryOnlyWasEnabled = (int) $queryOnlyStatement->fetchColumn() === 1;
        $database->exec('pragma query_only = on');

        try {
            return $callback();
        } finally {
            $database->exec('pragma query_only = '.($queryOnlyWasEnabled ? 'on' : 'off'));
        }
    }
}
