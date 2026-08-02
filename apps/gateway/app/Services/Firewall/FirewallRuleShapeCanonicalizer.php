<?php

declare(strict_types=1);

namespace App\Services\Firewall;

/**
 * Shared endpoint and address-family canonicalization for firewall detection
 * and UFW convergence so both paths agree when a rule is equivalent.
 */
final class FirewallRuleShapeCanonicalizer
{
    /**
     * Collapse host-only CIDR forms to the bare address so intent and UFW
     * observations compare as the same rule (`10.6.0.13/32` === `10.6.0.13`).
     */
    public static function canonicalizeHostCidr(string $value): string
    {
        if ($value === 'any' || $value === '') {
            return $value;
        }

        $matches = [];

        if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3})\/32$/', $value, $matches) === 1) {
            return $matches[1];
        }

        $matches = [];

        if (
            preg_match('/^(.+)\/128$/i', $value, $matches) === 1
            && filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
        ) {
            return $matches[1];
        }

        return $value;
    }

    /**
     * Derive the concrete address family from an explicit host IP when the
     * stored intent is `both`. A v4 host can only appear as a v4 UFW rule.
     */
    public static function effectiveAddressFamily(string $addressFamily, string $source, ?string $destination): string
    {
        if ($addressFamily !== 'both') {
            return $addressFamily;
        }

        $host = $source !== 'any' ? $source : $destination ?? 'any';

        if ($host === 'any') {
            return 'both';
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return 'v4';
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return 'v6';
        }

        return 'both';
    }
}
