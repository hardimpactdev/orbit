<?php

declare(strict_types=1);

namespace App\Actions\Operations;

use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationExecutors;
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
        return DB::transaction(function () use ($operation, $targetEnding): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            if ($operation->status->terminal()) {
                return $operation;
            }
            $now = Carbon::now();
            $descendants = [];
            $frontier = [$operation->id];
            while ($frontier !== []) {
                $children = Operation::query()->whereIn('parent_operation_id', $frontier)->lockForUpdate()->get();
                $frontier = $children->modelKeys();
                foreach ($children as $child) {
                    if (! $child->status->terminal()) {
                        $descendants[] = $child;
                    }
                }
            }
            foreach ([$operation, ...$descendants] as $member) {
                if ($member->cancel_requested_at === null) {
                    $this->store->change($member, ['cancel_requested_at' => $now]);
                }
            }
            foreach (array_reverse($descendants) as $descendant) {
                $this->settleUnstarted($descendant);
            }
            $this->settleUnstarted($operation);
            $executors = app(OperationExecutors::class);
            if (! $operation->status->terminal() && $operation->status === OperationStatus::Waiting
                && isset($operation->state['executor_assistance'])
                && ! app(OperationChildren::class)->hasProvenNonterminalChild($operation)) {
                $this->store->change($operation, ['status' => OperationStatus::Cancelled, 'worker' => null,
                    'current_step' => null, 'lock_key' => null, 'finished_at' => $now, 'error_code' => 'operation.cancelled']);
                foreach ($operation->steps()->whereIn('status', [OperationStepStatus::Pending->value, OperationStepStatus::Running->value, OperationStepStatus::Waiting->value])->get() as $step) {
                    $this->store->change($operation, [], $step, ['status' => OperationStepStatus::Cancelled, 'finished_at' => $now, 'error_code' => 'operation.cancelled']);
                }
                $executors->completion($operation->kind)?->complete($operation, OperationOutcome::cancelled());
            }
            if (! $executors->swamp($operation->kind)) {
                $this->runner->cancelWaiting($operation, $targetEnding);
            }

            return $operation->refresh();
        });
    }

    private function settleUnstarted(Operation $operation): void
    {
        if ($this->store->cancelQueued($operation)) {
            $this->store->change($operation, ['error_code' => 'operation.cancelled']);
            app(OperationExecutors::class)->completion($operation->kind)?->complete($operation, OperationOutcome::cancelled());
        }
    }
}
