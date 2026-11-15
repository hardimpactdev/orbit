<?php

declare(strict_types=1);

namespace App\Jobs\Operations;

use App\Actions\Operations\ExpireOperationAction;
use App\Domain\Gateway\GatewayQueue;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampHandshakes;
use App\Models\Operation;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * The prompt projection of one Operation whose native run ended (docs/reference/operations.md#how-an-operation-ends). The event
 * observer dispatches it with the Operation's id when the ROOT run ends, on its own `operations-prompt` worker, so the end is projected
 * at once: a pass that runs meanwhile neither drops it nor holds it back. It runs the pass's own settlement for that one Operation,
 * including the settlement of an unreadable end check; the minute pass stays the retry. A read that serve refused with 429 is tried
 * once more after the serve window, so a finished run is not left for the minute pass because a burst used the window.
 */
final class ProjectEndedNativeRun implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** The second try exists only for a read that serve refused with 429. */
    public int $tries = 2;

    /** A second end notice of the same run, while this one waits or runs, adds nothing. */
    public int $uniqueFor = 120;

    public function __construct(public readonly int $operationId)
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::OperationsPrompt->value);
    }

    public function uniqueId(): string
    {
        return (string) $this->operationId;
    }

    public function handle(SwampCommandStep $step, ExpireOperationAction $expire, SwampHandshakes $handshakes): void
    {
        $refusals = $handshakes->refusals();
        (new ReconcileSwampOperations)->settleOne($this->operationId, $step, $expire);
        if ($handshakes->refusals() === $refusals || $this->attempts() >= $this->tries
            || Operation::query()->find($this->operationId)?->status->terminal() !== false) {
            return;
        }
        Log::info('A finished run is projected again after the serve window.', ['operation_id' => $this->operationId, 'delay_seconds' => $handshakes->retryDelay()]);
        $this->release($handshakes->retryDelay());
    }
}
