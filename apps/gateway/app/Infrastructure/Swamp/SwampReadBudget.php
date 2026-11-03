<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Serve admits at most 50 new WebSocket connections from one IP address in a fixed minute, for every token together,
 * and answers the rest 429 until the minute ends. Reads that only refresh what a caller sees share a smaller part of
 * that, so admissions, execution copies and the shared observers keep the rest.
 */
final readonly class SwampReadBudget
{
    public const int PerMinute = 20;

    /** Takes one connection for $subject, which reads at most once per $intervalSeconds. A failed read spends it too. */
    public function take(string $subject, int $intervalSeconds): bool
    {
        $key = 'swamp-read:'.$subject;
        if (RateLimiter::tooManyAttempts($key, 1) || RateLimiter::tooManyAttempts('swamp-read', self::PerMinute)) {
            return false;
        }
        RateLimiter::hit($key, $intervalSeconds);
        RateLimiter::hit('swamp-read', 60);

        return true;
    }
}
