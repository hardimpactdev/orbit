<?php

declare(strict_types=1);

namespace App\Jobs\Operations;

use App\Domain\Gateway\GatewayQueue;
use App\Domain\Operations\DirectOperation;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampTerminalReadUnavailable;
use App\Models\Operation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Projects the terminal result of one Operation as soon as the run observer sees its run end. It is not unique, so a pass of
 * `ReconcileSwampOperations` that already runs, or waits, cannot drop it. The run's notice can come before Swamp writes its
 * history, so it reads again for a few seconds. The minute pass stays the safety net for a run that stays unread.
 */
final class ProjectSwampOperation implements ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    /** Only the wait for the history retries; any other exception fails the job once, as a pass logs it and moves on. */
    public int $maxExceptions = 1;

    private const int RetrySeconds = 1;

    public function __construct(public int $operationId)
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Operations->value);
    }

    public function handle(SwampCommandStep $step, DirectOperation $direct): void
    {
        $operation = Operation::query()->find($this->operationId);
        $selector = $operation === null ? null : ($direct->definition($operation->kind)['terminal_result'] ?? null);
        $run = $operation?->state['swamp_run_id'] ?? null;
        if ($selector === null || ! is_string($run)) {
            return;
        }
        try {
            $step->projectTerminal($operation, ['id' => $run], $selector);
        } catch (SwampTerminalReadUnavailable) {
            if ($this->attempts() < $this->tries) {
                $this->release(self::RetrySeconds);
            }
        }
    }

    /** Remote text can contain inputs: log identity and type only. The minute pass settles the Operation. */
    public function failed(Throwable $exception): void
    {
        Log::warning('The Operation projection job failed; the next reconcile pass settles it.', ['operation_id' => $this->operationId, 'exception_type' => $exception::class]);
    }
}
