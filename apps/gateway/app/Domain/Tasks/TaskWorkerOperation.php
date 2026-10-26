<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Instances\InstanceState;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationRun;
use App\Domain\Operations\OperationStepPlan;
use App\Domain\Operations\OperationWaitingKind;
use App\Domain\Operations\OperationWaitingStep;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmFailedException;
use App\Infrastructure\TaskVms\TaskVmWorker;
use App\Infrastructure\TaskVms\TaskVmWorkspace;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;
use App\Models\TaskWorkspaceLeaseRecord;

/** Fresh enrollment on an existing group's VM, including after parking. */
final readonly class TaskWorkerOperation implements OperationWaitingKind, OperationWaitingStep
{
    public function __construct(private TaskOperations $operations, private TaskExecutionLock $execution, private TaskWorkspaceLease $leases, private TaskVmWorkspace $vms, private TaskVmWorker $worker) {}

    public static function requireWorkspace(Task $group): Instance
    {
        $instance = $group->taskable;
        $vm = TaskVm::forGroup($group);
        if (! $instance instanceof Instance || ! $vm instanceof TaskVm || $vm->node_id !== $instance->node_id
            || ! in_array($instance->status, [InstanceState::SourceResolved, InstanceState::Active], true)) {
            throw new ResourceOperationException('tasks.no_workspace', 'The group has no task VM workspace.', 409);
        }

        return $instance;
    }

    public function run(Operation $operation, OperationRun $run): OperationOutcome
    {
        $run->plan([new OperationStepPlan('workspace_ready', true), new OperationStepPlan('worker', true)]);
        $run->step('workspace_ready', fn (): mixed => $this->read($run));
        $result = $run->step('worker', fn (): mixed => $this->read($run));
        if (! is_array($result) || ! is_int($result['instance_id'] ?? null) || ! is_int($result['node_id'] ?? null) || ($result['started'] ?? null) !== true) {
            throw new \LogicException('Worker startup must record its workspace, Node, and process start.');
        }

        return OperationOutcome::succeeded(['instance_id' => $result['instance_id'], 'node_id' => $result['node_id'], 'started' => true]);
    }

    public function waitingStep(Operation $operation, string $name): OperationWaitingStep
    {
        return $this;
    }

    public function read(OperationRun $run): mixed
    {
        try {
            return $this->execution->synchronized($run->operation()->target_id, function () use ($run): mixed {
                $operation = $run->operation();
                $group = Task::topLevel()->with('taskable')->findOrFail($operation->target_id);
                $this->operations->requireOpenExternal($group);
                $instance = self::requireWorkspace($group);
                if ($operation->current_step === 'workspace_ready') {
                    $this->worker->requireEnrollment($operation);
                    $this->vms->node($group);

                    return null;
                }

                return $this->leases->run($group, 'worker', 'operation:'.$operation->id, function () use ($operation, $instance): array {
                    $this->worker->start($operation, $instance);

                    return ['instance_id' => $instance->id, 'node_id' => $instance->node_id, 'started' => true];
                });
            });
        } catch (TaskCapacityException|TaskWorkspaceWaiting) {
            $run->wait(10);
        } catch (TaskVmFailedException $exception) {
            throw new ResourceOperationException($exception->vm->error_code ?? 'task_vm.job_failed', $exception->getMessage(), previous: $exception);
        }
    }

    public function stop(OperationRun $run): void
    {
        $this->release($run->operation());
    }

    private function release(Operation $operation): void
    {
        $this->worker->forget($operation);
        $this->execution->synchronized($operation->target_id, function () use ($operation): void {
            $group = Task::topLevel()->findOrFail($operation->target_id);
            $this->leases->cancelQueued($group, 'operation:'.$operation->id);
            $lease = TaskWorkspaceLeaseRecord::query()->where('group_id', $group->id)->where('owner', 'operation:'.$operation->id)->where('status', 'held')->first();
            if ($lease instanceof TaskWorkspaceLeaseRecord) {
                $this->leases->release($lease);
            }
        });
    }

    public function abandon(Operation $operation, OperationOutcome $failure, ?string $failedStep): OperationOutcome
    {
        $this->release($operation);

        return $failure;
    }
}
