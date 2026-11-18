<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain\Managed;

use Closure;
use Illuminate\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

/**
 * Orders notice publication and enrolment for one managed environment ([Revision order](/reference/conn#revision-order)).
 * It is a cache lock keyed by the environment UUID, so a first enrolment takes it too, and never the database writer lock.
 * Its holder learns before a send whether it still owns the lock with enough lease left for the whole publish.
 */
final class ConnManagedTargetLock
{
    /** The lease of one holder. */
    public const int LeaseSeconds = 30;

    /** The longest one Reverb publish may take: its 2 s connect and 5 s request limits. */
    public const int PublishSeconds = 7;

    /** Room left for clock steps and the record after the send. */
    private const int MarginSeconds = 3;

    private function __construct(private Lock $lock, private float $acquiredAt) {}

    /**
     * Runs `$work` with the held lock, or `$busy` when another holder keeps it for the configured wait.
     *
     * @template T
     *
     * @param  Closure(self): T  $work
     * @param  Closure(): T  $busy
     * @return T
     */
    public static function run(string $environmentId, Closure $work, Closure $busy): mixed
    {
        $lock = Cache::lock('orbit:conn-managed-environment:'.$environmentId, self::LeaseSeconds);
        assert($lock instanceof Lock);
        try {
            $lock->block(Config::integer('orbit.conn.managed_target_lock_wait_seconds'));
        } catch (LockTimeoutException) {
            return $busy();
        }
        $held = new self($lock, hrtime(true) / 1e9);
        try {
            return $work($held);
        } finally {
            if ($held->owns()) {
                $lock->release();
            }
        }
    }

    /** True while this holder still owns the lock with lease left for a whole publish and its record. */
    public function coversPublish(): bool
    {
        $elapsed = hrtime(true) / 1e9 - $this->acquiredAt;

        return $elapsed + self::PublishSeconds + self::MarginSeconds < self::LeaseSeconds && $this->owns();
    }

    /** True while this holder still owns the lock. */
    public function owns(): bool
    {
        return $this->lock->isOwnedByCurrentProcess();
    }
}
