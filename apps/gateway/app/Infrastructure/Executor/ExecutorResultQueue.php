<?php

declare(strict_types=1);

namespace App\Infrastructure\Executor;

/** Where the listener puts reply lines and a waiting request takes them: the Gateway queue's Valkey (`ValkeyExecutorResultQueue`). */
interface ExecutorResultQueue
{
    /** Appends `$line` to the list `$key`, which expires `$expirySeconds` later. Throws when the server fails. */
    public function push(string $key, string $line, int $expirySeconds): void;

    /**
     * Takes the first line of the first non-empty list in `$keys`, waiting up to `$timeoutSeconds` (`BLPOP`).
     *
     * @param  list<string>  $keys
     * @return array{0: string, 1: string}|null The key, as given, and the line; null when the wait ends empty.
     */
    public function pop(array $keys, float $timeoutSeconds): ?array;
}
