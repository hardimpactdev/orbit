<?php

declare(strict_types=1);

namespace App\Infrastructure\Engine;

use Basis\Nats\NKeys\Base32;
use Basis\Nats\NKeys\CRC16;
use Basis\Nats\NKeys\SecretKey;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * The nkeys and JWTs of the machine bus's auth callout (ADR 0271; docs/reference/gateway-engine.md#authentication). The
 * Gateway holds one account seed, `ORBIT_NATS_SEED`. Its public key is the callout's issuer, which `nats-server` trusts, and
 * the Gateway's own nkey user derives from it. A Node holds no credential: the callout answers each of its connections with a
 * short-lived user JWT that the issuer signs. The JWTs follow the format of `nsc`: `ed25519-nkey`, claims version 2.
 */
final readonly class NatsCredentials
{
    private const int PrefixSeed = 18 << 3;

    public const int PrefixAccount = 0;

    public const int PrefixUser = 20 << 3;

    /** A new random account seed: the callout issuer. */
    public static function newSeed(): string
    {
        return self::seed(self::PrefixAccount, random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES));
    }

    /** The seed of a user derived from the account seed: the same name always gives the same key. */
    public static function userSeed(#[SensitiveParameter] string $accountSeed, string $name): string
    {
        SecretKey::fromSeed($accountSeed);

        return self::seed(self::PrefixUser, hash_hmac('sha256', 'orbit-nats-user:'.$name, $accountSeed, true));
    }

    public static function publicKey(#[SensitiveParameter] string $seed): string
    {
        return SecretKey::fromSeed($seed)->getPublicKey();
    }

    /**
     * A user JWT for the connection whose server-made key is `$userKey`, in account `$account`, with exactly these permissions
     * until `$expires`. The server disconnects the user then, and the client's reconnect asks the callout again.
     *
     * @param  list<string>  $publish
     * @param  list<string>  $subscribe
     */
    public static function userJwt(#[SensitiveParameter] string $issuerSeed, string $userKey, string $name, string $account, array $publish,
        array $subscribe, int $now, int $expires): string
    {
        return self::jwt($issuerSeed, ['iat' => $now, 'exp' => $expires, 'name' => $name, 'sub' => $userKey, 'aud' => $account,
            'nats' => ['type' => 'user', 'version' => 2, 'pub' => ['allow' => $publish], 'sub' => ['allow' => $subscribe],
                'subs' => -1, 'data' => -1, 'payload' => -1]]);
    }

    /** The callout's answer to server `$server` about the connection `$userKey`: a user JWT, or the reason it is refused. */
    public static function authorizationResponse(#[SensitiveParameter] string $issuerSeed, string $userKey, string $server, int $now,
        ?string $userJwt, ?string $error = null): string
    {
        return self::jwt($issuerSeed, ['iat' => $now, 'sub' => $userKey, 'aud' => $server,
            'nats' => ['type' => 'authorization_response', 'version' => 2, ...($userJwt === null ? [] : ['jwt' => $userJwt]),
                ...($error === null ? [] : ['error' => $error])]]);
    }

    /**
     * The claims of a JWT, unverified. The callout reads its request this way: only the server publishes on the request subject.
     *
     * @return array<string, mixed>|null
     */
    public static function claims(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        $json = count($parts) === 3 ? base64_decode(strtr($parts[1], '-_', '+/'), true) : false;
        $claims = is_string($json) ? json_decode($json, true) : null;
        if (! is_array($claims) || array_is_list($claims)) {
            return null;
        }

        /** @var array<string, mixed> $claims a decoded JSON object */
        return $claims;
    }

    /** @param array<string, mixed> $claims */
    private static function jwt(#[SensitiveParameter] string $issuerSeed, array $claims): string
    {
        $issuer = SecretKey::fromSeed($issuerSeed);
        $claims = ['jti' => '', ...$claims, 'iss' => $issuer->getPublicKey()];
        $claims['jti'] = rtrim((new Base32)->encode(hash('sha256', self::json($claims), true)), '=');
        $signing = self::base64url(self::json(['typ' => 'JWT', 'alg' => 'ed25519-nkey'])).'.'.self::base64url(self::json($claims));

        $secret = $issuer->value;
        if ($secret === '') {
            throw new InvalidArgumentException('An nkey seed gave an empty signing key.');
        }

        return $signing.'.'.self::base64url(sodium_crypto_sign_detached($signing, $secret));
    }

    private static function seed(int $prefix, #[SensitiveParameter] string $raw): string
    {
        if (! in_array($prefix, [self::PrefixAccount, self::PrefixUser], true) || strlen($raw) !== SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            throw new InvalidArgumentException('An nkey seed needs a known prefix and 32 bytes.');
        }
        $bytes = chr(self::PrefixSeed | ($prefix >> 5)).chr(($prefix & 31) << 3).$raw;
        $crc = CRC16::hash(array_values(unpack('C*', $bytes) ?: []));

        return rtrim((new Base32)->encode($bytes.pack('v', $crc)), '=');
    }

    /** @param array<string, mixed> $value */
    private static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
