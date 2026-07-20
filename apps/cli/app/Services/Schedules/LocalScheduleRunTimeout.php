<?php

declare(strict_types=1);

namespace App\Services\Schedules;

use InvalidArgumentException;

final readonly class LocalScheduleRunTimeout
{
    public static function from(mixed $value): int
    {
        if (is_int($value) && $value >= 1 && $value <= 86_400) {
            return $value;
        }

        throw new InvalidArgumentException('Schedule timeout is invalid.');
    }
}
