<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain\Push;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * Apple APNs token-based auth credentials (.p8 EC private key, Key ID, Team ID).
 * One key serves both sandbox and production hosts. Never logged or serialized.
 */
final readonly class ConnPushCredentials
{
    public const int MaxPrivateKeyBytes = 16384;

    public function __construct(
        public string $keyId,
        public string $teamId,
        #[SensitiveParameter] public string $privateKey,
    ) {
        self::assertIdentifier($keyId, 'key_id');
        self::assertIdentifier($teamId, 'team_id');
        self::assertValidPrivateKey($privateKey);
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return [
            'keyId' => $this->keyId,
            'teamId' => $this->teamId,
            'privateKey' => '[REDACTED]',
        ];
    }

    public static function assertIdentifier(string $value, string $field): void
    {
        if (preg_match('/\A[A-Za-z0-9]{1,32}\z/D', $value) !== 1) {
            throw new InvalidArgumentException("Invalid APNs {$field}: expected 1 to 32 alphanumeric characters.");
        }
    }

    public static function assertValidPrivateKey(#[SensitiveParameter] string $pem): void
    {
        if ($pem === '' || strlen($pem) > self::MaxPrivateKeyBytes) {
            throw new InvalidArgumentException('APNs private key must be a non-empty PEM within 16 KiB.');
        }

        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            throw new InvalidArgumentException('APNs private key is not a valid PEM private key.');
        }

        $details = openssl_pkey_get_details($key);
        if (! is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC) {
            throw new InvalidArgumentException('APNs private key must be an EC (P-256) private key.');
        }
    }
}
