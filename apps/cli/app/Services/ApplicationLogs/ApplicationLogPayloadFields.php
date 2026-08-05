<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

final readonly class ApplicationLogPayloadFields
{
    public static function absolutePath(mixed $value): string
    {
        return self::requiredAbsolutePath($value, 'absolute_path');
    }

    public static function authorizedRoot(mixed $value): string
    {
        return self::requiredAbsolutePath($value, 'authorized_root');
    }

    public static function lines(mixed $value): int
    {
        $parsed = ApplicationLogFlags::parsePositiveIntegerLines($value);

        if ($parsed === null) {
            throw new LocalApplicationLogFailure(
                errorCode: 'validation_failed',
                message: 'Application log line count is invalid.',
                meta: ['field' => 'lines'],
            );
        }

        return $parsed;
    }

    public static function follow(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        throw new LocalApplicationLogFailure(
            errorCode: 'validation_failed',
            message: 'Application log follow value is invalid.',
            meta: ['field' => 'follow'],
        );
    }

    private static function requiredAbsolutePath(mixed $value, string $field): string
    {
        if (! is_string($value) || trim($value) === '' || ! str_starts_with(trim($value), '/')) {
            throw new LocalApplicationLogFailure(
                errorCode: 'validation_failed',
                message: 'Application log path is invalid.',
                meta: ['field' => $field],
            );
        }

        return rtrim(trim($value), characters: '/');
    }
}
