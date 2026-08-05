<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

/**
 * Safe CLI-provided selector or hostname for application-log activity metadata.
 *
 * Transported as header {@see self::Header}. Never credentials, paths, or content.
 */
final readonly class ApplicationLogRequestedTarget
{
    public const string Header = 'X-Orbit-Application-Log-Requested-Target';

    /**
     * @return array<string, string>
     */
    public static function headers(?string $value): array
    {
        $safe = self::sanitize($value);

        return $safe === null ? [] : [self::Header => $safe];
    }

    public static function sanitize(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        // Reject credentials, paths, queries, fragments, ports, and whitespace.
        if (
            str_contains($value, '://')
            || str_contains($value, '/')
            || str_contains($value, '\\')
            || str_contains($value, '@')
            || str_contains($value, '?')
            || str_contains($value, '#')
            || str_contains($value, ' ')
            || preg_match('/:\d+$/', $value) === 1
        ) {
            return null;
        }

        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9.-]*\z/', $value) !== 1) {
            return null;
        }

        return mb_strtolower($value);
    }
}
