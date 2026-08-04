<?php

declare(strict_types=1);

namespace App\Services\Processes;

final class SystemProcessStreamClock implements ProcessStreamClock
{
    public function now(): float
    {
        return microtime(true);
    }
}
