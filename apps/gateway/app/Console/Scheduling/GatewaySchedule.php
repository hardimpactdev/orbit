<?php

declare(strict_types=1);

namespace App\Console\Scheduling;

use App\Domain\Gateway\GatewayQueue;
use App\Infrastructure\Gateway\GatewayQueueRedis;
use App\Jobs\RunScheduledCommand;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Throwable;

/**
 * Registers scheduled work (docs/reference/gateway-queue.md#scheduled-work). While the Gateway queue is set, the
 * scheduler only dispatches, so nothing heavy runs in `schedule:work`. Without the queue, it runs a command itself,
 * with its overlap lock, as before.
 */
final readonly class GatewaySchedule
{
    /**
     * @param  int  $uniqueFor  seconds a lost job keeps its unique lock
     * @param  int|null  $overlapMinutes  the overlap lock of the inline command; null for none
     */
    public static function command(Schedule $schedule, string $command, int $uniqueFor, ?int $overlapMinutes = null, GatewayQueue $queue = GatewayQueue::Tasks): Event
    {
        if (! GatewayQueueRedis::configured()) {
            $event = $schedule->command($command);

            return $overlapMinutes === null ? $event : $event->withoutOverlapping($overlapMinutes);
        }

        return $schedule->call(static function () use ($command, $uniqueFor, $queue): void {
            self::dispatch(new RunScheduledCommand($command, $uniqueFor, $queue));
        })->name($command);
    }

    /**
     * A job that names its own connection and queue. Only the Gateway queue runs it, so without the queue each due run
     * fails loudly instead of skipping the work.
     */
    public static function job(Schedule $schedule, ShouldQueue $job): Event
    {
        return $schedule->call(static function () use ($job): void {
            if (! GatewayQueueRedis::configured()) {
                throw new RuntimeException($job::class.' needs the Gateway queue, but ORBIT_GATEWAY_QUEUE_CONNECTION is empty. See /reference/gateway-queue.');
            }

            self::dispatch(clone $job);
        })->name($job::class);
    }

    /**
     * Queues the job on its own connection and queue, unless its unique lock is held. A failed push frees the lock and
     * throws, so the scheduler reports it, keeps running, and its next run dispatches the job again.
     */
    public static function dispatch(ShouldQueue $job): void
    {
        $lock = $job instanceof ShouldBeUnique ? new UniqueLock(app(Repository::class)) : null;

        if ($lock instanceof UniqueLock && ! $lock->acquire($job)) {
            return;
        }

        try {
            Queue::connection(self::property($job, 'connection'))->pushOn(self::property($job, 'queue'), $job);
        } catch (Throwable $exception) {
            $lock?->release($job);

            throw $exception;
        }
    }

    private static function property(ShouldQueue $job, string $name): string
    {
        $value = property_exists($job, $name) ? $job->{$name} : null;

        return is_string($value) && $value !== '' ? $value : throw new \LogicException($job::class." names no {$name}.");
    }
}
