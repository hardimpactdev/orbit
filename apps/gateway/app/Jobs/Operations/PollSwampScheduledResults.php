<?php

declare(strict_types=1);

namespace App\Jobs\Operations;

use App\Domain\Gateway\GatewayQueue;
use App\Infrastructure\Swamp\SwampScheduledResults;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Reads the runs that finished in Swamp since the last read and queues the projection of each new one. */
final class PollSwampScheduledResults implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** The shared Swamp read connection can be stale at the first read of a minute; the second try opens a new one. The read is idempotent. */
    public int $tries = 2;

    public int $timeout = 120;

    public int $uniqueFor = 120;

    public function __construct()
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Operations->value);
    }

    public function handle(SwampScheduledResults $results): void
    {
        $results->poll();
    }
}
