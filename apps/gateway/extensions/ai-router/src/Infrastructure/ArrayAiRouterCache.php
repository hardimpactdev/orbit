<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Infrastructure;

use Orbit\Extensions\AiRouter\Domain\AiRouterCache;

final class ArrayAiRouterCache implements AiRouterCache
{
    /** @var array<string, array{value: string, expires: int|null}> */
    private array $items = [];

    public function get(string $key): ?string
    {
        $item = $this->items[$key] ?? null;

        if ($item === null) {
            return null;
        }

        if ($item['expires'] !== null && $item['expires'] <= time()) {
            unset($this->items[$key]);

            return null;
        }

        return $item['value'];
    }

    public function put(string $key, string $value, ?int $seconds = null): void
    {
        $this->items[$key] = [
            'value' => $value,
            'expires' => $seconds === null ? null : time() + $seconds,
        ];
    }
}
