<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Instances\InstanceState;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\TaskVms\TaskVmState;
use App\Jobs\TaskVms\ReleaseTaskVmSlot;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Closure;
use Illuminate\Support\Facades\DB;

/** State projection only: the claim of a slot (docs/cli/tasks/provision.mmd). Swamp owns the start, the fetch, the containers and readiness. */
final readonly class TaskProvisionProjection implements DirectOperationProjection
{
    /** The end check of a task start: what a task needs, the agent, Pi, the tunnel and the three containers running. */
    public const string EndCheck = 'Check ready';

    /** @return array<string,mixed> */
    public function input(Operation $operation): array
    {
        $input = ValidatedData::object($operation->input);

        return ['intent' => ValidatedData::object($input['intent']), 'execution_secrets' => ValidatedData::object($input['execution_secrets'])];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $status = ValidatedData::string($data['status']);
            $result = ['task_vm_id' => $operation->input['task_vm_id'] ?? null, 'ready' => $status === 'succeeded'];
            if (in_array($status, ['failed', 'interrupted'], true)) {
                $data['error_code'] = ValidatedData::nullableString($data['error_code'] ?? null) ?? 'tasks.provision_failed';
            }
            if ($operation->status->terminal()) {
                return app(DirectOperation::class)->project($operation, $data, $result);
            }
            $projected = $this->record($operation, $status, ValidatedData::nullableString($data['error_code'] ?? null),
                static fn (): Operation => app(DirectOperation::class)->project($operation, $data, $result));

            app(TaskMaintenanceContinuation::class)->apply($projected);

            return $projected;
        });
    }

    /**
     * Records the slot, the workspace Instance and the group from the end of a claim. `$record` writes the Operation between the lookups
     * and the writes. A kind that claims as one step of a larger run passes its own closure; `$operation` then holds the provision intent
     * as its input. A claim that did not pass releases its slot, so the slot is free again for the next claim; a run that releases the
     * slot itself passes `$release` false.
     *
     * @param  Closure(): Operation  $record
     */
    public function record(Operation $operation, string $status, ?string $errorCode, Closure $record, bool $release = true): Operation
    {
        $slot = TaskVm::query()->lockForUpdate()->findOrFail(ValidatedData::integer($operation->input['task_vm_id']));
        $group = Task::topLevel()->lockForUpdate()->findOrFail($operation->target_id);
        $instance = Instance::query()->where('task_workspace_owner_id', $group->id)->findOrFail(ValidatedData::integer($operation->input['instance_id']));
        $workspace = ValidatedData::object($operation->input['workspace']);
        $projected = $record();
        if ($status === 'succeeded') {
            $slot->update(['state' => TaskVmState::Ready, 'ready_at' => now(), 'stop_confirmed_at' => null, 'error_code' => null, 'error_message' => null]);
            $instance->update(['branch' => $workspace['branch'], 'starting_commit' => $workspace['start_commit'],
                'status' => InstanceState::SourceResolved, 'first_setup_pending' => false, 'failed_step' => null, 'error_code' => null]);
            $group->update(['status' => TaskGroupStatus::Running, 'started_at' => $group->started_at ?? now(), 'reserved_at' => null, 'capacity_wait_reason' => null]);

            return $projected;
        }
        if ($status === 'cancelled') {
            $group->update(['status' => TaskGroupStatus::Cancelled, 'settled_at' => now(), 'reserved_at' => null, 'capacity_wait_reason' => null]);
        } else {
            $instance->update(['failed_step' => 'provision', 'error_code' => $errorCode ?? 'tasks.provision_failed']);
            $group->update(['status' => TaskGroupStatus::Failed, 'settled_at' => now(), 'reserved_at' => null, 'capacity_wait_reason' => null]);
        }
        if ($release) {
            ReleaseTaskVmSlot::dispatch($slot->id, $group->id)->afterCommit();
        }

        return $projected;
    }
}
