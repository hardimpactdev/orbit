<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Domain;

interface AiRouterCache
{
    public function get(string $key): ?string;

    public function put(string $key, string $value, ?int $seconds = null): void;
}
