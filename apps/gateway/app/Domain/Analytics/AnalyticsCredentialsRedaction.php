<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Activity\ActivityInputRedaction;
use Closure;

/** The entire credential body is write-only, including the management identity. */
final readonly class AnalyticsCredentialsRedaction implements ActivityInputRedaction
{
    public function commands(): array
    {
        return ['analytics:credentials:set'];
    }

    public function input(string $command, Closure $input): array
    {
        return [];
    }
}
