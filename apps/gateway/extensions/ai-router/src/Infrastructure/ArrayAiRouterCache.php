<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Infrastructure;

use Illuminate\Container\Attributes\Singleton;
use Orbit\Extensions\AiRouter\Domain\AiRouterCache;

#[Singleton]
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

        if ($item['expires'] !== null && $item['expires'] <= now()->getTimestamp()) {
            unset($this->items[$key]);

            return null;
        }

        return $item['value'];
    }

    public function renameCollectorKeys(string $from, string $to): void
    {
        foreach (array_keys($this->items) as $key) {
            if (str_starts_with($key, $from)) {
                $suffix = substr($key, strlen($from));
                if ($suffix !== 'lock') {
                    $this->items[$to.$suffix] = $this->items[$key];
                }
                unset($this->items[$key]);
            }
        }
    }

    public function put(string $key, string $value, ?int $seconds = null): void
    {
        $this->items[$key] = [
            'value' => $value,
            'expires' => $seconds === null ? null : now()->getTimestamp() + $seconds,
        ];
    }
}
