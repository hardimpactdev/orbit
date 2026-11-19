<?php

declare(strict_types=1);

namespace App\Jobs\Operations;

use App\Actions\Operations\ExpireOperationAction;
use App\Domain\Gateway\GatewayQueue;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Proof\ProofChildStarts;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Models\Operation;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Throwable;

/**
 * Starts committed intent once. Admission copies are acknowledged before queuing; run-time copies are prepared from the committed intent.
 * Starts run on their own workers, side by side: the status transition in StartSwampRun::begin admits one submission per Operation,
 * and a target hold keeps a second Operation for the same held target from being admitted until the first ends.
 */
final class StartAdmittedSwampRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 150;

    public bool $failOnTimeout = true;

    public function __construct(public int $operationId)
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::OperationStarts->value);
    }

    public function handle(StartSwampRun $start, ExpireOperationAction $expire): void
    {
        $jobStarted = microtime(true);
        $operation = Operation::query()->find($this->operationId);
        if ($operation === null) {
            return;
        }
        if ($operation->status !== OperationStatus::Queued) {
            return;
        }
        if (app(DirectOperation::class)->definition($operation) === null) {
            // A retained worker may not ship the new handler. Leave the work for a compatible worker.
            Bus::dispatch((new self($operation->id))->delay(now()->addSeconds(15)));

            return;
        }
        $release = app(ProofChildStarts::class)->release($operation);
        if ($release instanceof CarbonImmutable) {
            Bus::dispatch((new self($operation->id))->delay($release));

            return;
        }
        if (! $release) {
            return;
        }
        $operation = $expire->execute($operation);
        if ($operation->status === OperationStatus::Queued) {
            $start->start($operation, $jobStarted);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $operation = Operation::query()->find($this->operationId);
        if ($operation === null || app(DirectOperation::class)->definition($operation) === null) {
            return;
        }
        if ($operation->status->terminal() || isset($operation->state['swamp_run_id'])) {
            return;
        }
        // A dead worker may have submitted before losing its confirmation. Never release that hold or resubmit.
        $uncertain = $operation->status === OperationStatus::Running;
        app(StartSwampRun::class)->refuse($operation, new ResourceOperationException(
            $uncertain ? 'swamp.start_uncertain' : 'swamp.start_job_failed',
            $uncertain ? 'The start worker stopped before confirming the native run. A native run may exist.'
                : 'The queued native workflow start failed before submission.', 503));
    }
}
