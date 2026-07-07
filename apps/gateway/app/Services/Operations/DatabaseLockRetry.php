<?php

declare(strict_types=1);

namespace App\Services\Operations;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DatabaseLockRetry
{
    private const int DATABASE_LOCK_RETRY_ATTEMPTS = 10;

    private const int DATABASE_LOCK_RETRY_DELAY_MICROSECONDS = 100_000;

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function transaction(Closure $callback): mixed
    {
        for ($attempt = 1; $attempt <= self::DATABASE_LOCK_RETRY_ATTEMPTS; $attempt++) {
            try {
                return $this->runTransaction($callback);
            } catch (QueryException $exception) {
                if (! $this->causedByDatabaseLock($exception) || $attempt === self::DATABASE_LOCK_RETRY_ATTEMPTS) {
                    throw $exception;
                }

                $this->beforeDatabaseLockRetry($exception, $attempt);
            }
        }

        throw new RuntimeException('Database lock transaction retry attempts were exhausted.');
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    protected function runTransaction(Closure $callback): mixed
    {
        /** @var TReturn */
        return DB::transaction($callback);
    }

    protected function beforeDatabaseLockRetry(QueryException $exception, int $attempt): void
    {
        usleep(max(0, self::DATABASE_LOCK_RETRY_DELAY_MICROSECONDS * $attempt));
    }

    private function causedByDatabaseLock(QueryException $exception): bool
    {
        $driverCode = (string) ($exception->errorInfo[1] ?? $exception->getCode());
        $message = strtolower($exception->getMessage());

        return (
            in_array($driverCode, ['5', '6'], strict: true)
            || str_contains($message, 'database is locked')
            || str_contains($message, 'database table is locked')
            || str_contains($message, 'database schema is locked')
            || str_contains($message, 'database is busy')
        );
    }
}
