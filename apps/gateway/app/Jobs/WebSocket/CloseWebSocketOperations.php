<?php

declare(strict_types=1);

namespace App\Jobs\WebSocket;

use App\Actions\WebSocket\CloseWebSocketOperationsAction;
use App\Domain\Gateway\GatewayQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Fails registry tasks whose Reverb processes stopped reporting; reports close the rest at once. */
final class CloseWebSocketOperations implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 120;

    public function __construct()
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Operations->value);
    }

    public function handle(CloseWebSocketOperationsAction $close): void
    {
        $close->execute();
    }
}
