<?php

declare(strict_types=1);

namespace App\Domain\Executor;

use App\Models\Operation;
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
    /** The actions the executor allows. The proof and power families; wake joins in slice 5. */
    public const array Actions = ['proof.noop', 'sleep', 'wake', 'discard', 'ufw.read', 'ufw.ensure', 'ufw.ensure_rule', 'spike.wait', 'tls.csr', 'file.put', 'file.link', 'file.install', 'ca.trust', 'caddy.config', 'systemd.restart', 'incus.acl_ensure', 'incus.copy', 'incus.device_ensure', 'incus.file_put', 'incus.file_link', 'incus.rootfs_unshift', 'incus.start', 'ufw.ensure_route', 'http.wait', 'container.git_checkout', 'dotenv.merge', 'container.git_config', 'container.json_merge', 'container.dir', 'container.dir_sync', 'container.unit', 't3.session_issue', 't3.project_add', 'file.remove_tree'];

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

    /**
     * Signs an order for an Operation. The order's `node` is the Operation's `node_id` and its `operation` the Operation's id, so
     * an order exists only for an Operation that names its Node.
     *
     * @throws InvalidArgumentException when the Operation has no id or `node_id`, the action is unknown, the target is not the
     *                                  executor's form, or the order passes 4 KB.
     */
    public function sign(Operation $operation, string $action, string $target, array $input = []): SignedExecutorOrder
    {
        $node = self::nodeOf($operation);
        $id = self::idOf($operation);
        $nonce = bin2hex(random_bytes(16));
        $expiresMs = (int) Carbon::now()->getTimestampMs() + self::ExpiresAfterMs;
        $payload = self::payload($action, (string) $node, $target, $id, $nonce, $expiresMs, $input);
        $wire = base64_encode($payload).'.'.base64_encode(sodium_crypto_sign_detached($payload, $this->secretKey));
        if (strlen($wire) > self::MaxWireBytes) {
            throw new InvalidArgumentException('An executor order may not pass '.self::MaxWireBytes.' bytes.');
        }

        return new SignedExecutorOrder($wire, $id, $nonce, $expiresMs);
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
    public static function payload(string $action, string $node, string $target, string $operation, string $nonce, int $expiresMs, array $input = []): string
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
            // SPIKE (ADR 0267 order shape): a typed input, only when the action takes one.
            ...($input === [] ? [] : ['input' => $input]),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
