<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Tasks\CancelTaskCheckAction;
use App\Data\Tasks\StartTaskCheckData;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationRun;
use App\Domain\Operations\OperationStepPlan;
use App\Domain\Operations\OperationWaitingKind;
use App\Domain\Operations\OperationWaitingStep;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmFailedException;
use App\Infrastructure\TaskVms\TaskVmWorkspace;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskCheck;
use LogicException;

/** Runs the existing detached check under its own lease, outside any implementer receipt scope. */
final readonly class TaskCheckOperation implements OperationWaitingKind, OperationWaitingStep
{
    public function __construct(
        private TaskExecutionLock $execution,
        private TaskWorkspaceLease $leases,
        private TaskSubtaskRunner $subtasks,
        private TaskVmWorkspace $vms,
        private TaskCheckRunner $checks,
        private TaskWorkspaceSigner $signer,
        private CancelTaskCheckAction $cancel,
        private TaskCheckOutputBuilder $output,
    ) {}

    public function requireCheck(Task $group, StartTaskCheckData $input): Task
    {
        if (! $group->taskable instanceof Instance) {
            throw new ResourceOperationException('tasks.no_workspace', 'This task group has no workspace.', 409);
        }
        $task = $group->tasks()->findOrFail($input->subtask);
        $current = $group->tasks()->whereNotIn('status', [TaskStatus::Completed, TaskStatus::Failed, TaskStatus::Cancelled])->orderBy('position')->orderBy('id')->value('id');
        if ($current !== $task->id) {
            throw new ResourceOperationException('tasks.subtask_not_current', 'This subtask is not the current open subtask.', 409);
        }
        if ($input->phase === TaskCheckKind::Baseline && $this->subtasks->groupImplementerStarted($group)) {
            throw new ResourceOperationException('tasks.baseline_done', 'An implementer has already started in this task group.', 409);
        }

        return $task;
    }

    public function run(Operation $operation, OperationRun $run): OperationOutcome
    {
        $run->plan([new OperationStepPlan('workspace_ready', true), new OperationStepPlan('check', false), new OperationStepPlan('commit', false)]);
        $run->step('workspace_ready', fn (): mixed => $this->read($run));
        $run->step('check', fn (): mixed => $this->read($run));
        $result = $run->step('commit', fn (): mixed => $this->read($run));
        if (! is_int($result)) {
            throw new LogicException('The task check step must record its check ID.');
        }
        $check = TaskCheck::query()->findOrFail($result);

        return $run->cancelRequested() ? OperationOutcome::cancelled() : OperationOutcome::succeeded($this->result($check->task()->firstOrFail(), $check, $check->kind));
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
                $group = Task::topLevel()->with('taskable', 'project')->findOrFail($operation->target_id);
                $subtask = $operation->input['subtask'] ?? null;
                $phase = $operation->input['phase'] ?? null;
                if (! is_int($subtask) || ! is_string($phase)) {
                    throw new LogicException('A check operation must store its validated subtask and phase.');
                }
                $input = new StartTaskCheckData($subtask, TaskCheckKind::from($phase));
                if ($operation->current_step === 'workspace_ready') {
                    try {
                        $this->vms->node($group);
                    } catch (TaskCapacityException) {
                        $run->wait(10);
                    }

                    return null;
                }
                // Admission belongs to start. Board updates cannot end ownership of the recorded check.
                // The runner claimed waiting -> running before entering this hook. Reconcile owns the reading.
                $this->leases->reconcile($group);
                $checkId = $operation->state['check_id'] ?? null;
                if ($checkId !== null && ! is_int($checkId)) {
                    throw new LogicException('A check operation must store an integer check ID.');
                }
                if ($operation->current_step === 'commit') {
                    if ($checkId === null) {
                        throw new LogicException('A completed check must have its recorded check ID.');
                    }
                    $check = TaskCheck::query()->findOrFail($checkId);
                    if ($input->phase === TaskCheckKind::Handoff && $check->status === TaskCheckStatus::Passed) {
                        $this->commit($group, $check->task()->firstOrFail(), $check, $operation);
                    }

                    return $check->id;
                }
                if ($checkId === null) {
                    $task = $group->tasks()->findOrFail($input->subtask);
                    $this->leases->run($group, 'check', 'operation:'.$operation->id, function () use ($run, $group, $task, $input): void {
                        $this->subtasks->recordSubtaskStart($task);
                        $run->remember('check_id', fn (): int => $task->checks()->create([
                            'kind' => $input->phase, 'status' => TaskCheckStatus::Running,
                            'pid' => 0, 'process_started' => '', 'head_before' => '', 'tree_before' => '', 'started_at' => now(),
                        ])->id);
                        $setup = $input->phase === TaskCheckKind::Baseline ? $this->subtasks->baselineSetup($group) : [];
                        $deliverables = $input->phase === TaskCheckKind::Handoff ? $this->subtasks->handoffCheck($task) : null;
                        try {
                            $this->leases->startCheck($group, $task, $group->project->taskCheckCommand(), $setup, $deliverables);
                        } catch (TaskCheckException $exception) {
                            if ($exception->startNotDispatched) {
                                throw $exception;
                            }
                            // The recorded run may exist on the VM. Reconcile recovers its start on the next read.
                            $run->wait(10);
                        }
                    }, taskId: $task->id);
                    $run->wait(10);
                }
                $check = TaskCheck::query()->find($checkId);
                if (! $check instanceof TaskCheck) {
                    throw new ResourceOperationException('tasks.check_unreachable', 'The check did not confirm its start.');
                }
                if (in_array($check->status, [TaskCheckStatus::Running, TaskCheckStatus::Lost], true)) {
                    $run->wait(10);
                }
                if ($check->status === TaskCheckStatus::Passed && $input->phase === TaskCheckKind::Handoff
                    && $this->deliverableFailures($check->task()->firstOrFail(), $check) !== []) {
                    $check->update(['status' => TaskCheckStatus::Failed]);
                }

                return $check->id;
            });
        } catch (TaskWorkspaceWaiting) {
            $run->wait(10);
        } catch (TaskVmFailedException $exception) {
            throw new ResourceOperationException($exception->vm->error_code ?? 'task_vm.job_failed', $exception->getMessage(), previous: $exception);
        } catch (TaskCheckException $exception) {
            throw new ResourceOperationException('tasks.check_unreachable', $exception->getMessage(), previous: $exception);
        }
    }

    private function commit(Task $group, Task $task, TaskCheck $check, Operation $operation): void
    {
        $this->leases->run($group, 'check_commit', 'operation:'.$operation->id, function () use ($group, $task, $check): void {
            $instance = $group->taskable;
            if (! $instance instanceof Instance) {
                throw new LogicException('A check requires its workspace.');
            }
            $before = $this->checks->snapshot($instance);
            if ($before->head !== $check->head_after || $before->tree !== $check->tree_after) {
                $check->update(['status' => TaskCheckStatus::Changed]);

                return;
            }
            $commit = $this->signer->commit($instance, $task->title);
            if ($commit === null) {
                throw new TaskCheckException('Orbit could not commit the checked tree.');
            }
            $after = $this->checks->snapshot($instance);
            $check->update(['commit_sha' => $commit]);
            if ($after->head !== $commit || $after->tree !== $check->tree_after || $after->commitTree !== $check->tree_after) {
                $check->update(['status' => TaskCheckStatus::Changed]);
            }
        }, taskId: $task->id);
    }

    /** @return list<string> */
    private function deliverableFailures(Task $task, TaskCheck $check): array
    {
        return TaskDeliverableVerifier::failures($task->deliverableList(), TaskDeliverableEvidence::fromArray($check->deliverable_evidence), $this->output);
    }

    /** @return array<string, mixed> */
    private function result(Task $task, TaskCheck $check, TaskCheckKind $phase): array
    {
        $deliverables = [];
        $evidence = TaskDeliverableEvidence::fromArray($check->deliverable_evidence);
        foreach ($task->deliverableList() as $deliverable) {
            $checked = $phase === TaskCheckKind::Handoff && $deliverable->type !== TaskDeliverableType::Review;
            $failures = $checked ? TaskDeliverableVerifier::failures([$deliverable], $evidence, $this->output) : [];
            $deliverables[] = ['id' => $deliverable->id, 'outcome' => $checked ? ($failures === [] ? 'passed' : 'failed') : 'not_checked', 'failures' => array_map($this->output->tail(...), $failures)];
        }

        return ['outcome' => $check->status === TaskCheckStatus::Cancelled ? 'failed' : $check->status->value,
            'deliverables' => $deliverables, 'check_id' => $check->id, 'commit' => $check->commit_sha,
            'head' => $check->head_after, 'tree' => $check->tree_after, 'output' => $check->output];
    }

    public function stop(OperationRun $run): void
    {
        $operation = $run->operation();
        $this->execution->synchronized($operation->target_id, function () use ($operation): void {
            $group = Task::topLevel()->with('taskable')->findOrFail($operation->target_id);
            $check = TaskCheck::query()->find($operation->state['check_id'] ?? null);
            $check = $check instanceof TaskCheck ? $this->cancel->recoverStart($group, $check) : null;
            if ($check instanceof TaskCheck && in_array($check->status, [TaskCheckStatus::Running, TaskCheckStatus::Lost], true)) {
                $this->cancel->execute($group, $check->task()->firstOrFail(), $check);
            }
            $this->leases->cancelQueued($group, 'operation:'.$operation->id);
            $this->leases->reconcile($group);
        });
    }

    public function abandon(Operation $operation, OperationOutcome $failure, ?string $failedStep): OperationOutcome
    {
        $group = Task::topLevel()->find($operation->target_id);
        if ($group instanceof Task) {
            $this->leases->cancelQueued($group, 'operation:'.$operation->id);
        }

        return $failure;
    }
}
