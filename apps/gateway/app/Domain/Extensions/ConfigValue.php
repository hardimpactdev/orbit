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

    /** A name usable as one config token or filename component, without syntax separators. */
    public static function slug(string $value): bool
    {
        return preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $value) === 1;
    }

    /** An ASCII DNS hostname, not a wildcard, URL, port, or dnsmasq record. */
    public static function dnsName(string $value): bool
    {
        $name = str_ends_with($value, '.') ? substr($value, 0, -1) : $value;

        if ($name === '' || strlen($name) > 253) {
            return false;
        }

        return array_all(explode('.', $name), static fn (string $label): bool => preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/iD', $label) === 1);
    }

    public static function ipv4(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    public static function port(int $value): bool
    {
        return $value >= 1 && $value <= 65535;
    }
}
