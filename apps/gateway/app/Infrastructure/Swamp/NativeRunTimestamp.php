<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use Carbon\CarbonImmutable;

/** Native timestamps are RFC3339 values, never relative date expressions. */
final class NativeRunTimestamp
{
    public static function parse(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]+)?(?:Z|[+-][0-9]{2}:[0-9]{2})\z/D', $value) !== 1) {
            return null;
        }
        $parsed = date_parse($value);

        return $parsed['error_count'] === 0 && $parsed['warning_count'] === 0 ? CarbonImmutable::parse($value) : null;
    }
}
