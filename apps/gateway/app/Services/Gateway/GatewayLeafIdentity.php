<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use RuntimeException;

/**
 * Canonical names for the Orbit-issued gateway API leaf certificate.
 *
 * The leaf CN / on-disk short host remains `gateway`. Browser Toolbar,
 * TypeScript SDK, and EventSource callers use the configured browser Gateway
 * hostname (default `gateway.orbit`). WireGuard IP remains an IP SAN so IP
 * clients keep working.
 */
final readonly class GatewayLeafIdentity
{
    public const string ShortHost = 'gateway';

    public const string DefaultBrowserHostname = 'gateway.orbit';

    public static function browserHostname(): string
    {
        $configured = config('orbit.gateway.hostname') ?? self::DefaultBrowserHostname;

        if (! is_string($configured)) {
            throw new RuntimeException('orbit.gateway.hostname must be a non-empty DNS hostname.');
        }

        $hostname = strtolower(trim($configured));

        if ($hostname === '') {
            return self::DefaultBrowserHostname;
        }

        if (! self::isValidDnsHostname($hostname)) {
            throw new RuntimeException("Invalid browser Gateway hostname: {$hostname}");
        }

        return $hostname;
    }

    private static function isValidDnsHostname(string $hostname): bool
    {
        if ($hostname === '' || strlen($hostname) > 253) {
            return false;
        }

        if (filter_var($hostname, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        // Reject scheme/path/port/whitespace forms and empty/adjacent labels before domain validation.
        if (preg_match('/[:\/\\\\?#@\s]|\.\.|\A\.|\.\z/', $hostname) === 1) {
            return false;
        }

        if (filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return false;
        }

        return ! array_any(
            explode('.', $hostname),
            self::isInvalidDnsLabel(...),
        );
    }

    private static function isInvalidDnsLabel(string $label): bool
    {
        return (
            $label === ''
            || strlen($label) > 63
            || str_starts_with($label, '-')
            || str_ends_with($label, '-')
            || preg_match('/^(?:[a-z0-9]|[a-z0-9][a-z0-9-]*[a-z0-9])$/i', $label) !== 1
        );
    }

    /**
     * Additional SANs when the leaf is issued under the short host CN.
     *
     * @return list<string>
     */
    public static function additionalSansForShortHost(string $wireguardAddress): array
    {
        return array_values(array_unique([
            self::browserHostname(),
            $wireguardAddress,
        ]));
    }

    /**
     * Additional SANs when the leaf is issued under the WireGuard IP CN
     * (legacy API container installer path).
     *
     * @return list<string>
     */
    public static function additionalSansForWireguardIp(): array
    {
        return array_values(array_unique([
            self::ShortHost,
            self::browserHostname(),
        ]));
    }
}
