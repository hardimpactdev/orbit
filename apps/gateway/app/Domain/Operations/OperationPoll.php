<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\Operation;
use Illuminate\Support\Carbon;

/**
 * How long a polling client waits before it reads an operation again: 2 seconds while queued and in the first
 * 10 seconds of the run, then one more second for each 10 seconds of run time, at most 15. Null once terminal.
 */
final class OperationPoll
{
    public const int MinimumSeconds = 2;

    public const int MaximumSeconds = 15;

    public static function afterSeconds(Operation $operation): ?int
    {
        if ($operation->status->terminal()) {
            return null;
        }

        if ($operation->started_at === null) {
            return self::MinimumSeconds;
        }

        $elapsed = max(0, (int) $operation->started_at->diffInSeconds(Carbon::now()));

        return min(self::MaximumSeconds, self::MinimumSeconds + intdiv($elapsed, 10));
    }
}
