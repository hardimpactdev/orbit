<?php

declare(strict_types=1);

namespace App\Jobs\Fleet;

use App\Actions\Fleet\AdmitFleetRolloutAction;
use App\Domain\Gateway\GatewayQueue;
use App\Domain\Shared\ResourceOperationException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Admits one `fleet.rollout` for the Nodes the plan names. A verified release queues it, and the scheduler queues it
 * once a day when the window of the non-daytime Nodes opens. Swamp runs the visits.
 */
final class AdmitFleetRollout implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 120;

    public function __construct()
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Operations->value);
    }

    public function handle(AdmitFleetRolloutAction $admit): void
    {
        try {
            $admit->execute();
        } catch (ResourceOperationException $refusal) {
            // No Node needs a visit, the rollout is off, or one runs: the next release or window asks again.
            if (! in_array($refusal->errorCode, ['fleet.rollout_not_ready', 'fleet.rollout_busy'], true)) {
                throw $refusal;
            }
            Log::info('No fleet rollout was admitted.', ['error_code' => $refusal->errorCode]);
        }
    }
}
