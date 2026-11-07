<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Nodes\NodeRemovalRecords;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationTimeout;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Terminal native cleanup projection; provider absence alone never removes Node records. */
final readonly class TaskVmDestroyProjection implements DirectOperationProjection, DirectOperationTimeout
{
    public function __construct(private TaskVmDestroyIntent $intent, private NodeRemovalRecords $nodes) {}

    public function input(Operation $operation): array
    {
        return $this->intent->input($operation);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        if ($data['status'] === OperationStatus::Interrupted->value) {
            $data['status'] = OperationStatus::Failed->value;
        }
        // No result step: a succeeded run proves the cleanup, so the receipt comes from the captured intent.
        if ($data['status'] === OperationStatus::Failed->value) {
            $data['error_code'] = 'task_vm.destroy_failed';
        }

        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            if ($operation->status->terminal()) {
                return app(DirectOperation::class)->project($operation, $data, ValidatedData::object($data['result'] ?? []));
            }
            $vm = $this->intent->owned($operation, lock: true);
            if ($data['status'] === OperationStatus::Succeeded->value) {
                $projected = app(DirectOperation::class)->project($operation, $data, $this->receipt($operation->input));
                $this->record($vm, $operation->input);
            } else {
                $projected = app(DirectOperation::class)->project($operation, $data, ($data['error_code'] ?? null) === null ? [] : ['error_code' => $data['error_code']]);
                if (in_array($data['status'], [OperationStatus::Failed->value, OperationStatus::Cancelled->value], true)) {
                    $this->failed($vm, $operation->input, ValidatedData::nullableString($data['error_code'] ?? null));
                }
            }

            return $projected;
        });
    }

    public function timedOut(Operation $operation): void
    {
        $this->failed(TaskVm::query()->lockForUpdate()->find($operation->target_id), $operation->input, 'operation.timed_out');
    }

    /** Incomplete cleanup: the VM row keeps its error and its guest Node follows the Node removal failure rule.
     * The parent calls this for its own failed or expired cleanup.
     *
     * @param  array<string,mixed>  $captured
     */
    public function failed(?TaskVm $vm, array $captured, ?string $errorCode): void
    {
        $vm?->update(['error_code' => $errorCode ?? 'task_vm.destroy_failed', 'error_message' => 'Task VM cleanup did not complete.']);
        if (($captured['node_removal'] ?? null) !== null) {
            $this->nodes->fail(ValidatedData::object($captured['node_removal']), $errorCode);
        }
    }

    /** The cleanup receipt of a succeeded run, from the captured intent: the run's end check proved the VM gone.
     * @param  array<string,mixed>  $captured
     * @return array<string,mixed>
     */
    public function receipt(array $captured): array
    {
        $removal = null;
        if (($captured['guest_node_id'] ?? null) !== null) {
            $intent = ValidatedData::object($captured['removal'] ?? []);
            $removal = ['schema_version' => 1, 'node_id' => $captured['guest_node_id'], 'name' => $intent['name'] ?? null, 'removed' => true, 'offline' => true,
                'roles_shed' => array_map(static fn (mixed $role): mixed => is_array($role) ? ($role['role'] ?? null) : null, is_array($intent['roles'] ?? null) ? array_values($intent['roles']) : []),
                'retained_on_node' => [...ValidatedData::stringList($intent['preserved_on_node'] ?? []), ...ValidatedData::stringList($intent['retained_on_node'] ?? [])],
                'follow_up' => 'Discard the Node or clear the reported retained artifacts when it is reachable.'];
        }

        return ['task_vm_id' => $captured['task_vm_id'] ?? null, 'guest_node_id' => $captured['guest_node_id'] ?? null,
            'destroyed' => true, 'cleanup_complete' => true, 'removal' => $removal];
    }

    /** The parent removes workspace records first, inside the same terminal transaction, after its run succeeded.
     * @param  array<string,mixed>  $captured
     */
    public function record(TaskVm $vm, array $captured): void
    {
        DB::transaction(function () use ($vm, $captured): void {
            $vm = TaskVm::query()->lockForUpdate()->findOrFail($vm->id);
            $this->intent->assertCaptured($vm, $captured);
            $this->intent->assertRemovedWorkspace($vm);
            if (($captured['node_removal'] ?? null) !== null) {
                $snapshot = ValidatedData::object($captured['node_removal']);
                if (($snapshot['node_id'] ?? null) !== $vm->node_id || ($snapshot['task_vm_id'] ?? null) !== $vm->id) {
                    throw new ResourceOperationException('operation.invalid_result', 'Destroy requires its captured Node and completed removal.', 422);
                }
                $this->nodes->remove($snapshot, ValidatedData::object($this->receipt($captured)['removal']));
                $vm->refresh();
            } elseif ($vm->node_id !== null) {
                throw new ResourceOperationException('operation.invalid_result', 'Destroy requires cleanup of its recorded guest Node.', 422);
            }
            $vm->update(['state' => TaskVmState::Destroyed, 'destroyed_at' => now(), 'pi_token' => null,
                'provider_bootstrap' => null, 'worker_stop_required' => false,
                'error_code' => null, 'error_message' => null, ...$vm::ForgetTopology]);
        });
    }
}
