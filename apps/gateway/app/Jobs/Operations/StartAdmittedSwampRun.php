<?php

declare(strict_types=1);

namespace App\Jobs\Operations;

use App\Actions\Operations\ExpireOperationAction;
use App\Domain\Gateway\GatewayQueue;
use App\Domain\Operations\OperationStatus;
use App\Domain\Proof\ProofChildStarts;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Models\Operation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Starts committed intent once. Admission copies are acknowledged before queuing; run-time copies are prepared from the committed intent. */
final class StartAdmittedSwampRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 150;

    public bool $failOnTimeout = true;

    public function __construct(public int $operationId)
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Operations->value);
    }

    public function handle(StartSwampRun $start, ExpireOperationAction $expire): void
    {
        $operation = Operation::query()->find($this->operationId);
        if ($operation === null) {
            return;
        }
        $children = app(ProofChildStarts::class);
        try {
            if ($operation->status !== OperationStatus::Queued || ! $children->release($operation)) {
                return;
            }
            $operation = $expire->execute($operation);
            if ($operation->status === OperationStatus::Queued) {
                $start->start($operation);
            }
        } finally {
            $children->advance($operation);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $operation = Operation::query()->find($this->operationId);
        if ($operation === null) {
            return;
        }
        try {
            if ($operation->status->terminal() || isset($operation->state['swamp_run_id'])) {
                return;
            }
            // A dead worker may have submitted before losing its confirmation. Never release that hold or resubmit.
            $uncertain = $operation->status === OperationStatus::Running;
            app(StartSwampRun::class)->refuse($operation, new ResourceOperationException(
                $uncertain ? 'swamp.start_uncertain' : 'swamp.start_job_failed',
                $uncertain ? 'The start worker stopped before confirming the native run. A native run may exist.'
                    : 'The queued native workflow start failed before submission.', 503));
        } finally {
            app(ProofChildStarts::class)->advance($operation);
        }
    }
}
