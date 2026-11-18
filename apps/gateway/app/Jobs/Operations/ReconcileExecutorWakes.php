<?php

declare(strict_types=1);

namespace App\Jobs\Operations;

use App\Domain\Gateway\GatewayQueue;
use App\Domain\Instances\Power\ExecutorWake;
use App\Domain\Instances\Power\ExecutorWakeNotices;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ReconcileExecutorWakes implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public int $uniqueFor = 60;

    public function __construct()
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::OperationsPrompt->value);
    }

    public function handle(ExecutorWake $wake, ExecutorWakeNotices $notices): void
    {
        foreach ([$wake->recoverPendingCompletions(...), $wake->recoverPendingHooks(...), $notices->relay(...)] as $handoff) {
            try {
                $handoff();
            } catch (Throwable $exception) {
                Log::warning('An executor wake handoff sweep failed; the next pass retries.', ['exception_type' => $exception::class]);
            }
        }
    }
}
