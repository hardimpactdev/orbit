<?php

declare(strict_types=1);

namespace App\Domain\Executor;

use Illuminate\Support\Carbon;
use InvalidArgumentException;
use RuntimeException;
use SensitiveParameter;

/**
 * Builds and signs one executor order (ADR 0266 "The order", docs/reference/executor.md#the-order). The payload holds exactly
 * `action`, `node`, `target`, `operation`, `nonce` (128 random bits, hex) and `expires_ms` (Gateway time plus 5 s). The
 * secret key is the Gateway setting `orbit.executor.signing_key`; it never leaves this class.
 */
final readonly class ExecutorOrderSigner
{
    /** The actions the executor allows. Only the no-op `proof` family exists until sleep and wake arrive. */
    public const array Actions = ['proof.noop'];

    public const int MaxWireBytes = 4096;

    public const int ExpiresAfterMs = 5000;

    /** The executor's identifier form: 1 to 128 bytes of `[A-Za-z0-9._:-]`. */
    private const string Id = '/\A[A-Za-z0-9._:-]{1,128}\z/D';

    /** @var non-empty-string */
    private string $secretKey;

    /** @throws RuntimeException when the key is not a 64-byte Ed25519 secret key. */
    public function __construct(#[SensitiveParameter] string $secretKey)
    {
        if (strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('ORBIT_EXECUTOR_SIGNING_KEY is not a base64 Ed25519 secret key.');
        }
        $this->secretKey = $secretKey;
    }

    /** @throws RuntimeException when the Gateway has no usable signing key. */
    public static function fromConfig(): self
    {
        $encoded = config('orbit.executor.signing_key');
        $secret = is_string($encoded) ? base64_decode(trim($encoded), true) : false;

        return new self(is_string($secret) ? $secret : '');
    }

    /** @throws InvalidArgumentException when the action is unknown, an id is not the executor's form, or the order passes 4 KB. */
    public function sign(string $action, string $node, string $target, string $operation): SignedExecutorOrder
    {
        $nonce = bin2hex(random_bytes(16));
        $expiresMs = (int) Carbon::now()->getTimestampMs() + self::ExpiresAfterMs;
        $payload = self::payload($action, $node, $target, $operation, $nonce, $expiresMs);
        $wire = base64_encode($payload).'.'.base64_encode(sodium_crypto_sign_detached($payload, $this->secretKey));
        if (strlen($wire) > self::MaxWireBytes) {
            throw new InvalidArgumentException('An executor order may not pass '.self::MaxWireBytes.' bytes.');
        }

        return new SignedExecutorOrder($wire, $nonce, $expiresMs);
    }

    /** The signed payload's exact bytes. */
    public static function payload(string $action, string $node, string $target, string $operation, string $nonce, int $expiresMs): string
    {
        if (! in_array($action, self::Actions, strict: true)) {
            throw new InvalidArgumentException("Unknown executor action [{$action}].");
        }
        foreach (['node' => $node, 'target' => $target, 'operation' => $operation] as $field => $value) {
            if (preg_match(self::Id, $value) !== 1) {
                throw new InvalidArgumentException("The executor order's {$field} is not 1 to 128 characters of [A-Za-z0-9._:-].");
            }
        }

        return json_encode([
            'action' => $action,
            'node' => $node,
            'target' => $target,
            'operation' => $operation,
            'nonce' => $nonce,
            'expires_ms' => $expiresMs,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
