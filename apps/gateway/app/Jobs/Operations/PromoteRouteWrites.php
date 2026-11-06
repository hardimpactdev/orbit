<?php

declare(strict_types=1);

namespace App\Jobs\Operations;

use App\Domain\Gateway\GatewayQueue;
use App\Domain\Operations\RoutePublicationHolds;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Starts the Route writes that wait for a Node a finished write just freed (docs/reference/routes.md#coordinate-publication).
 * It runs after the freeing transaction commits, on a worker, so a failed start cannot surface in the caller's commit.
 */
final class PromoteRouteWrites implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 120;

    public function __construct()
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Operations->value);
    }

    public function handle(RoutePublicationHolds $holds): void
    {
        $holds->promoteAndStart();
    }
}
