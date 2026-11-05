<?php

declare(strict_types=1);

namespace App\Jobs\Proof;

use App\Domain\Gateway\GatewayQueue;
use App\Domain\Proof\ProofRunContinuation;
use App\Models\Operation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Admits the next step of a proof after a task operation of its group ended. The admission can be refused for a moment
 * while the group still records the result, so the job tries again; if it never succeeds, the proof closes failed.
 */
final class AdvanceProofRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 12;

    public int $backoff = 10;

    public function __construct(public int $operationId)
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Operations->value);
    }

    public function handle(ProofRunContinuation $proof): void
    {
        $proof->advance(Operation::query()->findOrFail($this->operationId));
    }

    public function failed(Throwable $exception): void
    {
        app(ProofRunContinuation::class)->abandon(Operation::query()->findOrFail($this->operationId));
    }
}
