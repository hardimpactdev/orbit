<?php

declare(strict_types=1);

namespace App\Jobs\Proof;

use App\Domain\Gateway\GatewayQueue;
use App\Domain\Proof\ProofRunIntent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Admits one `proof.run`: the checks of the commit, the reads of GitHub, the groups and VMs, and the hand-over of each run to Swamp.
 * The request of `proof:run` only records the proof and queues this job, so a release that reloads PHP-FPM never ends a half-done
 * admission; the release drains the queue workers (docs/cli/proof/run.mmd). It runs once: a failure is on the proof Operation, and
 * the watchdog ends a proof whose job was lost.
 */
final class AdmitProofRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Each hand-over to Swamp is answered within 120 s. */
    public int $timeout = 1200;

    public function __construct(public int $operationId)
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Tasks->value);
    }

    public function handle(ProofRunIntent $intent): void
    {
        $intent->admit($this->operationId);
    }
}
