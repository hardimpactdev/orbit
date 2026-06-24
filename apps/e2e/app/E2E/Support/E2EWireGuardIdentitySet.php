<?php

declare(strict_types=1);

namespace App\E2E\Support;

use RuntimeException;

final class E2EWireGuardIdentitySet
{
    /**
     * @var array<string, array{private_key: string, public_key: string}>
     */
    private const array Identities = [
        'gateway' => [
            'private_key' => 'QICs4J/f9Fe/W6hTIf2bOTPFkK85nQZ3MCNsKem3EFE=',
            'public_key' => 'FGvPNoz2W40e67fcPssa5XgmqJJWOY6PGReQZ1eQ2T0=',
        ],
        'operator' => [
            'private_key' => 'kF1pgoSuocpTNeZ+Z2lG77R3kW8vIbvHxWYuS3/KR2o=',
            'public_key' => '8Kk1eHvFjl9KapqdZ6U2epl3KkMLhscWFhABalzBplk=',
        ],
        'dev' => [
            'private_key' => 'sIYENayYwOkOuxybC6OAxLcayLVLPB3FsLj5ic2KOlM=',
            'public_key' => 'SyfSKoVje2MKfdob/ZV2FXGbe27gkvaXkdFOIvO3CzU=',
        ],
        'prod' => [
            'private_key' => 'kGR3TLgIK9q+h+YeZHcXaH2X9kva74moZUkeN6gQSFI=',
            'public_key' => 'yN4ixmpZlDpJ9B7GU+Hot/CBiRMFZpzKwuVBgdnk0Gw=',
        ],
        'agent' => [
            'private_key' => 'MLLrH+EcmwibyX+IZ8cNAIzOUmx86TsDgOGXn6P4eXY=',
            'public_key' => 'jugHALwmJn52ZdQBS8mNp8fQsUgN6rxk9UttWWOJHm8=',
        ],
        'ingress' => [
            'private_key' => '2PTdrJy1yQSHHG+8BWx3ZoLdZ4HeqGX6YJyMomvPCXs=',
            'public_key' => '1zOZVQfoXGiub+gO9Jgp0ogagDGwA4E+MBCC3/496wA=',
        ],
        'websocket' => [
            'private_key' => 'CN2p2VS+kRsxWylU5XQEB6SUvIOlcYbbSGZj2VH0xkk=',
            'public_key' => 'cxi9JWNW/f/OtEVnbSfCWCXzK3nSGGRAAUT/cpC/wCE=',
        ],
    ];

    /**
     * @return array{private_key: string, public_key: string}
     */
    public static function forRole(string $role): array
    {
        return self::Identities[$role] ?? throw new RuntimeException(
            "No fixed E2E WireGuard identity is defined for role [{$role}].",
        );
    }

    public static function version(): string
    {
        return '2026-06-05.1';
    }
}
