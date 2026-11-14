<?php

declare(strict_types=1);

namespace App\Actions\Operations;

use App\Domain\Instances\DevelopmentInstanceCreateProjection;
use App\Domain\Instances\InstanceCompleteProjection;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\NativeRunCancellation;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Operations\RetiredOperationSettlement;
use App\Domain\Proof\ProofRunSettlement;
use App\Domain\Proof\ProofRunWatch;
use App\Infrastructure\Swamp\CancelSwampRun;
use App\Jobs\Proof\CancelProofGroup;
use App\Models\Operation;
use App\Models\Task;
use App\Support\ValidatedData;
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
                && ! isset($operation->state['swamp_run_id']) && $operation->attempts === 0
                && ! ($operation->status === OperationStatus::Running && isset($operation->state['swamp_start']))) {
                $provisioning = $operation->kind === 'instance.create'
                    ? ValidatedData::object($operation->input['direct_provisioning'] ?? []) : [];
                $recovery = is_array($container = $provisioning['container'] ?? null)
                    && ($provisioning['already_active'] ?? false) !== true
                    ? app(DevelopmentInstanceCreateProjection::class)->recovery($operation, ValidatedData::object($container)) : null;
                // A completion cancelled before its run keeps the clone for a retry, as a cancelled run does.
                $recovery = $operation->kind === 'instance.complete' ? InstanceCompleteProjection::cancelledBeforeRun($operation) : $recovery;
                $this->store->change($operation, ['cancel_requested_at' => $operation->cancel_requested_at ?? now(),
                    'status' => OperationStatus::Cancelled, 'finished_at' => now(), 'error_code' => null,
                    'current_step' => null, 'failed_step' => null, 'lock_key' => null,
                    ...($recovery === null ? [] : ['result' => [...($operation->result ?? []), 'recovery' => $recovery]])]);
            } elseif ($operation->cancel_requested_at === null) {
                $this->store->change($operation, ['cancel_requested_at' => now()]);
            }
        });

        $operation->refresh();
        if ($operation->status === OperationStatus::Cancelled && isset($operation->input['capacity_request']) && $operation->target_type === 'task_group') {
            Task::topLevel()->whereKey($operation->target_id)->update(['capacity_wait_reason' => null]);
        }
        // A kind that left the registry has no run to stop and no reconciler read: the request ends it here.
        if (! $operation->status->terminal() && RetiredOperationSettlement::retired($operation)) {
            return app(RetiredOperationSettlement::class)->settle($operation);
        }
        // A proof owns a task group with a VM for each of its runs; cancelling the proof without the groups would leave the VMs running.
        if ($operation->kind === OperationKinds::ProofRun && $operation->status === OperationStatus::Cancelled) {
            foreach (ProofRunSettlement::children($operation->id) as $run) {
                // A run that kept its VM (`--keep-failed`) keeps it until its time is up.
                if (ProofRunWatch::keeps($run)) {
                    continue;
                }
                DB::afterCommit(static fn () => CancelProofGroup::dispatch($run->id));
            }
        }
        if (! $operation->status->terminal() && isset($operation->state['swamp_run_id'])) {
            if (DirectOperation::owns($operation) && app(DirectOperation::class)->usesNativeTerminal($operation)) {
                // While a database copy runs, the request is recorded and the reconciler forwards it when the copy has ended.
                if (app(NativeRunCancellation::class)->holdsForCopy($operation)) {
                    // Recorded again, so an earlier request whose forward window has closed cannot swallow this one.
                    app(NativeRunCancellation::class)->request($operation, again: true);
                } else {
                    app(CancelSwampRun::class)->execute($operation);
                }
            }
        } elseif (app(NativeRunCancellation::class)->outlivesTimeout($operation)) {
            // The Gateway ended it at its budget; the native run may still be going. Ask Swamp again.
            app(NativeRunCancellation::class)->request($operation, again: true);
        }

        return $operation;
    }
}
