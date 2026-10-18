<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Domain;

interface AiRouterCache
{
    public function get(string $key): ?string;

    /** Delete only the obsolete collector namespace during its recorded cutover. */
    public function forgetPrefix(string $prefix): void;

    public function put(string $key, string $value, ?int $seconds = null): void;
}
