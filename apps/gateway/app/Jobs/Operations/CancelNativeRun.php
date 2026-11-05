<?php

declare(strict_types=1);

namespace App\Jobs\Operations;

use App\Domain\Gateway\GatewayQueue;
use App\Infrastructure\Swamp\CancelSwampRun;
use App\Models\Operation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Asks Swamp to cancel the native run of an Operation whose intent to stop is recorded: a budget expiry, or a group that
 * ended. Swamp stops the run; the Gateway only forwards the request, so it runs on the queue and tries again when Swamp
 * does not confirm.
 */
final class CancelNativeRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public int $backoff = 30;

    public function __construct(public int $operationId)
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Operations->value);
    }

    public function handle(CancelSwampRun $cancel): void
    {
        $operation = Operation::query()->find($this->operationId);
        if ($operation instanceof Operation) {
            $cancel->execute($operation);
        }
    }
}
