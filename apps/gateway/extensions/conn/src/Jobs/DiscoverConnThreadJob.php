<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Jobs;

use App\Domain\Gateway\GatewayQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Orbit\Extensions\Conn\Domain\ConnThreadLibrarian;

/** A host Conn Server recorded a new thread: reads that server's shell and queues the librarian for the thread when it is eligible. */
final class DiscoverConnThreadJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 15;

    public function __construct(public int $environmentId, public string $threadId)
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Tasks->value);
    }

    public function handle(ConnThreadLibrarian $librarian): void
    {
        $librarian->pushed($this);
    }
}
