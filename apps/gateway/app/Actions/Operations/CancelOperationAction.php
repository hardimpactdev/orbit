<?php

declare(strict_types=1);

namespace App\Actions\Operations;

use App\Domain\Instances\DevelopmentInstanceCreateProjection;
use App\Domain\Instances\InstanceCompleteProjection;
use App\Domain\Operations\NativeRunCancellation;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Operations\RetiredOperationSettlement;
use App\Domain\Proof\ProofRunSettlement;
use App\Domain\Proof\ProofRunWatch;
use App\Domain\Shared\ResourceOperationException;
use App\Jobs\Proof\CancelProofGroup;
use App\Models\Operation;
use App\Models\Task;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use function Illuminate\Support\defer;

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
        // A bound run, or one the Gateway timed out that may still be going: the request is recorded (again, so an earlier request whose
        // forward window has closed cannot swallow it) and the caller gets its answer. Swamp is asked after the response, through the
        // one send path and its rule; while a database copy runs that path holds it, and the reconciler stays the retry.
        if ((! $operation->status->terminal() && isset($operation->state['swamp_run_id'])) || app(NativeRunCancellation::class)->outlivesTimeout($operation)) {
            $cancellation = app(NativeRunCancellation::class);
            if ($cancellation->request($operation, again: true, reconcile: false)) {
                defer(static function () use ($operation, $cancellation): void {
                    try {
                        $cancellation->forward($operation);
                    } catch (ResourceOperationException $exception) {
                        Log::warning('operation.cancel_forward_deferred', ['operation_id' => $operation->id, 'error_code' => $exception->errorCode]);
                    }
                }, always: true);
            }
        }

        return $operation;
    }
}
