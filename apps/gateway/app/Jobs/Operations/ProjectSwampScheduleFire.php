<?php

declare(strict_types=1);

namespace App\Jobs\Operations;

use App\Domain\Gateway\GatewayQueue;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampScheduledResults;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Records one calendar fire of a Schedule's own workflow after the poll found it finished. */
final class ProjectSwampScheduleFire implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $workflowId, public string $runId)
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Operations->value);
    }

    public function handle(SwampCommandStep $step): bool
    {
        $projected = $step->projectScheduleFire($this->workflowId, $this->runId);
        if ($projected) {
            SwampScheduledResults::done($this->runId);
        }

        return $projected;
    }

    public function failed(Throwable $exception): void
    {
        SwampScheduledResults::failed($this->runId);
    }
}
