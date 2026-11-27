<?php

declare(strict_types=1);

namespace App\Domain\Executor;

use App\Models\Operation;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use RuntimeException;
use SensitiveParameter;

/**
 * Builds and signs one executor order (ADR 0266 "The order", docs/reference/executor.md#the-order). The payload holds
 * `action`, `node`, `target`, `operation`, `nonce` (128 random bits, hex) and `expires_ms` (Gateway time plus 5 s), then
 * `power_check` when the order carries one, then `input` for an engine step (ADR 0271) or a wake's readiness bound. The
 * secret key is the Gateway setting `orbit.executor.signing_key`; it never leaves this class.
 */
final readonly class ExecutorOrderSigner
{
    public const array Actions = ['proof.noop', 'sleep', 'wake', 'discard', 'ufw.ensure_rule', 'tls.ensure_csr', 'route.publish_site', 'dns.publish_records', 'instance.clone', 'instance.prepare', 'instance.start', 'instance.fetch_branch', 'instance.checkout', 'instance.t3', 'instance.club_key', 'instance.copy_volume', 'instance.stop', 'instance.spare_volume', 'instance.fleet_ready', 'instance.names', 'instance.destroy', 'instance.sync_base', 'instance.clear_base', 'process.ensure_state', 'schedule.ensure_timer'];

    public const int MaxWireBytes = 4096;

    public const int ExpiresAfterMs = 5000;

    /** The most time a Node accepts on an order at verification (MAX_EXPIRY_AHEAD_MS in the executor). */
    public const int MaxExpiryAheadMs = 10_000;

    /**
     * An engine order may wait in its Node's JetStream consumer while the Node reconnects. The executor refuses an expiry more
     * than 10 s ahead, so the engine signs 8 s and sends a new order when a receipt says `expired` (ADR 0271).
     */
    public const int StepExpiresAfterMs = 8000;

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

    /**
     * The X25519 key an engine step names as `seal_to`, so the executor seals secret output to this Gateway alone (ADR 0271).
     * It derives from the signing key; base64.
     */
    public function sealRecipient(): string
    {
        return base64_encode(sodium_crypto_sign_ed25519_pk_to_curve25519(sodium_crypto_sign_publickey_from_secretkey($this->secretKey)));
    }

    /**
     * Opens a sealed box the executor made to [sealRecipient()]. The plaintext is a secret: the caller stores it where its
     * reader expects it and never logs it.
     *
     * @throws RuntimeException `executor.seal_invalid` when the box is not base64 or does not open with this key.
     */
    public function open(string $sealed): string
    {
        $box = base64_decode($sealed, true);
        $public = sodium_crypto_sign_ed25519_pk_to_curve25519(sodium_crypto_sign_publickey_from_secretkey($this->secretKey));
        $pair = sodium_crypto_box_keypair_from_secretkey_and_publickey(sodium_crypto_sign_ed25519_sk_to_curve25519($this->secretKey), $public);
        $plain = is_string($box) && $box !== '' ? sodium_crypto_box_seal_open($box, $pair) : false;

        return is_string($plain) ? $plain : throw new RuntimeException('executor.seal_invalid');
    }

    /** @throws RuntimeException when the Gateway has no usable signing key. */
    public static function fromConfig(): self
    {
        $encoded = config('orbit.executor.signing_key');
        $secret = is_string($encoded) ? base64_decode(trim($encoded), true) : false;

        return new self(is_string($secret) ? $secret : '');
    }

    /**
     * Signs an order for an Operation. The order's `node` is the Operation's `node_id` and its `operation` the Operation's id, so
     * an order exists only for an Operation that names its Node.
     *
     * @throws InvalidArgumentException when the Operation has no id or `node_id`, the action is unknown, the target is not the
     *                                  executor's form, or the order passes 4 KB.
     */
    /** @param array<string, mixed> $input */
    public function sign(Operation $operation, string $action, string $target, ?string $powerCheck = null, array $input = []): SignedExecutorOrder
    {
        $node = self::nodeOf($operation);
        $id = self::idOf($operation);
        $nonce = bin2hex(random_bytes(16));
        $expiresMs = (int) Carbon::now()->getTimestampMs() + self::ExpiresAfterMs;
        $payload = self::payload($action, (string) $node, $target, $id, $nonce, $expiresMs, $powerCheck, $input);
        $wire = base64_encode($payload).'.'.base64_encode(sodium_crypto_sign_detached($payload, $this->secretKey));
        if (strlen($wire) > self::MaxWireBytes) {
            throw new InvalidArgumentException('An executor order may not pass '.self::MaxWireBytes.' bytes.');
        }

        return new SignedExecutorOrder($wire, $id, $nonce, $expiresMs);
    }

    /**
     * Signs one step of an engine Operation (ADR 0271). The order's `operation` is `<id>:<step>`, so each step has its own
     * done record on the Node; its `node` is the step's Node, and its `input` the primitive's typed input.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws InvalidArgumentException when the Operation has no id, the action is unknown, a field is not the executor's form,
     *                                  or the order passes 4 KB.
     */
    public function signStep(Operation $operation, int $node, string $step, string $action, string $target, array $input): SignedExecutorOrder
    {
        $reference = self::idOf($operation).':'.$step;
        $nonce = bin2hex(random_bytes(16));
        $expiresMs = (int) Carbon::now()->getTimestampMs() + self::StepExpiresAfterMs;
        $payload = self::payload($action, (string) $node, $target, $reference, $nonce, $expiresMs, null, $input);
        $wire = base64_encode($payload).'.'.base64_encode(sodium_crypto_sign_detached($payload, $this->secretKey));
        if (strlen($wire) > self::MaxWireBytes) {
            throw new InvalidArgumentException('An executor order may not pass '.self::MaxWireBytes.' bytes.');
        }

        return new SignedExecutorOrder($wire, $reference, $nonce, $expiresMs);
    }

    /**
     * The Node an Operation's order goes to: its `node_id`. Slice 3's listener records a result only from that Node.
     *
     * @throws InvalidArgumentException when the Operation has no id or no `node_id`.
     */
    public static function nodeOf(Operation $operation): int
    {
        self::idOf($operation);
        if ($operation->node_id === null) {
            throw new InvalidArgumentException('An executor order needs an Operation with an id and a node_id.');
        }

        return $operation->node_id;
    }

    /**
     * The Operation's id as the numeric string an order and its event carry.
     *
     * @throws InvalidArgumentException when the Operation has no id.
     */
    public static function idOf(Operation $operation): string
    {
        $id = $operation->getKey();
        if (! is_int($id)) {
            throw new InvalidArgumentException('An executor order needs an Operation with an id and a node_id.');
        }

        return (string) $id;
    }

    /** The signed payload's exact bytes. */
    /** @param array<string, mixed> $input */
    public static function payload(string $action, string $node, string $target, string $operation, string $nonce, int $expiresMs, ?string $powerCheck = null, array $input = []): string
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
            ...($powerCheck === null ? [] : ['power_check' => $powerCheck]),
            ...($input === [] ? [] : ['input' => $input]),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
