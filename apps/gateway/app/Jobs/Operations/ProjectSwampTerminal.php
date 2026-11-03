<?php

declare(strict_types=1);

namespace App\Jobs\Operations;

use App\Actions\Operations\ExpireOperationAction;
use App\Domain\Gateway\GatewayQueue;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampTerminalReadUnavailable;
use App\Models\Operation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Throwable;

/** Read exact terminal history on the existing operations queue, away from live event reception.
 * Only transport failures retry with capped exponential jitter until the run deadline; other failures end the job.
 */
final class ProjectSwampTerminal implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public const int RetrySeconds = 30;

    public const int MaximumRetrySeconds = 300;

    public int $tries = 0;

    public function __construct(public int $operationId, public string $runId, public ?string $status, public bool $atDeadline = false)
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Operations->value);
    }

    public function handle(SwampCommandStep $step): void
    {
        try {
            $this->project($step);
        } catch (Throwable $exception) {
            if ($this->job === null || $this->job instanceof SyncJob) {
                throw $exception;
            }
            $this->fail($exception);
        }
    }

    private function project(SwampCommandStep $step): void
    {
        $operation = Operation::query()->findOrFail($this->operationId);
        $selector = app(DirectOperation::class)->definition($operation->kind)['terminal_result'] ?? null;
        if ($selector === null) {
            return;
        }
        try {
            $step->projectTerminal($operation, ['id' => $this->runId, ...($this->status !== null ? ['status' => $this->status] : [])], $selector, $this->atDeadline);
        } catch (SwampTerminalReadUnavailable $exception) {
            $deadline = OperationRunBudgets::deadline($operation->refresh());
            if (! $this->atDeadline && ! $operation->status->terminal() && $deadline !== null && $deadline->isFuture()) {
                $ceiling = min(self::MaximumRetrySeconds, self::RetrySeconds * (2 ** min(4, max(1, $this->attempts()))));
                $this->release(random_int(intdiv($ceiling, 2), $ceiling));

                return;
            }
            $this->settleAtDeadline($operation, $exception);
        } catch (ResourceOperationException $exception) {
            $this->settleAtDeadline($operation, $exception);
        }
    }

    private function settleAtDeadline(Operation $operation, ResourceOperationException $exception): void
    {
        if (! $this->atDeadline) {
            throw $exception;
        }
        $expired = app(ExpireOperationAction::class)->execute($operation->refresh());
        if (! ExpireOperationAction::awaitsSettlement($expired)) {
            throw $exception;
        }
    }
}
