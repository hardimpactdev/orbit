<?php

declare(strict_types=1);

namespace App\Infrastructure\Executor;

/** Where the listener puts reply lines for a waiting request: the Gateway queue's Valkey (`ValkeyExecutorResultQueue`). */
interface ExecutorResultQueue
{
    /** Appends `$line` to the list `$key`, which expires `$expirySeconds` later. Throws when the server fails. */
    public function push(string $key, string $line, int $expirySeconds): void;
}
