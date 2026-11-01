<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Nodes\NodeRemovalRecords;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Terminal native cleanup projection; provider absence alone never removes Node records. */
final readonly class TaskVmDestroyProjection implements DirectOperationProjection
{
    public function __construct(private TaskVmDestroyIntent $intent, private NodeRemovalRecords $nodes) {}

    public function input(Operation $operation): array
    {
        return $this->intent->input($operation);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $result = ValidatedData::object($data['result'] ?? []);
            if ($operation->status->terminal()) {
                return app(DirectOperation::class)->project($operation, $data, $result);
            }
            $vm = $this->intent->owned($operation, lock: true);
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if ($data['status'] === OperationStatus::Succeeded->value) {
                $this->record($vm, $operation->input, $result);
            } elseif (in_array($data['status'], [OperationStatus::Failed->value, OperationStatus::Cancelled->value], true)) {
                $vm->update(['error_code' => $data['error_code'] ?? 'task_vm.destroy_failed', 'error_message' => 'The task VM destruction did not complete.']);
            }

            return $projected;
        });
    }

    /** The parent removes workspace records first, inside the same terminal transaction.
     * @param  array<string,mixed>  $captured
     * @param  array<string,mixed>  $result
     */
    public function record(TaskVm $vm, array $captured, array $result): void
    {
        DB::transaction(function () use ($vm, $captured, $result): void {
            $vm = TaskVm::query()->lockForUpdate()->findOrFail($vm->id);
            $this->intent->assertCaptured($vm, $captured);
            $this->intent->assertRemovedWorkspace($vm);
            if (($result['task_vm_id'] ?? null) !== $vm->id || ($result['guest_node_id'] ?? null) !== $vm->node_id
                || ($result['destroyed'] ?? null) !== true || ($result['cleanup_complete'] ?? null) !== true) {
                throw new ResourceOperationException('operation.invalid_result', 'Destroy requires the completed same-VM native cleanup result.', 422);
            }
            if (($captured['node_removal'] ?? null) !== null) {
                $snapshot = ValidatedData::object($captured['node_removal']);
                $removal = ValidatedData::object($result['removal'] ?? []);
                if (($snapshot['node_id'] ?? null) !== $vm->node_id || ($snapshot['task_vm_id'] ?? null) !== $vm->id
                    || ($removal['node_id'] ?? null) !== $vm->node_id || ($removal['removed'] ?? null) !== true) {
                    throw new ResourceOperationException('operation.invalid_result', 'Destroy requires its captured Node and completed removal.', 422);
                }
                $this->nodes->remove($snapshot, $removal);
                $vm->refresh();
            } elseif ($vm->node_id !== null) {
                throw new ResourceOperationException('operation.invalid_result', 'Destroy requires cleanup of its recorded guest Node.', 422);
            }
            $vm->update(['state' => TaskVmState::Destroyed, 'destroyed_at' => now(), 'pi_token' => null,
                'model_key' => null, 'provider_bootstrap' => null, 'worker_stop_required' => false,
                'error_code' => null, 'error_message' => null, ...$vm::ForgetTopology]);
        });
    }
}
