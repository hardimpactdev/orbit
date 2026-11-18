<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain\Managed;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

/**
 * Orders notice publication and rotation for one managed target ([Revision order](/reference/conn#revision-order)). It is
 * a cache lock, not the database writer lock, so probes and other targets never wait on Reverb.
 */
final class ConnManagedTargetLock
{
    /** Longer than one Reverb send: a 2 s connect and a 5 s request limit. */
    public const int Seconds = 30;

    /**
     * Runs `$work` while holding the target's lock, or `$timedOut` when the lock stays taken for the configured wait.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @param  Closure(): T  $timedOut
     * @return T
     */
    public static function run(int $targetId, Closure $work, Closure $timedOut): mixed
    {
        try {
            return Cache::lock('orbit:conn-managed-target:'.$targetId, self::Seconds)
                ->block(Config::integer('orbit.conn.managed_target_lock_wait_seconds'), $work);
        } catch (LockTimeoutException) {
            return $timedOut();
        }
    }
}
