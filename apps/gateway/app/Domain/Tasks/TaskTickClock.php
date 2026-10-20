<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\GatewayReleases\GatewayVersion;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Remembers when a `tasks:tick` last took the tick lock and started its work, so an operator or a release smoke
 * test can tell a running scheduler from a stopped one. The value lives in the Gateway cache store, which every
 * Gateway process shares; clearing the cache only forgets it until the next tick.
 *
 * It also remembers the version of the code that ran the tick, so a Gateway release can confirm that its own
 * scheduler ticks ([Post-release tick confirmation](/reference/gateway-recovery#post-release-tick-confirmation)).
 *
 * The record is an observation, not part of the tick: a cache that cannot be written or read is reported and the
 * tick goes on, and the status reports no last tick.
 */
final readonly class TaskTickClock
{
    private const string StartedKey = 'orbit:tasks:tick:started';

    public function record(): void
    {
        $startedAt = now()->toISOString();

        try {
            Cache::forever(self::StartedKey, ['started_at' => $startedAt, 'version' => self::version()]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function lastStartedAt(): ?string
    {
        return $this->lastStarted()['started_at'] ?? null;
    }

    /**
     * When the last tick started and the version of the code that ran it, or null when no tick is remembered.
     *
     * @return array{started_at: string, version: string|null}|null
     */
    public function lastStarted(): ?array
    {
        try {
            $value = Cache::get(self::StartedKey);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        if (! is_array($value) || ! is_string($value['started_at'] ?? null)) {
            return null;
        }

        return ['started_at' => $value['started_at'], 'version' => is_string($value['version'] ?? null) ? $value['version'] : null];
    }

    private static function version(): string
    {
        return GatewayVersion::current();
    }
}
