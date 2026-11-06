<?php

declare(strict_types=1);

namespace App\Jobs\Operations;

use App\Actions\Operations\ExpireOperationAction;
use App\Domain\Gateway\GatewayQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Frees the target of a timed-out Operation whose run Swamp never proved ended, so a lost run cannot hold a group or a VM for ever. */
final class ReleaseStaleOperationHolds implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Operations->value);
    }

    public function handle(ExpireOperationAction $expire): void
    {
        $expire->releaseStaleHolds();
    }
}
