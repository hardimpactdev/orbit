<?php

declare(strict_types=1);

namespace App\Jobs\Operations;

use App\Domain\Gateway\GatewayQueue;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampScheduledResults;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Projects exact scheduled history after the poll found the run finished. */
final class ProjectSwampScheduledTerminal implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $workflowId, public string $runId, public ?string $status)
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Operations->value);
    }

    public function handle(SwampCommandStep $step): bool
    {
        $projected = $step->projectScheduledTerminal($this->workflowId, ['id' => $this->runId,
            ...($this->status !== null ? ['status' => $this->status] : [])]);
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
