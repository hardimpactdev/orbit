<?php

declare(strict_types=1);

namespace App\Jobs\Operations;

use App\Domain\Gateway\GatewayQueue;
use App\Infrastructure\Swamp\SwampCommandStep;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Records the step times of a finished run once serve's window has ended, after the first read of them was refused with 429. */
final class FillNativeRunStepTimes implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $operationId, public readonly string $runId)
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Operations->value);
    }

    public function handle(SwampCommandStep $step): void
    {
        $step->retryStepTimes($this->operationId, $this->runId);
    }
}
