<?php

declare(strict_types=1);

namespace App\Jobs\Proof;

use App\Domain\Gateway\GatewayQueue;
use App\Domain\Proof\ProofGroup;
use App\Models\Operation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Cancels the task group of a proof.run that was cancelled, which destroys its VM. The admission is refused while another
 * operation holds the group, so the job tries again; the cancel is keyed, so a retry never admits a second one.
 */
final class CancelProofGroup implements ShouldQueue
{
    use Queueable;

    public int $tries = 12;

    public int $backoff = 10;

    /** @param string $trigger who cancels: the Activity trigger of the cancel, such as `proof-run` or `image-refresh` */
    public function __construct(public int $operationId, public string $trigger = ProofGroup::Trigger)
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Operations->value);
    }

    public function handle(ProofGroup $group): void
    {
        $group->cancel(Operation::query()->findOrFail($this->operationId), $this->trigger);
    }
}
