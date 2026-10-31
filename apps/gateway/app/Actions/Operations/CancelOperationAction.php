<?php

declare(strict_types=1);

namespace App\Actions\Operations;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationAttemptLease;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationExecutionContract;
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
            if ($this->cancelUnstartedRetired($operation)) {
                return;
            }
            if ($this->native($operation)) {
                if ($operation->cancel_requested_at === null) {
                    $this->store->change($operation, ['cancel_requested_at' => Carbon::now()]);
                }

                return;
            }
            $this->assertCancellableTree($operation);
            $now = Carbon::now();
            $descendants = [];
            $cancelled = clone $operation;
            $cancelled->cancel_requested_at = $now;
            $continue = app(OperationExecutors::class)->swamp($operation->kind)
                && app(OperationExecutionContract::class)->cancellationAllowsRun($cancelled);
            $frontier = $operation->cancel_requested_at === null && ! $continue ? [$operation->id] : [];
            while ($frontier !== []) {
                $children = Operation::query()->whereIn('parent_operation_id', $frontier)->whereNull('cancel_requested_at')
                    ->whereNotIn('status', [OperationStatus::Succeeded->value, OperationStatus::Failed->value, OperationStatus::Cancelled->value])->lockForUpdate()->get();
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
                $this->settleUnprovableDescendants($operation);
                $this->store->change($operation, ['status' => OperationStatus::Cancelled, 'worker' => null,
                    'current_step' => null, 'lock_key' => null, 'finished_at' => $now, 'error_code' => 'operation.cancelled']);
                foreach ($operation->steps()->whereIn('status', [OperationStepStatus::Pending->value, OperationStepStatus::Running->value, OperationStepStatus::Waiting->value])->get() as $step) {
                    $this->store->change($operation, [], $step, ['status' => OperationStepStatus::Cancelled, 'finished_at' => $now, 'error_code' => 'operation.cancelled']);
                }
                $executors->completion($operation->kind)?->complete($operation, OperationOutcome::cancelled());
            }
        });

        if ($this->unsubmitted($operation)) {
            app(OperationAttemptLease::class)->hold($operation->id, function () use ($operation): void {
                DB::transaction(function () use ($operation): void {
                    $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
                    if ($this->unsubmitted($operation) && $this->store->cancelQueued($operation)) {
                        $this->store->change($operation, ['error_code' => 'operation.cancelled']);
                    }
                });
            });
        }

        if (! $operation->status->terminal() && ! app(OperationExecutors::class)->swamp($operation->kind)) {
            $this->runner->cancelWaiting($operation, $targetEnding);
        }

        return $operation->refresh();
    }

    private function assertCancellableTree(Operation $operation): void
    {
        $executors = app(OperationExecutors::class);
        $frontier = [$operation];
        while ($frontier !== []) {
            $ids = [];
            foreach ($frontier as $member) {
                if (! $member->status->terminal()) {
                    $executors->cancelGuard($member->kind)?->assertCancellable($member);
                }
                $ids[] = $member->id;
            }
            $frontier = Operation::query()->whereIn('parent_operation_id', $ids)->orderBy('id')->lockForUpdate()->get()->all();
        }
    }

    /** Abandoned admissions cannot execute or project, but must release their own holds. */
    private function settleUnprovableDescendants(Operation $parent): void
    {
        $descendants = [];
        $frontier = [$parent->id];
        while ($frontier !== []) {
            $children = Operation::query()->whereIn('parent_operation_id', $frontier)->lockForUpdate()->get();
            $frontier = $children->modelKeys();
            foreach ($children as $child) {
                if (! $child->status->terminal()) {
                    $descendants[] = $child;
                }
            }
        }
        foreach (array_reverse($descendants) as $child) {
            if ($this->native($child)) {
                continue;
            }
            $now = Carbon::now();
            $this->store->change($child, ['status' => OperationStatus::Cancelled, 'worker' => null, 'current_step' => null,
                'lock_key' => null, 'cancel_requested_at' => $child->cancel_requested_at ?? $now, 'finished_at' => $now,
                'error_code' => 'operation.child_unproven', 'state' => [...($child->state ?? []), 'executor_assistance' => ['error_code' => 'operation.child_unproven']]]);
            foreach ($child->steps()->whereIn('status', [OperationStepStatus::Pending->value, OperationStepStatus::Running->value, OperationStepStatus::Waiting->value])->get() as $step) {
                $this->store->change($child, [], $step, ['status' => OperationStepStatus::Cancelled, 'finished_at' => $now, 'error_code' => 'operation.child_unproven']);
            }
        }
    }

    private function settleUnstarted(Operation $operation): void
    {
        if ($this->cancelUnstartedRetired($operation)) {
            return;
        }
        if ($this->native($operation)) {
            return;
        }
        if ($this->store->cancelQueued($operation)) {
            $this->store->change($operation, ['error_code' => 'operation.cancelled']);
            app(OperationExecutors::class)->completion($operation->kind)?->complete($operation, OperationOutcome::cancelled());
        }
    }

    /** Cancel only untouched polled admissions after their execution contract was deleted. */
    private function cancelUnstartedRetired(Operation $operation): bool
    {
        $state = $operation->state ?? [];
        $target = $state['executor_target'] ?? null;
        if (! app(OperationKinds::class)->directOnly($operation->kind) || DirectOperation::owns($operation)
            || $operation->status !== OperationStatus::Queued || $operation->attempts !== 0
            || $operation->started_at !== null || $operation->worker !== null
            || array_keys($state) !== ['executor_target'] || ! is_array($target) || count($target) !== 3
            || array_diff(array_keys($target), ['node_id', 'target_type', 'target_id']) !== []
            || ($target['node_id'] ?? null) !== $operation->node_id || ($target['target_type'] ?? null) !== $operation->target_type
            || ($target['target_id'] ?? null) !== $operation->target_id
            || $operation->steps()->exists() || $operation->children()->exists()) {
            return false;
        }
        $this->store->change($operation, ['cancel_requested_at' => $operation->cancel_requested_at ?? Carbon::now()]);
        if (! $this->store->cancelQueued($operation)) {
            return false;
        }
        $this->store->change($operation, ['error_code' => 'operation.cancelled']);

        return true;
    }

    private function native(Operation $operation): bool
    {
        return DirectOperation::owns($operation) || app(OperationKinds::class)->directOnly($operation->kind);
    }

    /** Only admission can establish that no native request has ever been attempted. */
    private function unsubmitted(Operation $operation): bool
    {
        $definition = app(DirectOperation::class)->definition($operation->kind);
        $state = $operation->state ?? [];
        $target = $state['executor_target'] ?? null;

        return $definition !== null && DirectOperation::owns($operation)
            && ($state['swamp_submission'] ?? null) === 'unattempted'
            && array_diff(array_keys($state), [$definition['writer_state'], 'swamp_submission', 'executor_target']) === []
            && is_array($target) && count($target) === 3
            && array_diff(array_keys($target), ['node_id', 'target_type', 'target_id']) === []
            && ($target['node_id'] ?? null) === $operation->node_id && ($target['target_type'] ?? null) === $operation->target_type
            && ($target['target_id'] ?? null) === $operation->target_id
            && $operation->status === OperationStatus::Queued && $operation->attempts === 0
            && $operation->cancel_requested_at !== null && $operation->started_at === null && $operation->dispatched_at === null
            && $operation->finished_at === null && $operation->worker === null && $operation->lock_key === null
            && $operation->current_step === null && $operation->result === null && $operation->parent_operation_id === null
            && ! $operation->steps()->exists() && ! $operation->children()->exists();
    }
}
