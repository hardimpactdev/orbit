<?php

declare(strict_types=1);

namespace App\Services\Schedules;

use InvalidArgumentException;

final readonly class LocalScheduleRunExecutionValue
{
    public static function from(mixed $value): string
    {
        if (is_string($value) && trim($value) !== '' && ! str_contains($value, "\0")) {
            return $value;
        }

        throw new InvalidArgumentException('Schedule execution value is invalid.');
    }
}
