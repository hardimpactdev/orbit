<?php

declare(strict_types=1);

namespace App\Domain\InstanceMigration;

use Closure;
use Illuminate\Support\Carbon;

/** A migrate reconciliation slice bounds its history, artifact reads and cancel requests together. */
final class InstanceMigrateReadDeadline
{
    private static ?Carbon $deadline = null;

    /** @template T
     * @param  Closure(): T  $read
     * @return T
     */
    public static function during(Carbon $deadline, Closure $read): mixed
    {
        $previous = self::$deadline;
        self::$deadline = $deadline;
        try {
            return $read();
        } finally {
            self::$deadline = $previous;
        }
    }

    public static function remaining(float $maximum): float
    {
        return self::$deadline === null ? $maximum : max(0.0, min($maximum, (self::$deadline->getTimestampMs() - now()->getTimestampMs()) / 1000));
    }
}
