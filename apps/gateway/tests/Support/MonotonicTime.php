<?php

declare(strict_types=1);

namespace Tests\Support;

/** A monotonic nanosecond clock whose microsecond sleeps advance time without waiting. */
final class MonotonicTime
{
    private float $nanoseconds = 0;

    public function now(): float
    {
        return $this->nanoseconds;
    }

    public function sleep(int $microseconds): void
    {
        $this->nanoseconds += $microseconds * 1_000;
    }
}
