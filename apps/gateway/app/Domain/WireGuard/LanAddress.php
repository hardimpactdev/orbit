<?php

declare(strict_types=1);

namespace App\Domain\WireGuard;

/** The recorded-LAN rule: two recorded `lan_ip` values share a private network when both are IPv4 in one /24 (ADR 0233, ADR 0251). */
final class LanAddress
{
    /** RFC 1918: the networks the hub keeps on its main table, and the only ones a hub LAN endpoint may use (ADR 0251). */
    public const array PrivateRanges = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];

    public static function isPrivate(?string $address): bool
    {
        if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }
        foreach (self::PrivateRanges as $range) {
            if (Ipv4Subnet::from($range)->contains($address)) {
                return true;
            }
        }

        return false;
    }

    public static function sameNetwork(?string $first, ?string $second): bool
    {
        return is_string($first) && is_string($second)
            && filter_var($first, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && filter_var($second, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && self::network($first) === self::network($second);
    }

    /** The /24 of an IPv4 LAN address, such as `10.0.0.0/24` for `10.0.0.2`. */
    public static function network(string $address): string
    {
        return long2ip(ip2long($address) & 0xFFFFFF00).'/24';
    }
}
