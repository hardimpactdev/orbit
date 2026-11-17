<?php

declare(strict_types=1);
use App\Domain\Executor\ExecutorOrderPublisher;
use App\Domain\Executor\ExecutorOrderSigner;
use App\Domain\Executor\SignedExecutorOrder;
use Illuminate\Contracts\Console\Kernel;

// Test support only. Runs inside the nested Gateway as its managed user, never installed in a release.
//
// The executor's proof key (docs/reference/executor.md#proof-key). `key` makes a proof-only Ed25519 key pair once: the secret
// half stays in this Gateway's own `storage/app/private`, mode 0600, readable only by this user, and becomes its signing key
// ORBIT_EXECUTOR_SIGNING_KEY; the public half is pinned through ORBIT_EXECUTOR_PUBLIC_KEYS in this Gateway's own `.env`, so its
// next converge of an app-dev Node pins it there. `order` signs one `proof.noop` order with it, or a tampered one for the order
// security tests. `publish` sends orders over the observer's WebSocket through the Gateway's own signer and publisher. No key is
// ever committed, printed or leaves this Gateway.

require __DIR__.'/../../apps/gateway/vendor/autoload.php';
$app = require __DIR__.'/../../apps/gateway/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$secretPath = storage_path('app/private/executor-proof.key');

$result = match ($input['mode'] ?? null) {
    'key' => proofKey($secretPath, base_path('.env')),
    'order' => proofOrder($secretPath, $input),
    'publish' => proofPublish($secretPath, $input),
    default => throw new InvalidArgumentException('Unknown executor proof mode.'),
};
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";

/** @return array{public_key: string, changed: bool} */
function proofKey(string $secretPath, string $envPath): array
{
    $changed = false;
    if (! is_file($secretPath)) {
        @mkdir(dirname($secretPath), 0o700, true);
        $previous = umask(0o077);
        try {
            if (file_put_contents($secretPath, base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())), LOCK_EX) === false) {
                throw new RuntimeException('The proof key cannot be written.');
            }
        } finally {
            umask($previous);
        }
        $changed = true;
    }
    chmod($secretPath, 0o600);
    $public = base64_encode(sodium_crypto_sign_publickey_from_secretkey(secret($secretPath)));
    $env = is_file($envPath) ? (string) file_get_contents($envPath) : '';
    $next = envLine(envLine($env, 'ORBIT_EXECUTOR_PUBLIC_KEYS', $public), 'ORBIT_EXECUTOR_SIGNING_KEY', base64_encode(secret($secretPath)));
    if ($next !== $env) {
        file_put_contents($envPath, $next, LOCK_EX);
        $changed = true;
    }

    return ['public_key' => $public, 'changed' => $changed];
}

function envLine(string $env, string $name, string $value): string
{
    $line = $name.'='.$value;
    $pinned = preg_replace('/^'.$name.'=.*$/m', $line, $env, -1, $count);

    return $count > 0 ? (string) $pinned : rtrim($env, "\n")."\n".$line."\n";
}

/**
 * One `proof.noop` order. `tamper` makes the order of one order security test: `bad_signature` (another key signs),
 * `changed_action` or `changed_target` (the payload changes after signing), `expired`, `unknown_action` or `wrong_node`.
 *
 * @param  array<string, mixed>  $input
 * @return array{order: string, nonce: string}
 */
function proofOrder(string $secretPath, array $input): array
{
    if (! is_file($secretPath)) {
        throw new RuntimeException('No executor proof key: run the key mode first.');
    }
    $tamper = $input['tamper'] ?? null;
    $nonce = is_string($input['nonce'] ?? null) ? $input['nonce'] : bin2hex(random_bytes(16));
    $now = (int) floor(microtime(true) * 1000);
    $fields = [
        'action' => $tamper === 'unknown_action' ? 'sleep' : 'proof.noop',
        'node' => $tamper === 'wrong_node' ? (string) ((int) $input['node'] + 1000) : (string) $input['node'],
        'target' => (string) ($input['target'] ?? $input['node']),
        'operation' => (string) $input['operation'],
        'nonce' => $nonce,
        'expires_ms' => $tamper === 'expired' ? $now - 1000 : $now + 5000,
    ];
    $payload = json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $key = $tamper === 'bad_signature' ? sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()) : secret($secretPath);
    $signature = sodium_crypto_sign_detached($payload, $key);
    if (in_array($tamper, ['changed_action', 'changed_target'], true)) {
        $fields[$tamper === 'changed_action' ? 'action' : 'target'] = $tamper === 'changed_action' ? 'proof.other' : $fields['target'].'0';
        $payload = json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    return ['order' => base64_encode($payload).'.'.base64_encode($signature), 'nonce' => $nonce];
}

/**
 * Publishes `count` orders, one after another, as `executor-order` on the Node's channel. An untampered order goes through the
 * Gateway's own signer (ORBIT_EXECUTOR_SIGNING_KEY); a tampered one is made by {@see proofOrder}. Each entry records the Gateway
 * time just before its publish, for the latency to the executor.
 *
 * @param  array<string, mixed>  $input
 * @return array{orders: list<array{nonce: string, published_ms: int}>}
 */
function proofPublish(string $secretPath, array $input): array
{
    $node = (string) $input['node'];
    $count = max(1, min(50, (int) ($input['count'] ?? 1)));
    $publisher = app(ExecutorOrderPublisher::class);
    $orders = [];
    for ($index = 1; $index <= $count; $index++) {
        $operation = (string) $input['operation'].($count > 1 ? '-'.$index : '');
        if (isset($input['tamper']) || isset($input['nonce'])) {
            $made = proofOrder($secretPath, ['node' => $node, 'operation' => $operation] + $input);
            $order = new SignedExecutorOrder($made['order'], $made['nonce'], 0);
        } else {
            $order = ExecutorOrderSigner::fromConfig()->sign('proof.noop', $node, $node, $operation);
        }
        $published = (int) floor(microtime(true) * 1000);
        $publisher->publish((int) $node, $order);
        $orders[] = ['nonce' => $order->nonce, 'published_ms' => $published];
    }

    return ['orders' => $orders];
}

function secret(string $secretPath): string
{
    $secret = base64_decode(trim((string) file_get_contents($secretPath)), true);
    if (! is_string($secret) || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        throw new RuntimeException('The executor proof key is malformed.');
    }

    return $secret;
}
