<?php

declare(strict_types=1);

namespace App\Jobs\Proof;

use App\Domain\Gateway\GatewayQueue;
use App\Domain\Proof\ProofRunCleanup;
use App\Models\Operation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Cancels the task group of a proof.run that ended without its own cancel step, which destroys its VM. The admission is refused while another
 * operation holds the group, so the job tries again; the cancel is keyed, so a retry never admits a second one.
 */
final class CancelProofGroup implements ShouldQueue
{
    use Queueable;

    public int $tries = 12;

    public int $backoff = 10;

    public function __construct(public int $operationId)
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Operations->value);
    }

    public function handle(ProofRunCleanup $proof): void
    {
        $proof->cancelGroup(Operation::query()->findOrFail($this->operationId));
    }
}
