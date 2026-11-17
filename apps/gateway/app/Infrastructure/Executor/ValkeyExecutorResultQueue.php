<?php

declare(strict_types=1);

namespace App\Infrastructure\Executor;

use Closure;
use Illuminate\Redis\Connections\Connection;

final readonly class ValkeyExecutorResultQueue implements ExecutorResultQueue
{
    private const string PushScript = "redis.call('RPUSH', KEYS[1], ARGV[1]) return redis.call('EXPIRE', KEYS[1], ARGV[2])";

    /** @param Closure(): Connection $redis The Gateway queue's Valkey connection. */
    public function __construct(private Closure $redis) {}

    public function push(string $key, string $line, int $expirySeconds): void
    {
        // One round trip, and the key never exists without its expiry.
        ($this->redis)()->command('eval', [self::PushScript, 1, $key, $line, $expirySeconds]);
    }

    public function pop(array $keys, float $timeoutSeconds): ?array
    {
        $popped = ($this->redis)()->command('blpop', [$keys, $timeoutSeconds]);

        if (! is_array($popped) || ! is_string($popped[0] ?? null) || ! is_string($popped[1] ?? null)) {
            return null;
        }

        // Valkey names the key with the connection's prefix.
        foreach ($keys as $key) {
            if (str_ends_with($popped[0], $key)) {
                return [$key, $popped[1]];
            }
        }

        return null;
    }
}
