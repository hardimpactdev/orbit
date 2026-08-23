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
     * A UFW comment is the only backend identity available for a shape that
     * no longer matches. Do not infer ownership from a shared port alone.
     *
     * @param  array<string, mixed>  $observed
     */
    public static function managedCommentIdentifiesObservedRule(
        ?string $reason,
        string $name,
        array $observed,
    ): bool {
        return ($observed['comment'] ?? null) === self::managedComment($reason, $name);
    }

    public static function managedComment(?string $reason, string $name): string
    {
        return is_string($reason) && $reason !== '' ? $reason : "orbit:{$name}";
    }

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
     * Derive the concrete address family from an explicit IP or CIDR when the
     * stored intent is `both`.
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

        $address = explode(separator: '/', string: $host, limit: 2)[0];

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return 'v4';
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return 'v6';
        }

        return 'both';
    }

    /**
     * @param  array{direction: string, action: string, source: string, destination: ?string, port: string, protocol: string, address_family: string, interface: ?string}  $expected
     * @return list<array{direction: string, action: string, source: string, destination: ?string, port: string, protocol: string, address_family: string, interface: ?string}>
     */
    public static function concreteExpectedShapes(array $expected): array
    {
        $addressFamilies = $expected['address_family'] === 'both'
            ? ['v4', 'v6']
            : [$expected['address_family']];

        return array_map(
            static fn (string $addressFamily): array => [
                ...$expected,
                'address_family' => $addressFamily,
            ],
            $addressFamilies,
        );
    }
}
