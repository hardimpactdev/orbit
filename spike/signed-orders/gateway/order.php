<?php
// SPIKE(signed-orders): the Gateway's order signer and sender.

function spike_order(int $node, string $target, string $operation, string $action = 'wake', int $ttlMs = 3000, ?string $nonce = null): array
{
    $payload = json_encode([
        'action' => $action,
        'node' => $node,
        'target' => $target,
        'operation' => $operation,
        'nonce' => $nonce ?? bin2hex(random_bytes(16)),
        'expires_ms' => (int) (microtime(true) * 1000) + $ttlMs,
    ], JSON_UNESCAPED_SLASHES);

    return ['payload' => $payload, 'envelope' => spike_sign($payload)];
}

function spike_sign(string $payload): string
{
    $key = base64_decode(trim(file_get_contents('/etc/orbit-spike/gateway.key')));

    return base64_encode($payload).'.'.base64_encode(sodium_crypto_sign_detached($payload, $key));
}

/** $reply is 'http' (observer POSTs to the agent route) or 'channel' (a client event on the Node channel). */
function spike_broadcast(int $node, string $envelope, string $reply = 'http'): void
{
    $realtime = app(App\Domain\Broadcasting\RealtimeConnection::class);
    static $configured = false;
    if (! $configured) {
        $realtime->configureBroadcasting($realtime->all()[0]);
        $configured = true;
    }
    Illuminate\Support\Facades\Broadcast::connection('reverb')
        ->broadcast(["presence-node.{$node}"], 'spike-order', ['order' => $envelope, 'reply' => $reply]);
}

/** Waits for the result with this nonce, up to $timeout seconds. */
function spike_result(string $nonce, float $timeout): ?array
{
    $deadline = microtime(true) + $timeout;
    while (($left = $deadline - microtime(true)) > 0) {
        $item = spike_redis()->blPop(['spike:results'], max(0.01, $left));
        if (! $item) {
            return null;
        }
        $result = json_decode($item[1], true);
        if (($result['body']['nonce'] ?? null) === $nonce) {
            return $result;
        }
    }

    return null;
}

/** The nested Gateway's local Valkey, reached directly: the spike adds no Laravel connection. */
function spike_redis(): Redis
{
    static $redis = null;
    if ($redis === null) {
        $redis = new Redis;
        $redis->connect('127.0.0.1', 6379);
    }

    return $redis;
}
