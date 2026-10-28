<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationRun;
use App\Domain\Operations\OperationStatus;
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
use App\Models\TaskWorkspaceLeaseRecord;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Claims a workspace through the existing claim provisioner, without starting task work. */
final readonly class TaskProvisionOperation implements OperationWaitingKind, OperationWaitingStep
{
    public function __construct(
        private InstanceProvisioning $provisioning,
        private TaskWorkspaceRoute $routes,
        private TaskOperations $operations,
        private TaskExecutionLock $execution,
        private TaskWorkspaceLease $leases,
        private TaskVmWorkspace $vms,
        private TaskAnalysisRunner $analysis,
        private TaskVmWorker $worker,
    ) {}

    public static function requireTodo(Task $group): void
    {
        if ($group->status === TaskGroupStatus::Backlog) {
            throw new ResourceOperationException('tasks.not_todo', 'Provision requires a todo task group.', 409);
        }
        if ($group->status !== TaskGroupStatus::Todo || $group->taskable_id !== null) {
            throw TaskGroupGuard::alreadyClaimed();
        }
    }

    public function run(Operation $operation, OperationRun $run): OperationOutcome
    {
        $run->plan([
            new OperationStepPlan('provision', false),
            new OperationStepPlan('workspace', false),
            new OperationStepPlan('workspace_lease', true),
            new OperationStepPlan('route', false),
        ]);
        $run->step('provision', fn (): mixed => $this->read($run));
        $run->step('workspace', fn (): int => $this->createWorkspace($operation));
        $run->step('workspace_lease', fn (): mixed => $this->read($run));
        $result = $run->step('route', fn (): array => $this->finishWorkspace($operation, $run));
        if (! is_array($result) || ! is_int($result['instance_id'] ?? null)) {
            throw new LogicException('The provision result must be an object.');
        }

        return $run->cancelRequested() ? $this->abandon($operation, OperationOutcome::cancelled(), null) : OperationOutcome::succeeded([
            'instance_id' => $result['instance_id'],
            'node_id' => $result['node_id'],
            'starting_commit' => $result['starting_commit'],
            'analysis_base' => TaskAnalysisBase::fromRecord($result['analysis_base'] ?? null, $result['instance_id']),
        ]);
    }

    public function waitingStep(Operation $operation, string $name): OperationWaitingStep
    {
        return $this;
    }

    /** A waiting read only admits capacity or a lease; it never creates source or projects a Route. */
    public function read(OperationRun $run): mixed
    {
        try {
            return $this->execution->synchronized($run->operation()->target_id, function () use ($run): mixed {
                $group = Task::topLevel()->with('taskable')->findOrFail($run->operation()->target_id);
                $this->operations->requireOpenExternal($group);
                if ($run->operation()->current_step === 'workspace_lease') {
                    $this->leases->reconcile($group);

                    return $this->leases->acquire($group, 'provision', 'operation:'.$run->operation()->id)->id;
                }
                if ($group->status !== TaskGroupStatus::Reserved) {
                    self::requireTodo($group);
                }
                if ($group->status === TaskGroupStatus::Todo) {
                    DB::transaction(static function () use ($group): void {
                        $locked = Task::topLevel()->lockForUpdate()->findOrFail($group->id);
                        if ($locked->status !== TaskGroupStatus::Todo) {
                            throw TaskGroupGuard::alreadyClaimed();
                        }
                        $locked->update(['status' => TaskGroupStatus::Reserved, 'reserved_at' => now(), 'capacity_wait_reason' => null]);
                    });
                    $group->refresh();
                }
                try {
                    $this->vms->node($group);
                } catch (TaskCapacityException $wait) {
                    $group->update(['capacity_wait_reason' => $wait->getMessage()]);
                    $run->wait(10);
                }

                return null;
            });
        } catch (TaskWorkspaceWaiting) {
            $run->wait(10);
        } catch (TaskVmFailedException $exception) {
            throw new ResourceOperationException($exception->vm->error_code ?? 'task_vm.job_failed', $exception->getMessage(), previous: $exception);
        }
    }

    private function createWorkspace(Operation $operation): int
    {
        return $this->execution->synchronized($operation->target_id, function () use ($operation): int {
            $group = Task::topLevel()->findOrFail($operation->target_id);
            $this->operations->requireOpenExternal($group);
            try {
                $instance = $this->provisioning->provision(InstanceProvisionIntent::for($group));
            } catch (TaskVmFailedException $exception) {
                throw new ResourceOperationException($exception->vm->error_code ?? 'task_vm.job_failed', $exception->getMessage(), previous: $exception);
            }
            if (! $instance instanceof Instance) {
                throw new ResourceOperationException($instance->errorCode ?? 'tasks.provision_failed', $instance->cause ?? 'Workspace provisioning did not return an instance.');
            }
            $group->taskable()->associate($instance);
            $group->save();

            return $instance->id;
        });
    }

    /** @return array{instance_id: int, node_id: int, starting_commit: string|null, analysis_base: array{base: string, instance_id: int, repository: string, branch: string}|null} */
    private function finishWorkspace(Operation $operation, OperationRun $run): array
    {
        return $this->execution->synchronized($operation->target_id, function () use ($operation, $run): array {
            $group = Task::topLevel()->with('taskable')->findOrFail($operation->target_id);
            $this->operations->requireOpenExternal($group);
            $identity = $this->leases->run($group, 'provision', 'operation:'.$operation->id, function () use ($group, $run, $operation): array {
                $failure = $this->routes->complete($group);
                if ($failure !== null) {
                    throw new ResourceOperationException($failure->errorCode ?? 'tasks.provision_failed', $failure->cause);
                }
                $instance = $group->taskable;
                if (! $instance instanceof Instance) {
                    throw new LogicException('Provision has no workspace.');
                }
                $this->worker->start($operation, $instance, required: false);
                $base = $run->remember('analysis_base', fn (): array => [
                    'base' => $this->analysis->initialAnalysisBase($instance),
                    'instance_id' => $instance->id,
                    'repository' => $group->project->repository_url,
                    'branch' => $instance->branch,
                ]);

                // The existing route-step checkpoint retains this identity on recovery.
                return [
                    'instance_id' => $instance->id, 'node_id' => $instance->node_id, 'starting_commit' => $instance->starting_commit,
                    'analysis_base' => TaskAnalysisBase::fromRecord($base, $instance->id),
                ];
            });
            $group->update(['status' => TaskGroupStatus::Running, 'started_at' => $group->started_at ?? now(), 'reserved_at' => null, 'capacity_wait_reason' => null]);

            return $identity;
        });
    }

    public function stop(OperationRun $run): void
    {
        $this->worker->forget($run->operation());
        $this->releaseReservation($run->operation());
    }

    private function releaseReservation(Operation $operation): void
    {
        $group = Task::topLevel()->find($operation->target_id);
        if ($group instanceof Task) {
            $this->leases->cancelQueued($group, 'operation:'.$operation->id);
            $lease = TaskWorkspaceLeaseRecord::query()->where('group_id', $group->id)->where('owner', 'operation:'.$operation->id)->where('status', 'held')->first();
            if ($lease instanceof TaskWorkspaceLeaseRecord) {
                $this->leases->release($lease);
            }
            $group->update(['reserved_at' => null, 'capacity_wait_reason' => null]);
        }
    }

    public function abandon(Operation $operation, OperationOutcome $failure, ?string $failedStep): OperationOutcome
    {
        $this->worker->forget($operation);
        $this->releaseReservation($operation);
        $group = Task::topLevel()->find($operation->target_id);
        if ($group instanceof Task) {
            $this->worker->stopGroup($group);
        }
        if ($failure->status === OperationStatus::Failed && $group instanceof Task && ! in_array($group->status, TaskGroupStatus::endedStatuses(), true)) {
            $group->update(['status' => TaskGroupStatus::Failed, 'settled_at' => now()]);
        }

        return $failure;
    }
}
