<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmSlotRuns;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * Projects the terminal event of one `operation-task-idle-save` run once (docs/cli/task/idle-save.mmd). A passed save records the
 * saved commit on the group and admits the release of the VM; a failed save keeps the VM, and the next pass of the idle release tries
 * again, up to {@see TaskIdleSave::MaxFailures} times in a row, after which the group asks for assistance.
 */
final readonly class TaskIdleSaveProjection implements DirectOperationProjection
{
    /** @return array<string,mixed> */
    public function input(Operation $operation): array
    {
        return ['intent' => array_diff_key($operation->input, ['execution_secrets' => true]),
            'execution_secrets' => $operation->input['execution_secrets']];
    }

    /** @param array<string,mixed> $data */
    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            if ($operation->status->terminal()) {
                return app(DirectOperation::class)->project($operation, $data, ValidatedData::object($operation->result ?? []));
            }
            $saved = trim(ValidatedData::nullableString(ValidatedData::object($data['result'] ?? [])['saved'] ?? null) ?? '');
            if (($data['status'] ?? null) !== 'succeeded' || preg_match('/\A(?:[0-9a-f]{40}|[0-9a-f]{64})\z/', $saved) !== 1) {
                $data['error_code'] = 'tasks.idle_save_failed';
                $projected = app(DirectOperation::class)->project($operation, [...$data, 'status' => ($data['status'] ?? null) === 'succeeded' ? 'failed' : $data['status']],
                    ['error_code' => 'tasks.idle_save_failed']);
                $group = Task::topLevel()->find($operation->target_id);
                if ($group instanceof Task && TaskIdleSave::exhausted($group)) {
                    // The idle release stops trying; the VM keeps its place until the driver's next Operation or a person acts.
                    TaskAssistance::apply($group, AssistanceKind::Failure, reason: 'The idle save of the workspace failed '.TaskIdleSave::MaxFailures
                        .' times in a row, so the task VM keeps its place. See the failed tasks.idle_save Operations.');
                }

                return $projected;
            }
            $group = Task::topLevel()->lockForUpdate()->findOrFail($operation->target_id);
            $group->update(['idle_saved_commit' => $saved, 'idle_saved_at' => now()]);
            $released = $this->release($group, ValidatedData::integer($operation->input['task_vm_id']));
            $result = ['saved_commit' => $saved, 'branch' => TaskIdleSave::branch($group), 'released' => $released];

            return app(DirectOperation::class)->project($operation, [...$data, 'result' => $result], $result);
        });
    }

    /** Admits the release when the VM is still the group's and no Operation, such as a resume, is open on it. */
    private function release(Task $group, int $vmId): bool
    {
        $vm = TaskVm::query()->lockForUpdate()->find($vmId);
        if (! $vm instanceof TaskVm || $vm->group_id !== $group->id || ! in_array($vm->state, [TaskVmState::Ready, TaskVmState::Parked], true)
            || ! $group->groupStatus()->isActive()) {
            return false;
        }
        try {
            TaskVmSlotRuns::assertReleasable($vm);
        } catch (ResourceOperationException) {
            return false;
        }
        app(TaskVmSlotRuns::class)->startRelease($vm, 'task-vm-idle');

        return true;
    }
}
