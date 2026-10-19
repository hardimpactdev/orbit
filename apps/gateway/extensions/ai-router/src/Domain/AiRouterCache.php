<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Domain;

interface AiRouterCache
{
    public function get(string $key): ?string;

    /** Rename collector state without extending TTLs; discard the retired collector lock. */
    public function renameCollectorKeys(string $from, string $to): void;

    public function put(string $key, string $value, ?int $seconds = null): void;
}
