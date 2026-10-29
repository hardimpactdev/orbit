<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Infrastructure;

use App\Models\DatabaseConnection;
use Orbit\Extensions\AiRouter\Domain\AiRouterCache;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;

/** Selects the configured cache on first use, without reading fleet state during construction. */
final class ConfiguredAiRouterCache implements AiRouterCache
{
    private ?AiRouterCache $cache = null;

    public function __construct(private readonly AiRouterState $state, private readonly ArrayAiRouterCache $memory) {}

    public function get(string $key): ?string
    {
        return $this->cache()->get($key);
    }

    public function put(string $key, string $value, ?int $seconds = null): void
    {
        $this->cache()->put($key, $value, $seconds);
    }

    public function renameCollectorKeys(string $from, string $to): void
    {
        $this->cache()->renameCollectorKeys($from, $to);
    }

    private function cache(): AiRouterCache
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $slug = $this->state->cacheConnection();
        $connection = is_string($slug) ? DatabaseConnection::query()->where('slug', $slug)->first() : null;

        return $this->cache = $connection instanceof DatabaseConnection
            ? new ValkeyAiRouterCache($connection) : $this->memory;
    }
}
