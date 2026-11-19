<?php

declare(strict_types=1);

namespace App\Jobs\Fleet;

use App\Actions\Fleet\AdmitFleetRolloutAction;
use App\Actions\Fleet\ContinueReleaseFleetAction;
use App\Actions\Fleet\FleetRolloutPlanAction;
use App\Domain\Gateway\GatewayQueue;
use App\Domain\Shared\ResourceOperationException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Admits one `fleet.rollout` for the Nodes the plan names. A verified release queues it at once, and the scheduler queues
 * it once a day to catch drift between releases. Swamp runs the visits.
 */
final class AdmitFleetRollout implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * While Operations run, the job releases itself for another short observation. A pass of full Node converges can poll for
     * hours, so the job is bounded by time, not by attempts, until the daily pass takes over.
     */
    public const int RetryHours = 23;

    public int $timeout = 120;

    public int $uniqueFor = 3600;

    public ?int $releaseId = null;

    public function __construct(?int $releaseId = null)
    {
        $this->releaseId = $releaseId;
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Operations->value);
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(self::RetryHours);
    }

    public function uniqueId(): string
    {
        return $this->releaseId === null ? 'fleet-daily' : (string) $this->releaseId;
    }

    public function handle(FleetRolloutPlanAction $plan, AdmitFleetRolloutAction $admit): void
    {
        $fleet = app(ContinueReleaseFleetAction::class);
        if (($release = $fleet->current()) !== null && ($this->releaseId === null || $release->id === $this->releaseId)) {
            $delay = $fleet->execute($release);
            if ($delay !== null) {
                $this->release($delay);
            }

            return;
        }
        // A newer verified release owns its own admission. This job must not apply the old intent.
        if ($this->releaseId !== null) {
            return;
        }
        $selection = $plan->execute();
        if ($selection['status'] === 'waiting') {
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
            // No Node needs a visit, or the rollout is off: the next release or daily pass asks again.
            Log::info('No fleet rollout was admitted.', ['error_code' => $refusal->errorCode]);
        }
        if ($selection['waiting_for_cli'] ?? false) {
            // A partial rollout must retain the release-publication retry for its CI callers.
            $this->release(60);
        }
    }
}
