<?php

declare(strict_types=1);

namespace App\Domain\WireGuard;

/** The recorded-LAN rule: two recorded `lan_ip` values share a private network when both are IPv4 in one /24 (ADR 0233, ADR 0251). */
final class LanAddress
{
    public static function sameNetwork(?string $first, ?string $second): bool
    {
        return is_string($first) && is_string($second)
            && filter_var($first, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && filter_var($second, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && (ip2long($first) & 0xFFFFFF00) === (ip2long($second) & 0xFFFFFF00);
    }
}
