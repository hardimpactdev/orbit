<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Http\JsonResponse;

/** The wait a screen read of this request yielded to Operations, for the `Retry-After` of its answer. */
#[Scoped]
final class SwampViewWait
{
    private ?int $seconds = null;

    /** Notes that a view read of this request may connect again after `$seconds`. */
    public function note(int $seconds): void
    {
        $this->seconds = max($this->seconds ?? 0, max(1, $seconds));
    }

    public function seconds(): ?int
    {
        return $this->seconds;
    }

    /** Adds `Retry-After` to a screen read's answer when a view read of this request yielded. The body keeps its shape. */
    public function announce(JsonResponse $response): JsonResponse
    {
        return $this->seconds === null ? $response : $response->header('Retry-After', (string) $this->seconds);
    }
}
