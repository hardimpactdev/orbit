<?php

declare(strict_types=1);

namespace App\Infrastructure\Ssh;

interface KnownHostsStore
{
    public function path(): string;

    /** Return whether the pinned entry changed. */
    public function put(string $host, int $port, HostKey $key): bool;
}
