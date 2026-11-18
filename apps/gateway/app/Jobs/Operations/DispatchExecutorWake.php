<?php

declare(strict_types=1);

namespace App\Jobs\Operations;

use App\Domain\Gateway\GatewayQueue;
use App\Domain\Instances\Power\ExecutorWake;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class DispatchExecutorWake implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $operationId)
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::OperationsPrompt->value);
    }

    public function handle(ExecutorWake $wake): void
    {
        try {
            $wake->continueHandoff($this->operationId);
        } catch (Throwable $exception) {
            Log::warning('The deferred executor wake handoff failed; the wake sweep retries.',
                ['operation_id' => $this->operationId, 'exception_type' => $exception::class]);
        }
    }
}
