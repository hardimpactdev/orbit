<?php

declare(strict_types=1);

namespace App\Services\Schedules;

use InvalidArgumentException;

final readonly class LocalScheduleRunExecutionType
{
    public static function from(mixed $value): string
    {
        if ($value === 'command' || $value === 'script') {
            return $value;
        }

        throw new InvalidArgumentException('Schedule execution type is invalid.');
    }
}
