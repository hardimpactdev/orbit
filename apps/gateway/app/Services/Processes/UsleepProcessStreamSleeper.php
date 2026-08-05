<?php

declare(strict_types=1);

namespace App\Services\Processes;

final class UsleepProcessStreamSleeper implements ProcessStreamSleeper
{
    public function sleep(int $microseconds): void
    {
        if ($microseconds > 0) {
            usleep($microseconds);
        }
    }
}
