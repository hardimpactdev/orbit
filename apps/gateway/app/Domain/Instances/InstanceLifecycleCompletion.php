<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Data\Instances\InstanceData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Instances\Transfer\InstanceTransferStatus;
use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Models\Instance;
use App\Models\InstanceTransfer;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Projects the proven terminal receipt. Swamp performs cleanup and rollback as explicit effects. */
final readonly class InstanceLifecycleCompletion implements OperationCompletion
{
    public function __construct(private RecordEventBroadcaster $broadcaster) {}

    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        if ($outcome->status !== OperationStatus::Succeeded) {
            $this->failed($operation, $outcome);

            return;
        }
        if ($operation->kind === 'instance.transfer_destination') {
            return;
        }
        $ids = $operation->kind === 'instance.register'
            ? array_column(InstanceLifecycleInput::members($operation->input), 'instance_id') : [$operation->input['instance_id']];
        foreach (Instance::query()->with(['project', 'node', 'routes.targets'])->whereKey($ids)->get() as $instance) {
            $created = $operation->kind === 'instance.create' && ($operation->input['created'] ?? false);
            $this->broadcaster->broadcast($created ? RecordEventType::InstanceCreated : RecordEventType::InstanceUpdated,
                $instance->id, InstanceData::fromModel($instance)->toArray());
        }
    }

    private function failed(Operation $operation, OperationOutcome $outcome): void
    {
        $code = $outcome->errorCode ?? 'operation.cancelled';
        $step = $operation->steps()->where('status', 'failed')->orderByDesc('id')->value('name');
        $instance = Instance::query()->find(ValidatedData::integer($operation->input['instance_id']));
        if ($instance !== null && ! ($operation->kind === 'instance.create'
            && ($operation->input['initial_status'] ?? null) === 'active' && ($operation->input['first_setup_pending'] ?? true) === false)) {
            $instance->update(['failed_step' => is_string($step) ? $step : $instance->failed_step ?? 'lifecycle', 'error_code' => $code]);
        }
        if ($operation->kind === 'instance.transfer') {
            $pending = $outcome->status === OperationStatus::Cancelled
                && app(TransferLifecycleExecution::class)->cancellationPendingRecovery($operation);
            $transfer = InstanceTransfer::query()->findOrFail(ValidatedData::string($operation->input['transfer_id']));
            $transfer->update([
                'status' => InstanceTransferStatus::Failed, 'error_code' => $code,
                ...($pending ? ['recovery_evidence' => [...($transfer->recovery_evidence ?? []),
                    'rollback_pending' => true, 'capture_pending' => true, 'transfer_operation_id' => $operation->id]] : []),
            ]);
        }
    }
}
