<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Domain;

use Closure;
use Illuminate\Support\Facades\Cache;

/** Serializes collector setup, teardown, and reconvergence without locking task model keys. */
final readonly class AiRouterLifecycleLock
{
    public const string Name = 'orbit:ai-router:lifecycle';

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function run(Closure $operation): mixed
    {
        return Cache::lock(self::Name, 600)->block(60, $operation);
    }
}
