<?php

declare(strict_types=1);

namespace App\Jobs\Operations;

use App\Actions\Operations\ExpireOperationAction;
use App\Domain\Gateway\GatewayQueue;
use App\Domain\Operations\OperationBroadcaster;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Operation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** One exact uncertain-start lookup, outside the observer's deadline and event reception loop. */
final class RecoverUnboundSwampRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $operationId)
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Operations->value);
    }

    public function handle(SwampCommandStep $step): void
    {
        $operation = Operation::query()->find($this->operationId);
        if ($operation === null || ($operation->status->terminal() && ! ExpireOperationAction::awaitsSettlement($operation))) {
            return;
        }
        $step->recoverUnbound($operation);
        $operation->refresh();
        if (isset($operation->state['swamp_run_id'])) {
            // A new binding needs a global record notice so the observer attaches to its root.
            app(OperationBroadcaster::class)->changed($operation, true);
        }
    }
}
