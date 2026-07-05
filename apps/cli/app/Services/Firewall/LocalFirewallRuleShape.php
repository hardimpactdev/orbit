<?php

declare(strict_types=1);

namespace App\Services\Firewall;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:excessive-parameter-list
 */
final readonly class LocalFirewallRuleShape
{
    private function __construct(
        public string $direction,
        public string $action,
        public string $source,
        public ?string $destination,
        public string $port,
        public string $protocol,
        public string $addressFamily,
        public ?string $interface,
        public ?string $reason,
    ) {}

    public static function from(mixed $value): self
    {
        if (! is_array($value)) {
            throw self::validationFailure('shape');
        }

        return new self(
            direction: self::oneOf($value['direction'] ?? null, 'direction', ['incoming', 'outgoing']),
            action: self::oneOf($value['action'] ?? null, 'action', ['allow', 'deny']),
            source: self::endpoint($value['source'] ?? null, 'source'),
            destination: self::nullableEndpoint($value['destination'] ?? null, 'destination'),
            port: self::port($value['port'] ?? null),
            protocol: self::oneOf($value['protocol'] ?? null, 'protocol', ['tcp', 'udp']),
            addressFamily: self::oneOf($value['address_family'] ?? 'both', 'address_family', ['v4', 'v6', 'both']),
            interface: self::nullableInterface($value['interface'] ?? null),
            reason: self::nullableString($value['reason'] ?? null, 'reason'),
        );
    }

    private static function oneOf(mixed $value, string $field, array $allowed): string
    {
        if (is_string($value) && in_array($value, $allowed, strict: true)) {
            return $value;
        }

        throw self::validationFailure($field);
    }

    private static function endpoint(mixed $value, string $field): string
    {
        if (
            is_string($value)
            && trim($value) !== ''
            && ($value === 'any'
            || filter_var($value, FILTER_VALIDATE_IP) !== false
            || str_contains($value, '/'))
        ) {
            return trim($value);
        }

        throw self::validationFailure($field);
    }

    private static function nullableEndpoint(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        return self::endpoint($value, $field);
    }

    private static function port(mixed $value): string
    {
        if (is_string($value) && preg_match('/^\d{1,5}(:\d{1,5})?$/', $value) === 1) {
            return $value;
        }

        throw self::validationFailure('port');
    }

    private static function nullableInterface(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value === 'public' || $value === 'wireguard') {
            return $value;
        }

        throw self::validationFailure('interface');
    }

    private static function nullableString(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        throw self::validationFailure($field);
    }

    private static function validationFailure(string $field): LocalFirewallRuleFailure
    {
        return new LocalFirewallRuleFailure(
            errorCode: 'validation_failed',
            message: 'Firewall rule shape is invalid.',
            meta: ['field' => $field],
        );
    }
}
