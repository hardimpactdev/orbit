<?php

declare(strict_types=1);

namespace App\Services\Analytics;

final class GlobalUnicastIpAddress
{
    /** @var list<string> */
    private const array DENIED_IPV4_RANGES = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.31.196.0/24',
        '192.52.193.0/24',
        '192.88.99.0/24',
        '192.168.0.0/16',
        '192.175.48.0/24',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
    ];

    /** @var list<string> */
    private const array DENIED_IPV6_RANGES = [
        '2001::/23',
        '2001:db8::/32',
        '2002::/16',
        '2620:4f:8000::/48',
        '3fff::/20',
    ];

    public static function isGlobalUnicast(string $value): bool
    {
        $packed = inet_pton($value);

        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 4) {
            return ! self::matchesAnyRange($packed, self::DENIED_IPV4_RANGES);
        }

        if (strlen($packed) !== 16 || ! self::matchesCidr($packed, '2000::/3')) {
            return false;
        }

        return ! self::matchesAnyRange($packed, self::DENIED_IPV6_RANGES);
    }

    /** @param list<string> $ranges */
    private static function matchesAnyRange(string $packed, array $ranges): bool
    {
        return array_any($ranges, fn (string $range): bool => self::matchesCidr($packed, $range));
    }

    private static function matchesCidr(string $packed, string $cidr): bool
    {
        [$network, $prefixValue] = explode(separator: '/', string: $cidr, limit: 2);
        $networkPacked = inet_pton($network);
        $prefix = (int) $prefixValue;

        if ($networkPacked === false || strlen($networkPacked) !== strlen($packed)) {
            return false;
        }

        $wholeBytes = intdiv(num1: $prefix, num2: 8);
        $remainingBits = $prefix % 8;

        if (
            $wholeBytes > 0
            && substr(string: $packed, offset: 0, length: $wholeBytes) !== substr(
                string: $networkPacked,
                offset: 0,
                length: $wholeBytes,
            )
        ) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xff << (8 - $remainingBits)) & 0xff;

        return (ord($packed[$wholeBytes]) & $mask) === (ord($networkPacked[$wholeBytes]) & $mask);
    }
}
