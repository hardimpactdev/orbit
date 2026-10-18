<?php

declare(strict_types=1);

namespace App\Domain\Extensions;

/** Scalar field checks, not template escaping. The boundary owns the error and the field's grammar. */
final readonly class ConfigValue
{
    /** One non-empty printable ASCII line, with no leading or trailing space. */
    public static function printableLine(string $value): bool
    {
        return preg_match('/\A[!-~](?:[ -~]*[!-~])?\z/D', $value) === 1;
    }

    public static function ipv4(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }
}
