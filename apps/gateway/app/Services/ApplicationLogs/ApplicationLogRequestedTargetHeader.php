<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

use Illuminate\Http\Request;

/**
 * Reads safe CLI host/selector values from the application-log requested-target header.
 */
final readonly class ApplicationLogRequestedTargetHeader
{
    public const string Name = 'X-Orbit-Application-Log-Requested-Target';

    public static function from(Request $request): ?string
    {
        $value = $request->headers->get(self::Name);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (
            str_contains($value, '://')
            || str_contains($value, '/')
            || str_contains($value, '\\')
            || str_contains($value, '@')
            || str_contains($value, '?')
            || str_contains($value, '#')
            || str_contains($value, ' ')
            || preg_match('/:\d+$/', $value) === 1
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9.-]*\z/', $value) !== 1
        ) {
            return null;
        }

        return mb_strtolower($value);
    }
}
