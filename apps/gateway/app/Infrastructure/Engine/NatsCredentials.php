<?php

declare(strict_types=1);

namespace App\Infrastructure\Engine;

use Basis\Nats\NKeys\Base32;
use Basis\Nats\NKeys\CRC16;
use Basis\Nats\NKeys\SecretKey;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * NATS decentralized authentication for the machine bus (ADR 0271; docs/reference/gateway-engine.md#credentials). The Gateway
 * holds three nkey seeds: the operator, the `ORBIT` account and the system account. `nats-server` trusts the operator and the
 * two account JWTs, so a Node joins with a user JWT the Gateway signs and the server's configuration never changes when Nodes
 * come and go. Each user JWT carries its own publish and subscribe permissions.
 *
 * A user's key is derived from the account seed and the user's name, so a Node's credentials stay the same across converges;
 * replacing the account seed revokes every user at once. The JWTs follow the format of `nsc`: `ed25519-nkey`, claims version 2.
 */
final readonly class NatsCredentials
{
    private const int PrefixSeed = 18 << 3;

    public const int PrefixOperator = 14 << 3;

    public const int PrefixAccount = 0;

    public const int PrefixUser = 20 << 3;

    /** A new random seed of the given kind: an operator, an account or a user. */
    public static function newSeed(int $prefix): string
    {
        return self::seed($prefix, random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES));
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

    /** The operator's self-signed JWT, naming the system account. */
    public static function operatorJwt(#[SensitiveParameter] string $operatorSeed, string $systemAccount): string
    {
        $operator = self::publicKey($operatorSeed);

        return self::jwt($operatorSeed, $operator, 'orbit', ['type' => 'operator', 'version' => 2, 'system_account' => $systemAccount]);
    }

    /** An account JWT signed by the operator. `$jetstream` gives the account unlimited JetStream storage on this server. */
    public static function accountJwt(#[SensitiveParameter] string $operatorSeed, #[SensitiveParameter] string $accountSeed, string $name, bool $jetstream): string
    {
        $limits = ['subs' => -1, 'data' => -1, 'payload' => -1, 'imports' => -1, 'exports' => -1, 'wildcards' => true, 'conn' => -1, 'leaf' => -1,
            ...($jetstream ? ['mem_storage' => -1, 'disk_storage' => -1, 'streams' => -1, 'consumer' => -1] : [])];

        return self::jwt($operatorSeed, self::publicKey($accountSeed), $name, ['type' => 'account', 'version' => 2, 'limits' => $limits]);
    }

    /**
     * A user JWT signed by the account, with exactly these permissions.
     *
     * @param  list<string>  $publish
     * @param  list<string>  $subscribe
     */
    public static function userJwt(#[SensitiveParameter] string $accountSeed, string $userPublicKey, string $name, array $publish, array $subscribe): string
    {
        return self::jwt($accountSeed, $userPublicKey, $name, ['type' => 'user', 'version' => 2,
            'pub' => ['allow' => $publish], 'sub' => ['allow' => $subscribe], 'subs' => -1, 'data' => -1, 'payload' => -1]);
    }

    /** A `.creds` file: the user JWT and its seed, in the form every NATS client reads. */
    public static function creds(string $jwt, #[SensitiveParameter] string $seed): string
    {
        return "-----BEGIN NATS USER JWT-----\n{$jwt}\n------END NATS USER JWT------\n\n"
            ."************************* IMPORTANT *************************\nNKEY Seed printed below can be used to sign and prove identity.\n"
            ."NKEYs are sensitive and should be treated as secrets.\n\n-----BEGIN USER NKEY SEED-----\n{$seed}\n------END USER NKEY SEED------\n";
    }

    /**
     * Signs claims as `$issuerSeed`. `iat` is fixed, so the same inputs give the same JWT and a converge changes nothing.
     *
     * @param  array<string, mixed>  $nats
     */
    private static function jwt(#[SensitiveParameter] string $issuerSeed, string $subject, string $name, array $nats): string
    {
        $issuer = SecretKey::fromSeed($issuerSeed);
        $claims = ['jti' => '', 'iat' => 1_767_225_600, 'iss' => $issuer->getPublicKey(), 'name' => $name, 'sub' => $subject, 'nats' => $nats];
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
        if (! in_array($prefix, [self::PrefixOperator, self::PrefixAccount, self::PrefixUser], true) || strlen($raw) !== SODIUM_CRYPTO_SIGN_SEEDBYTES) {
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
