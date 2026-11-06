<?php

declare(strict_types=1);

namespace App\Jobs\Fleet;

use App\Actions\Fleet\AdmitFleetRolloutAction;
use App\Actions\Fleet\FleetRolloutPlanAction;
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

    /** A minute apart: CI publishes the CLI release minutes after the release goes live, and an ancestor's stands in after 30. */
    public int $tries = 40;

    public int $timeout = 120;

    public int $uniqueFor = 2400;

    public function __construct()
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Operations->value);
    }

    public function handle(FleetRolloutPlanAction $plan, AdmitFleetRolloutAction $admit): void
    {
        if ($plan->execute()['status'] === 'waiting') {
            $this->release(60);

            return;
        }
        try {
            $admit->execute();
        } catch (ResourceOperationException $refusal) {
            if ($refusal->errorCode === 'fleet.rollout_busy') {
                // The running pass captured an earlier commit; this one admits once it ends.
                $this->release(60);

                return;
            }
            if ($refusal->errorCode !== 'fleet.rollout_not_ready') {
                throw $refusal;
            }
            // No Node needs a visit, or the rollout is off: the next release or window asks again.
            Log::info('No fleet rollout was admitted.', ['error_code' => $refusal->errorCode]);
        }
    }
}
