<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Operation;
use App\Models\OperationStep;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs one attempt of an operation in a queue worker (docs/reference/operations.md#how-an-operation-runs). It claims
 * the queued operation, decides what a step that a lost worker interrupted means, runs the kind under the operation
 * deadline, and stores the outcome. The target's lock ends with the operation.
 */
final readonly class OperationRunner
{
    /** The longest run of one operation. Its job may run 1,700 seconds, under the queue's `retry_after` of 1,800. */
    public const int DeadlineSeconds = 1_600;

    /** An operation that a worker started this often fails instead of starting again. */
    public const int MaximumAttempts = 3;

    public function __construct(
        private OperationStore $store,
        private OperationKinds $kinds,
        private OperationWorker $worker,
        private CommandDeadline $deadline,
        private OperationAttemptLease $lease,
    ) {}

    /**
     * Runs one attempt while holding the operation's lease. An error that escapes the attempt fails the operation
     * through its kind. When even that fails, for example while the database is locked, the error goes to the queue
     * and the lease ends with the attempt, so the sweeper queues the operation again.
     */
    public function run(int $operationId): void
    {
        $this->lease->hold($operationId, function () use ($operationId): void {
            $operation = Operation::query()->find($operationId);

            if (! $operation instanceof Operation || ! $this->store->claim($operation, $this->worker->current())) {
                return;
            }

            try {
                $this->attempt($operation);
            } catch (Throwable $exception) {
                Log::error('An operation attempt ended with an unexpected error.', ['operation' => $operation->id, 'error' => $exception->getMessage()]);

                try {
                    $operation->refresh();
                    $this->abandon($this->kinds->for($operation->kind), $operation, OperationOutcome::failed('operation.failed'), $this->failedStep($operation));
                } catch (Throwable) {
                    throw $exception;
                }
            }
        });
    }

    private function attempt(Operation $operation): void
    {
        try {
            $kind = $this->kinds->for($operation->kind);
        } catch (Throwable $exception) {
            Log::error('An operation has an unknown kind.', ['operation' => $operation->id, 'kind' => $operation->kind, 'error' => $exception->getMessage()]);
            $this->finish($operation, OperationOutcome::failed('operation.failed'), null);

            return;
        }

        // A step that failed before an earlier attempt could record the outcome has run: it never runs again.
        $failed = $this->failedStep($operation);

        if ($failed !== null) {
            $this->failLostStep($kind, $operation, $failed);

            return;
        }

        $lost = $this->interruptedStep($operation);

        if ($lost !== null && (! $lost->resumable || $operation->attempts > self::MaximumAttempts)) {
            $this->failLostStep($kind, $operation, $lost->name);

            return;
        }

        if ($operation->attempts > self::MaximumAttempts) {
            $this->abandon($kind, $operation, OperationOutcome::failed('operation.worker_lost'), null);

            return;
        }

        if ($lost !== null) {
            $this->store->change($operation, ['current_step' => null], $lost, ['status' => OperationStepStatus::Pending]);
        }

        $run = new OperationRun($operation, $this->store);
        // The run keeps the cleanup reserve of an API command, so the recovery after a step that ran out of time,
        // such as reading which release an interrupted activation selected, still has time to finish.
        $end = $this->deadline->startRequest(self::DeadlineSeconds, CommandDeadline::CleanupReserveSeconds);

        try {
            $outcome = $kind->run($operation, $run);
        } catch (OperationPlanChanged) {
            $this->abandon($kind, $operation, OperationOutcome::failed('operation.plan_changed'), null);

            return;
        } catch (OperationCancelled) {
            $this->abandon($kind, $operation, OperationOutcome::cancelled(), null);

            return;
        } catch (Throwable $exception) {
            Log::error('An operation ended with an unexpected error.', ['operation' => $operation->id, 'error' => $exception->getMessage()]);
            $this->abandon($kind, $operation, OperationOutcome::failed('operation.failed'), $this->failedStep($operation));

            return;
        } finally {
            $end();
        }

        $this->finish($operation, $outcome, $outcome->status === OperationStatus::Failed ? $this->failedStep($operation) : null);
    }

    /** Fails the operation at a step whose attempt was lost, so a person's command never runs twice. */
    private function failLostStep(OperationKind $kind, Operation $operation, string $name): void
    {
        $step = $this->step($operation, $name);

        if ($step instanceof OperationStep) {
            $this->store->change($operation, ['current_step' => null], $step, [
                'status' => OperationStepStatus::Failed,
                'finished_at' => $step->finished_at ?? Carbon::now(),
                'error_code' => 'operation.worker_lost',
            ]);
        }

        $this->abandon($kind, $operation, OperationOutcome::failed('operation.worker_lost'), $name);
    }

    /**
     * Ends an operation that never reached the queue or never left it, such as a cancel while queued.
     */
    public function finishWithoutRun(Operation $operation, OperationOutcome $outcome): void
    {
        $this->finish($operation, $outcome, null);
    }

    private function abandon(OperationKind $kind, Operation $operation, OperationOutcome $failure, ?string $failedStep): void
    {
        try {
            $outcome = $kind->abandon($operation, $failure, $failedStep);
        } catch (Throwable $exception) {
            Log::error('An operation kind could not finish its records.', ['operation' => $operation->id, 'error' => $exception->getMessage()]);
            $outcome = $failure;
        }

        $this->finish($operation, $outcome, $failedStep);
    }

    private function finish(Operation $operation, OperationOutcome $outcome, ?string $failedStep): void
    {
        $now = Carbon::now();
        $operation->refresh();
        $failed = $failedStep === null ? null : $this->step($operation, $failedStep);

        // A step that still runs after the kind returned can only belong to a run that threw past it.
        foreach ($operation->steps()->where('status', OperationStepStatus::Running->value)->get() as $running) {
            $this->store->change($operation, [], $running, ['status' => OperationStepStatus::Failed, 'finished_at' => $now]);
        }

        if ($outcome->status === OperationStatus::Cancelled) {
            foreach ($operation->steps()->where('status', OperationStepStatus::Pending->value)->get() as $pending) {
                $this->store->change($operation, [], $pending, ['status' => OperationStepStatus::Cancelled, 'finished_at' => $now]);
            }
        }

        // The failed step gets its error code in the same transaction as the terminal status. A step that is `failed`
        // without an error code is the mark of a hard failure, so a later attempt never mistakes it for a best-effort one.
        $copyError = $failed instanceof OperationStep && $failed->error_code === null && $outcome->errorCode !== null;
        $this->store->change($operation, [
            'status' => $outcome->status,
            'result' => $outcome->result,
            'error_code' => $outcome->errorCode,
            'failed_step' => $outcome->status === OperationStatus::Failed ? $failedStep : null,
            'current_step' => null,
            'lock_key' => null,
            'worker' => null,
            'finished_at' => $now,
        ], $copyError ? $failed : null, $copyError ? ['error_code' => $outcome->errorCode] : []);
    }

    /** The step a lost worker left running. */
    private function interruptedStep(Operation $operation): ?OperationStep
    {
        $step = $operation->steps()->where('status', OperationStepStatus::Running->value)->first();

        return $step instanceof OperationStep ? $step : null;
    }

    /** The step that ended a failed run: the failed step without an error code of its own, which best-effort steps have. */
    private function failedStep(Operation $operation): ?string
    {
        $step = $operation->steps()
            ->where('status', OperationStepStatus::Failed->value)
            ->whereNull('error_code')
            ->orderByDesc('position')
            ->first();

        return $step instanceof OperationStep ? $step->name : null;
    }

    private function step(Operation $operation, string $name): ?OperationStep
    {
        $step = $operation->steps()->where('name', $name)->first();

        return $step instanceof OperationStep ? $step : null;
    }
}
