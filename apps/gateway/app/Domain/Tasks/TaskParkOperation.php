<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\TaskVms\StopTaskVmAction;
use App\Domain\Operations\OperationKind;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationRun;
use App\Domain\Operations\OperationStepPlan;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;
use App\Models\TaskWorkspaceLeaseRecord;
use LogicException;
use Throwable;

final readonly class TaskParkOperation implements OperationKind
{
    public function __construct(private TaskOperations $operations, private TaskExecutionLock $execution, private StopTaskVmAction $stop) {}

    public static function requireEligible(Task $group): TaskVm
    {
        $vm = TaskVm::forGroup($group);
        if (! in_array($group->status, [TaskGroupStatus::Running, TaskGroupStatus::Reviewing, TaskGroupStatus::Settling, TaskGroupStatus::WaitingForReview], true)
            || ! $group->pr_url || $group->watched_pr_state !== 'open' || TaskExecutionHold::active($group) || $group->preview
            || array_filter($group->only(TaskAssistance::Fields)) !== [] || $group->tasks()->get()->contains(fn (Task $child): bool => array_filter($child->only(TaskAssistance::Fields)) !== []
                || ! in_array($child->status, [TaskStatus::Completed, TaskStatus::Failed, TaskStatus::Cancelled], true))
            || ! $vm instanceof TaskVm || ! in_array($vm->state, [TaskVmState::Ready, TaskVmState::Parked], true)
            || TaskWorkspaceLeaseRecord::query()->where('instance_id', $group->taskable_id)->whereIn('status', ['held', 'queued'])->exists()) {
            throw new ResourceOperationException('tasks.park_refused', 'Review parking requires an idle external group with an open published PR and no assistance or workspace owner.', 409);
        }
        $workspace = TaskWorkerOperation::requireWorkspace($group);
        if ($workspace->task_workspace_owner_id !== $group->id || $workspace->project_id !== $group->project_id) {
            throw new ResourceOperationException('tasks.park_refused', 'The workspace is not owned by this group and Project.', 409);
        }

        return $vm;
    }

    public function run(Operation $operation, OperationRun $run): OperationOutcome
    {
        $run->plan([new OperationStepPlan('park', true)]);
        $result = $run->step('park', fn (): array => $this->execution->synchronized($operation->target_id, function () use ($operation): array {
            $group = Task::topLevel()->findOrFail($operation->target_id);
            $this->operations->requireOpenExternal($group);
            $vm = self::requireEligible($group);
            $vm->update(['state' => TaskVmState::Parked, 'stop_confirmed_at' => null, ...TaskVm::ForgetTopology]);
            try {
                $this->stop->execute($vm);
                $group->refresh();
                $this->operations->requireOpenExternal($group);
                self::requireEligible($group);
            } catch (Throwable $exception) {
                $vm->update(['stop_confirmed_at' => null, ...TaskVm::errorFrom($exception)]);
                throw $exception;
            }
            $group->update(['status' => TaskGroupStatus::WaitingForReview, 'settled_at' => $group->settled_at ?? now()]);

            return ['group_id' => $group->id, 'task_vm_id' => $vm->id, 'instance_id' => $group->taskable_id, 'status' => 'waiting_for_review', 'power' => 'stopped'];
        }));

        if (! is_array($result)) {
            throw new LogicException('The park result must be an object.');
        }

        return OperationOutcome::succeeded([
            'group_id' => $result['group_id'], 'task_vm_id' => $result['task_vm_id'], 'instance_id' => $result['instance_id'],
            'status' => $result['status'], 'power' => $result['power'],
        ]);
    }

    public function abandon(Operation $operation, OperationOutcome $failure, ?string $failedStep): OperationOutcome
    {
        return $failure;
    }
}
