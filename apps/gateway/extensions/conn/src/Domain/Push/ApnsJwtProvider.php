<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain\Push;

use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Signs and caches the ES256 provider JWT for Apple APNs (RFC 7518 raw R||S signature).
 * Renews every 40 minutes, or immediately when APNs answers 403 ExpiredProviderToken.
 * Never logs or persists the signed token.
 */
final class ApnsJwtProvider
{
    private ?string $cachedToken = null;

    private ?int $issuedAt = null;

    private ?string $credentialsFingerprint = null;

    public function __construct(private readonly ConnPushCredentialsStore $credentials) {}

    public function token(): ?string
    {
        $credentials = $this->credentials->get();
        if (! $credentials instanceof ConnPushCredentials) {
            $this->clear();

            return null;
        }

        $now = Carbon::now()->getTimestamp();
        $fingerprint = hash('sha256', $credentials->keyId.':'.$credentials->teamId.':'.$credentials->privateKey);

        if ($this->cachedToken !== null
            && $this->issuedAt !== null
            && $this->credentialsFingerprint === $fingerprint
            && ($now - $this->issuedAt) < ConnPush::JwtLifetimeSeconds) {
            return $this->cachedToken;
        }

        return $this->mint($credentials, $now, $fingerprint);
    }

    /** Forces a fresh JWT immediately (used once on 403 ExpiredProviderToken). */
    public function refresh(): ?string
    {
        $credentials = $this->credentials->get();
        if (! $credentials instanceof ConnPushCredentials) {
            $this->clear();

            return null;
        }

        $now = Carbon::now()->getTimestamp();
        $fingerprint = hash('sha256', $credentials->keyId.':'.$credentials->teamId.':'.$credentials->privateKey);

        return $this->mint($credentials, $now, $fingerprint);
    }

    public function clear(): void
    {
        $this->cachedToken = null;
        $this->issuedAt = null;
        $this->credentialsFingerprint = null;
    }

    public static function sign(ConnPushCredentials $credentials, int $issuedAt): string
    {
        $header = self::base64UrlEncode(json_encode([
            'alg' => 'ES256',
            'kid' => $credentials->keyId,
        ], JSON_THROW_ON_ERROR));

        $claims = self::base64UrlEncode(json_encode([
            'iss' => $credentials->teamId,
            'iat' => $issuedAt,
        ], JSON_THROW_ON_ERROR));

        $signingInput = "{$header}.{$claims}";
        $privateKey = openssl_pkey_get_private($credentials->privateKey);
        if ($privateKey === false || ! openssl_sign($signingInput, $derSignature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign APNs provider JWT.');
        }

        $rawSignature = self::derToRaw($derSignature, 32);

        return "{$signingInput}.".self::base64UrlEncode($rawSignature);
    }

    /**
     * Converts an OpenSSL ASN.1 DER ECDSA signature into IEEE P1363 raw R||S format required by JWS ES256.
     */
    public static function derToRaw(string $der, int $partLength = 32): string
    {
        $length = strlen($der);
        if ($length < 8 || ord($der[0]) !== 0x30) {
            throw new RuntimeException('Invalid DER ECDSA signature.');
        }

        $offset = 2;
        if ((ord($der[1]) & 0x80) !== 0) {
            $lengthBytes = ord($der[1]) & 0x7F;
            $offset = 2 + $lengthBytes;
        }

        [$r, $offset] = self::readDerInteger($der, $offset, $partLength);
        [$s] = self::readDerInteger($der, $offset, $partLength);

        return $r.$s;
    }

    private function mint(ConnPushCredentials $credentials, int $now, string $fingerprint): string
    {
        $token = self::sign($credentials, $now);
        $this->cachedToken = $token;
        $this->issuedAt = $now;
        $this->credentialsFingerprint = $fingerprint;

        return $token;
    }

    /** @return array{0: string, 1: int} */
    private static function readDerInteger(string $der, int $offset, int $partLength): array
    {
        if (! isset($der[$offset], $der[$offset + 1]) || ord($der[$offset]) !== 0x02) {
            throw new RuntimeException('Invalid DER INTEGER in ECDSA signature.');
        }

        $intLength = ord($der[$offset + 1]);
        $valueStart = $offset + 2;
        if ($valueStart + $intLength > strlen($der)) {
            throw new RuntimeException('Truncated DER INTEGER in ECDSA signature.');
        }

        $value = substr($der, $valueStart, $intLength);
        $value = ltrim($value, "\x00");

        if (strlen($value) > $partLength) {
            throw new RuntimeException('ECDSA signature component exceeds expected length.');
        }

        return [str_pad($value, $partLength, "\x00", STR_PAD_LEFT), $valueStart + $intLength];
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
