<?php

declare(strict_types=1);

namespace App\Actions\Operations;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\NativeRunCancellation;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Proof\ProofRunSettlement;
use App\Domain\TaskVms\TaskVmImageRefreshIntent;
use App\Infrastructure\Swamp\CancelSwampRun;
use App\Jobs\Proof\CancelProofGroup;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

/** Cancels unstarted intent; a bound native workflow owns its terminal result, and Swamp stops it. */
final readonly class CancelOperationAction
{
    public function __construct(private OperationStore $store) {}

    public function execute(Operation $operation, bool $targetEnding = false): Operation
    {
        DB::transaction(function () use ($operation): void {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            if ($operation->status->terminal()) {
                return;
            }
            if (in_array($operation->status, [OperationStatus::Queued, OperationStatus::Running, OperationStatus::Waiting], true)
                && ! isset($operation->state['swamp_run_id']) && $operation->attempts === 0) {
                $this->store->change($operation, ['cancel_requested_at' => $operation->cancel_requested_at ?? now(),
                    'status' => OperationStatus::Cancelled, 'finished_at' => now(), 'error_code' => null,
                    'current_step' => null, 'failed_step' => null, 'lock_key' => null]);
            } elseif ($operation->cancel_requested_at === null) {
                $this->store->change($operation, ['cancel_requested_at' => now()]);
            }
        });

        $operation->refresh();
        // A proof owns a task group with a VM for each of its runs; cancelling the proof without the groups would leave the VMs running.
        if ($operation->kind === OperationKinds::ProofRun && $operation->status === OperationStatus::Cancelled) {
            foreach (ProofRunSettlement::children($operation->id) as $run) {
                DB::afterCommit(static fn () => CancelProofGroup::dispatch($run->id));
            }
        }
        // A refresh owns a task group with a VM too. One cancelled before its run started has no terminal event to project, so the group is cancelled here.
        if ($operation->kind === TaskVmImageRefreshIntent::Kind && $operation->status === OperationStatus::Cancelled) {
            DB::afterCommit(static fn () => CancelProofGroup::dispatch($operation->id, 'image-refresh'));
        }
        if (! $operation->status->terminal() && isset($operation->state['swamp_run_id'])) {
            if (DirectOperation::owns($operation) && app(DirectOperation::class)->usesNativeTerminal($operation)) {
                app(CancelSwampRun::class)->execute($operation);
            }
        } elseif (app(NativeRunCancellation::class)->outlivesTimeout($operation)) {
            // The Gateway ended it at its budget; the native run may still be going. Ask Swamp again.
            app(NativeRunCancellation::class)->request($operation, again: true);
        }

        return $operation;
    }
}
