<?php

declare(strict_types=1);

namespace App\Jobs\Operations;

use App\Actions\Operations\ExpireOperationAction;
use App\Domain\Gateway\GatewayQueue;
use App\Infrastructure\Swamp\SwampCommandStep;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The prompt projection of one Operation whose native run ended (docs/reference/operations.md#how-an-operation-ends). The event
 * observer dispatches it with the Operation's id when the ROOT run ends, on its own `operations-prompt` worker, so the end is projected
 * at once: a pass that runs meanwhile neither drops it nor holds it back. It runs the pass's own settlement for that one Operation,
 * including the settlement of an unreadable end check; the minute pass stays the retry.
 */
final class ProjectEndedNativeRun implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

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

    public function handle(SwampCommandStep $step, ExpireOperationAction $expire): void
    {
        (new ReconcileSwampOperations)->settleOne($this->operationId, $step, $expire);
    }
}
