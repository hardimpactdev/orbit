<?php

declare(strict_types=1);

namespace App\Services\Processes;

/**
 * Injectable sleep for process SSE follow loops (production usleep; tests no-op or controlled).
 */
interface ProcessStreamSleeper
{
    public function sleep(int $microseconds): void;
}
