<?php

declare(strict_types=1);

namespace App\Jobs\Operations;

use App\Domain\Gateway\GatewayQueue;
use App\Domain\Operations\OperationOverdueAlerts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Delivers the committed expiry audit outside the cancellation worker, with no automatic retry. */
final class SendOperationOverdueAlert implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    /** @param array<string,mixed> $payload */
    public function __construct(public array $payload)
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Tasks->value);
    }

    public function handle(OperationOverdueAlerts $alerts): void
    {
        $alerts->send($this->payload);
    }
}
