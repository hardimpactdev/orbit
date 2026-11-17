<?php

declare(strict_types=1);

// Test support only. Runs inside the nested Gateway as its managed user, never installed in a release.
//
// The executor's proof key (docs/reference/executor.md#proof-key). `key` makes a proof-only Ed25519 key pair once: the secret
// half stays in this Gateway's own `storage/app/private`, mode 0600, readable only by this user; the public half is pinned
// through ORBIT_EXECUTOR_PUBLIC_KEYS in this Gateway's own `.env`, so its next converge of an app-dev Node pins it there.
// `order` signs one `proof.noop` order with it. No key is ever committed or leaves this Gateway.

require __DIR__.'/../../apps/gateway/vendor/autoload.php';
$app = require __DIR__.'/../../apps/gateway/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$secretPath = storage_path('app/private/executor-proof.key');

$result = match ($input['mode'] ?? null) {
    'key' => proofKey($secretPath, base_path('.env')),
    'order' => proofOrder($secretPath, $input),
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
    $line = 'ORBIT_EXECUTOR_PUBLIC_KEYS='.$public;
    $env = is_file($envPath) ? (string) file_get_contents($envPath) : '';
    $pinned = preg_replace('/^ORBIT_EXECUTOR_PUBLIC_KEYS=.*$/m', $line, $env, -1, $count);
    $next = $count > 0 ? (string) $pinned : rtrim($env, "\n")."\n".$line."\n";
    if ($next !== $env) {
        file_put_contents($envPath, $next, LOCK_EX);
        $changed = true;
    }

    return ['public_key' => $public, 'changed' => $changed];
}

/** @param array<string, mixed> $input  @return array{order: string, nonce: string} */
function proofOrder(string $secretPath, array $input): array
{
    if (! is_file($secretPath)) {
        throw new RuntimeException('No executor proof key: run the key mode first.');
    }
    $nonce = is_string($input['nonce'] ?? null) ? $input['nonce'] : bin2hex(random_bytes(16));
    $payload = json_encode([
        'action' => 'proof.noop',
        'node' => (string) $input['node'],
        'target' => (string) ($input['target'] ?? $input['node']),
        'operation' => (string) $input['operation'],
        'nonce' => $nonce,
        'expires_ms' => (int) floor(microtime(true) * 1000) + 5000,
    ], JSON_THROW_ON_ERROR);

    return [
        'order' => base64_encode($payload).'.'.base64_encode(sodium_crypto_sign_detached($payload, secret($secretPath))),
        'nonce' => $nonce,
    ];
}

function secret(string $secretPath): string
{
    $secret = base64_decode(trim((string) file_get_contents($secretPath)), true);
    if (! is_string($secret) || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        throw new RuntimeException('The executor proof key is malformed.');
    }

    return $secret;
}
