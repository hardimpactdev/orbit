<?php

declare(strict_types=1);

namespace App\Actions\Operations;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationExecutors;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationRunner;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStepStatus;
use App\Domain\Operations\OperationStore;
use App\Models\Operation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Asks an operation to stop (docs/reference/operations.md#cancel-an-operation). A queued operation ends `cancelled`
 * at once; a running one stops before its next step. A terminal operation stays as it is.
 */
final readonly class CancelOperationAction
{
    public function __construct(private OperationStore $store, private OperationRunner $runner) {}

    public function execute(Operation $operation, bool $targetEnding = false): Operation
    {
        DB::transaction(function () use ($operation): void {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            if ($operation->status->terminal()) {
                return;
            }
            if ($this->native($operation)) {
                if ($operation->cancel_requested_at === null) {
                    $this->store->change($operation, ['cancel_requested_at' => Carbon::now()]);
                }

                return;
            }
            app(OperationExecutors::class)->cancelGuard($operation->kind)?->assertCancellable($operation);
            $now = Carbon::now();
            if ($operation->cancel_requested_at === null) {
                $this->store->change($operation, ['cancel_requested_at' => $now]);
            }
            $this->settleUnstarted($operation);
            $executors = app(OperationExecutors::class);
            if (! $operation->status->terminal() && $operation->status === OperationStatus::Waiting
                && isset($operation->state['executor_assistance'])) {
                $this->store->change($operation, ['status' => OperationStatus::Cancelled, 'worker' => null,
                    'current_step' => null, 'lock_key' => null, 'finished_at' => $now, 'error_code' => 'operation.cancelled']);
                foreach ($operation->steps()->whereIn('status', [OperationStepStatus::Pending->value, OperationStepStatus::Running->value, OperationStepStatus::Waiting->value])->get() as $step) {
                    $this->store->change($operation, [], $step, ['status' => OperationStepStatus::Cancelled, 'finished_at' => $now, 'error_code' => 'operation.cancelled']);
                }
                $executors->completion($operation->kind)?->complete($operation, OperationOutcome::cancelled());
            }
        });

        if (! $operation->status->terminal() && ! app(OperationExecutors::class)->swamp($operation->kind)) {
            $this->runner->cancelWaiting($operation, $targetEnding);
        }

        return $operation->refresh();
    }

    private function settleUnstarted(Operation $operation): void
    {
        if ($this->native($operation)) {
            return;
        }
        if ($this->store->cancelQueued($operation)) {
            $this->store->change($operation, ['error_code' => 'operation.cancelled']);
            app(OperationExecutors::class)->completion($operation->kind)?->complete($operation, OperationOutcome::cancelled());
        }
    }

    private function native(Operation $operation): bool
    {
        return DirectOperation::owns($operation) || app(OperationKinds::class)->directOnly($operation->kind);
    }
}
