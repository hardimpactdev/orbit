<?php

declare(strict_types=1);

namespace App\Services\Processes;

/**
 * Injectable time source for process SSE follow loops (production microtime; tests advance deterministically).
 */
interface ProcessStreamClock
{
    /**
     * Unix time in seconds with fractional microsecond precision (same shape as microtime(true)).
     */
    public function now(): float;
}
