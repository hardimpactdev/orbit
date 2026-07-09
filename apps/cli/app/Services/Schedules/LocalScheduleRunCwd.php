<?php

declare(strict_types=1);

namespace App\Services\Schedules;

use InvalidArgumentException;

final readonly class LocalScheduleRunCwd
{
    public static function from(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || ! str_starts_with($value, '/')) {
            throw new InvalidArgumentException('Schedule cwd is invalid.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1 || preg_match('#(?:^|/)\.\.(?:/|$)#', $value) === 1) {
            throw new InvalidArgumentException('Schedule cwd is invalid.');
        }

        return $value;
    }
}
