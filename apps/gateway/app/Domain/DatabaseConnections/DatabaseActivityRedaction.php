<?php

declare(strict_types=1);

namespace App\Domain\DatabaseConnections;

use App\Domain\Activity\ActivityInputRedaction;
use Closure;

/** SQL can contain credentials even when its field name is not secret. */
final readonly class DatabaseActivityRedaction implements ActivityInputRedaction
{
    public function commands(): array
    {
        return ['database:query'];
    }

    public function input(string $command, Closure $input): array
    {
        return [];
    }
}
