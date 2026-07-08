<?php

declare(strict_types=1);

namespace App\Services\Operations;

final readonly class LocalFleetUpdateInstallCliPayloadField
{
    private const string SHA_256_PATTERN = '/\A[a-f0-9]{64}\z/i';

    private const string URL_PATTERN = '/\A(?:https?|file):\/\/[^\s]+\z/';

    public static function url(mixed $value, string $field): string
    {
        return self::stringMatching($value, self::URL_PATTERN, $field);
    }

    public static function sha256(mixed $value): string
    {
        return self::stringMatching($value, self::SHA_256_PATTERN, 'sha256');
    }

    public static function absolutePath(mixed $value, string $field): string
    {
        if (
            is_string($value)
            && $value !== ''
            && str_starts_with($value, '/')
            && ! str_contains($value, "\0")
            && ! str_contains($value, "\n")
            && ! str_contains($value, "\r")
        ) {
            return $value;
        }

        throw self::validationFailure($field);
    }

    public static function optionalAbsolutePath(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::absolutePath($value, $field);
    }

    private static function stringMatching(mixed $value, string $pattern, string $field): string
    {
        if (is_string($value) && preg_match($pattern, $value) === 1) {
            return $value;
        }

        throw self::validationFailure($field);
    }

    public static function validationFailure(string $field): LocalFleetUpdateInstallCliFailure
    {
        return new LocalFleetUpdateInstallCliFailure(
            errorCode: 'validation_failed',
            message: 'Fleet update CLI install payload is invalid.',
            meta: ['field' => $field],
        );
    }
}
