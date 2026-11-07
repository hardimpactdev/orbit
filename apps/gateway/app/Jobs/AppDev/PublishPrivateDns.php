<?php

declare(strict_types=1);

namespace App\Jobs\AppDev;

use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Gateway\GatewayQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Publishes a fresh render of the private DNS after a failed run has been projected into the rows.
 *
 * A kind that restores the DNS in its own run publishes the restore with the revision it took at admission. A publication admitted
 * after that (another Route, a Node change) holds a newer revision, and the Node then skips the restore as superseded. That
 * publication rendered the rows while the failed change still stood. Rendering again after the failure is stored always makes
 * the newest revision the correct one, so no failed change stays in DNS. The render is the same when the restore already won, and the
 * Node then changes only its revision file. Dispatch it after the transaction that projects the failure commits.
 */
final class PublishPrivateDns implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 15;

    public function __construct()
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Operations->value);
    }

    public function handle(PrivateDnsManager $dns): void
    {
        $dns->converge();
    }
}
