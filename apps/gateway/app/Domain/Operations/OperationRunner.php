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
    ) {}

    public function run(int $operationId): void
    {
        $operation = Operation::query()->find($operationId);

        if (! $operation instanceof Operation || ! $this->store->claim($operation, $this->worker->current())) {
            return;
        }

        try {
            $kind = $this->kinds->for($operation->kind);
        } catch (Throwable $exception) {
            Log::error('An operation has an unknown kind.', ['operation' => $operation->id, 'kind' => $operation->kind, 'error' => $exception->getMessage()]);
            $this->finish($operation, OperationOutcome::failed('operation.failed'), null);

            return;
        }

        $lost = $this->interruptedStep($operation);

        if ($lost !== null && (! $lost->resumable || $operation->attempts > self::MaximumAttempts)) {
            $this->store->change($operation, ['current_step' => null], $lost, [
                'status' => OperationStepStatus::Failed,
                'finished_at' => Carbon::now(),
                'error_code' => 'operation.worker_lost',
            ]);
            $this->abandon($kind, $operation, OperationOutcome::failed('operation.worker_lost'), $lost->name);

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

        try {
            $outcome = $this->deadline->within(self::DeadlineSeconds, static fn (): OperationOutcome => $kind->run($operation, $run));
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
        }

        $this->finish($operation, $outcome, $outcome->status === OperationStatus::Failed ? $this->failedStep($operation) : null);
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

        if ($failed instanceof OperationStep && $failed->error_code === null && $outcome->errorCode !== null) {
            $this->store->change($operation, [], $failed, ['error_code' => $outcome->errorCode]);
        }

        // A step that still runs after the kind returned can only belong to a run that threw past it.
        foreach ($operation->steps()->where('status', OperationStepStatus::Running->value)->get() as $running) {
            $this->store->change($operation, [], $running, ['status' => OperationStepStatus::Failed, 'finished_at' => $now]);
        }

        if ($outcome->status === OperationStatus::Cancelled) {
            foreach ($operation->steps()->where('status', OperationStepStatus::Pending->value)->get() as $pending) {
                $this->store->change($operation, [], $pending, ['status' => OperationStepStatus::Cancelled, 'finished_at' => $now]);
            }
        }

        $this->store->change($operation, [
            'status' => $outcome->status,
            'result' => $outcome->result,
            'error_code' => $outcome->errorCode,
            'failed_step' => $outcome->status === OperationStatus::Failed ? $failedStep : null,
            'current_step' => null,
            'lock_key' => null,
            'worker' => null,
            'finished_at' => $now,
        ]);
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
