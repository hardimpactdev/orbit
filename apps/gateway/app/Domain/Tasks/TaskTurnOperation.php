<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Tasks\StopTaskSubtaskAction;
use App\Data\Tasks\TaskTurnData;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationRun;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStepPlan;
use App\Domain\Operations\OperationWaitingKind;
use App\Domain\Operations\OperationWaitingStep;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmFailedException;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\Tasks\Pi\PiModel;
use App\Infrastructure\TaskVms\TaskVmWorkspace;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskWorkspaceLeaseRecord;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\Config;
use LogicException;

/** Sends one orchestrator prompt and records its evidence without advancing task work. */
final readonly class TaskTurnOperation implements OperationWaitingKind, OperationWaitingStep
{
    public function __construct(
        private TaskOperations $operations,
        private TaskExecutionLock $execution,
        private TaskWorkspaceLease $leases,
        private TaskVmWorkspace $vms,
        private AgentDriverRegistry $drivers,
        private TaskCheckRunner $checks,
        private TaskTurnReceipts $receipts,
        private TaskWorkspaceMcp $mcp,
        private TaskReviewDiff $diffs,
        private TaskReviewReset $reset,
        private StopTaskSubtaskAction $interrupt,
    ) {}

    public function validate(Task $group, TaskTurnData $input): void
    {
        $task = $this->operations->requireCurrentSubtask($group, $input->subtask);
        $thread = null;
        if ($input->thread !== 'new') {
            $thread = AgentThread::query()->where('task_group_id', $group->id)->find((int) $input->thread);
            if (! $thread instanceof AgentThread || $thread->role !== $input->threadRole()->value || $thread->isPending()) {
                throw new ResourceOperationException('tasks.thread_role_mismatch', 'The thread must belong to this group and have the requested role.', 422);
            }
            if ($input->model !== null) {
                throw new ResourceOperationException('tasks.model_fixed', 'A continued thread keeps its model.', 422);
            }
        }
        try {
            PiModel::onlyProvider($thread instanceof AgentThread ? ($thread->model ?? '') : $this->model($group, $input), 'orbit-task-vm');
        } catch (AgentDriverException $exception) {
            throw new ResourceOperationException('tasks.model_refused', $exception->getMessage(), 422);
        }
        if ($input->role === 'reviewer') {
            $check = $this->reviewCheck($task, $input);
            // A parked VM must resume before any workspace read. Preparation checks HEAD again under its lease.
            $vm = $this->vms->vm($group);
            if ($vm?->state === TaskVmState::Ready && $this->checks->snapshot($this->workspace($group))->head !== $check->commit_sha) {
                throw new ResourceOperationException('tasks.head_moved', 'The workspace HEAD no longer matches the passed handoff check.', 409);
            }
        }
    }

    private function reviewCheck(Task $task, TaskTurnData $input): TaskCheck
    {
        $check = $task->checks()->find($input->check);
        if (! $check instanceof TaskCheck || $check->kind !== TaskCheckKind::Handoff || $check->status !== TaskCheckStatus::Passed || $check->commit_sha === null) {
            throw new ResourceOperationException('tasks.commit_not_checked', 'Review requires a passed handoff check with a recorded commit for this subtask.', 422);
        }

        return $check;
    }

    private function model(Task $group, TaskTurnData $input): string
    {
        return $input->model ?? ($input->threadRole() === TaskThreadRole::Implementer ? $group->implementer_model : $group->reviewer_model);
    }

    private function workspace(Task $group): Instance
    {
        return $group->taskable instanceof Instance ? $group->taskable : throw new ResourceOperationException('tasks.no_workspace', 'This task group has no workspace.', 409);
    }

    public function run(Operation $operation, OperationRun $run): OperationOutcome
    {
        $run->plan([
            new OperationStepPlan('turn_workspace', true),
            new OperationStepPlan('thread', true),
            new OperationStepPlan('prepare_turn', false),
            new OperationStepPlan('send_turn', true),
            new OperationStepPlan('turn', true),
            new OperationStepPlan('turn_result', true),
        ]);
        $run->step('turn_workspace', fn (): mixed => $this->read($run));
        $run->step('thread', fn (): int => $this->reserve($operation));
        $run->step('prepare_turn', fn (): mixed => $this->read($run));
        $run->step('send_turn', fn () => $this->send($operation));
        $run->step('turn', fn (): mixed => $run->cancelRequested() ? $run->wait(10) : $this->read($run), cancellable: false);
        $result = $run->step('turn_result', fn (): array => $this->result($run), cancellable: false);
        if (! is_array($result)) {
            throw new LogicException('A turn result must be an object.');
        }

        $result = ValidatedData::object($result);

        if ($run->cancelRequested()) {
            return OperationOutcome::cancelled($result);
        }

        return $result['state'] === AgentThreadState::Failed->value
            ? OperationOutcome::failed(is_string($result['error_code']) ? $result['error_code'] : 'tasks.turn_failed', $result)
            : OperationOutcome::succeeded($result);
    }

    private function reserve(Operation $operation): int
    {
        $group = Task::topLevel()->findOrFail($operation->target_id);
        $input = TaskTurnData::from($operation->input);
        $this->operations->requireCurrentSubtask($group, $input->subtask);
        $instance = $this->workspace($group);
        $thread = $input->thread === 'new'
            ? AgentThread::query()->firstOrCreate(['external_id' => 'pending:operation-'.$operation->id, 'runtime_key' => 'node:'.$instance->node_id, 'driver' => 'pi'], [
                'task_group_id' => $group->id, 'task_id' => $input->subtask, 'node_id' => $instance->node_id,
                'role' => $input->threadRole()->value, 'model' => $this->model($group, $input),
                'effort' => Config::string('orbit.tasks.'.$input->threadRole()->value.'_effort'),
            ])
            : AgentThread::query()->findOrFail((int) $input->thread);
        $thread->update(['task_id' => $input->subtask]);
        $owner = $input->threadRole() === TaskThreadRole::Implementer ? Task::query()->findOrFail($input->subtask) : $group;
        $owner->update([$input->threadRole()->value.'_agent_thread_id' => $thread->id]);

        return $thread->id;
    }

    private function thread(Operation $operation): AgentThread
    {
        $id = data_get($operation->state, 'steps.thread');

        if (! is_int($id)) {
            throw new LogicException('The turn has no reserved thread.');
        }

        return AgentThread::query()->findOrFail($id);
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
                $group = Task::topLevel()->findOrFail($operation->target_id);
                $this->operations->requireOpenExternal($group);
                if ($operation->current_step === 'turn_workspace') {
                    $this->vms->node($group);

                    return null;
                }
                $input = TaskTurnData::from($operation->input);
                $task = $this->operations->requireCurrentSubtask($group, $input->subtask);
                $thread = $this->thread($operation);
                $this->leases->reconcile($group);
                if ($operation->current_step === 'prepare_turn') {
                    $lease = $this->leases->turn($group, $input->threadRole(), 'thread:'.$thread->id, $thread->id);

                    return $this->leases->within($lease, function () use ($group, $task, $input, $thread): array {
                        $instance = $this->workspace($group);
                        $before = $this->checks->snapshot($instance);
                        $commit = $input->role === 'reviewer' ? $this->reviewCheck($task, $input)->commit_sha : null;
                        if ($commit !== null && $before->head !== $commit) {
                            throw new ResourceOperationException('tasks.head_moved', 'The workspace HEAD no longer matches the passed handoff check.', 409);
                        }
                        if (! $this->mcp->installWhenMissing($instance)) {
                            throw new ResourceOperationException('tasks.mcp_install_failed', 'The workspace MCP configuration could not be installed.');
                        }
                        $this->receipts->prepare($instance, $input->threadRole(), deliverables: $task->deliverableList(), threadId: $thread->id, mode: new TaskTurnMode(consult: $input->role === 'consult'));

                        return ['head' => $before->head, 'tree' => $before->tree, 'commit' => $commit];
                    });
                }
                $lease = TaskWorkspaceLeaseRecord::query()->where('group_id', $group->id)->where('thread_id', $thread->id)->where('dispatch_key', $this->key($operation))->latest('id')->first();
                if (! $lease instanceof TaskWorkspaceLeaseRecord || $lease->status !== 'released' || ! $lease->getAttribute('dispatch_completed')) {
                    $run->wait(10);
                }

                $observation = $this->drivers->get('pi')->observe($thread);

                return ['error_code' => $observation->providerError];
            });
        } catch (TaskWorkspaceWaiting|TaskCapacityException) {
            $run->wait(10);
        } catch (TaskVmFailedException $exception) {
            throw new ResourceOperationException($exception->vm->error_code ?? 'task_vm.job_failed', $exception->getMessage(), previous: $exception);
        }
    }

    private function key(Operation $operation): string
    {
        return $operation->idempotency_key ?? 'operation-'.$operation->id;
    }

    private function send(Operation $operation): void
    {
        $this->execution->synchronized($operation->target_id, function () use ($operation): void {
            $group = Task::topLevel()->findOrFail($operation->target_id);
            $this->operations->requireOpenExternal($group);
            $input = TaskTurnData::from($operation->input);
            $this->operations->requireCurrentSubtask($group, $input->subtask);
            $thread = $this->thread($operation);
            $driver = $this->drivers->get('pi');
            $prompt = 'Do not fetch or push. '.($input->threadRole() === TaskThreadRole::Reviewer ? 'Review read-only. Do not change the checkout. ' : '')
                .'End this turn with "$(git rev-parse --git-path orbit)/turn" --thread='.$thread->id.'. Read that command for its outcomes and arguments.'
                ."\n\n".$input->prompt;
            try {
                if ($thread->isPending()) {
                    $instance = $this->workspace($group);
                    $id = $driver->create(new AgentThreadStart($instance->node, $instance, 'Orbit task #'.$group->id.' / subtask #'.$input->subtask,
                        '', $thread->model ?? '', $thread->effort ?? 'high', $input->threadRole(), externalId: 'operation-'.$operation->id, deferOpeningTurn: true, threadId: $thread->id));
                    $thread->update(['external_id' => $id]);
                }
                $driver->send($thread, $prompt, $this->key($operation));
            } catch (AgentDriverException $exception) {
                throw new ResourceOperationException($exception->errorCode ?? 'tasks.turn_failed', $exception->getMessage(), previous: $exception);
            }
        });
    }

    /** @return array<string, mixed> */
    private function result(OperationRun $run): array
    {
        $operation = $run->operation();
        $group = Task::topLevel()->findOrFail($operation->target_id);
        $thread = $this->thread($operation);

        return $this->leases->run($group, 'turn_result', 'operation:'.$operation->id, function () use ($run, $operation, $group, $thread): array {
            $instance = $this->workspace($group);
            $before = data_get($operation->state, 'steps.prepare_turn');
            $after = $run->remember('after', fn (): array => $this->snapshot($instance));
            $file = null;
            $receipt = $run->remember('receipt', function () use ($instance, $thread, &$file): ?array {
                $receipt = $file = $this->receipts->read($instance, $thread->id);

                return $receipt === null ? null : ['hash' => $receipt->hash, 'outcome' => $receipt->outcome?->value, 'summary' => $receipt->summary,
                    'deliverables' => $receipt->deliverables, 'question' => $receipt->question, 'cause' => $receipt->cause];
            });
            if ($file === null && is_array($receipt)) {
                $file = $this->receipts->read($instance, $thread->id);
            }
            if ($file !== null && is_array($receipt) && $file->hash === $receipt['hash']) {
                $this->receipts->clear($instance, $file);
            }
            $input = TaskTurnData::from($operation->input);
            $difference = $input->role === 'implementer' ? null : $run->remember('difference', fn (): array => $this->diffs->read($instance, is_array($before) && is_string($before[$input->role === 'reviewer' ? 'commit' : 'tree'] ?? null) ? $before[$input->role === 'reviewer' ? 'commit' : 'tree'] : throw new LogicException('Missing turn baseline.')));
            if ($input->role === 'reviewer' && is_array($before) && is_string($before['commit'] ?? null)) {
                $this->reset->reset($instance, $before['commit']);
            }
            $thread->refresh();

            return ['receipt' => $receipt, 'thread' => $thread->id, 'state' => $thread->state?->value, 'error' => $thread->error,
                'error_code' => $thread->state === AgentThreadState::Failed ? (data_get($operation->state, 'steps.turn.error_code') ?? 'tasks.turn_failed') : null,
                'before' => $before, 'after' => $after, 'difference' => $difference, 'after_reset' => $input->role === 'reviewer' ? $this->snapshot($instance) : null,
                'metrics' => $thread->only(['tokens', 'input_tokens', 'cached_input_tokens', 'output_tokens', 'model_calls', 'peak_context_tokens'])];
        });
    }

    /** @return array{head: string, tree: string} */
    private function snapshot(Instance $instance): array
    {
        $snapshot = $this->checks->snapshot($instance);

        return ['head' => $snapshot->head, 'tree' => $snapshot->tree];
    }

    public function stop(OperationRun $run): void
    {
        $operation = $run->operation();
        $this->execution->synchronized($operation->target_id, function () use ($operation, $run): void {
            $group = Task::topLevel()->findOrFail($operation->target_id);
            $input = TaskTurnData::from($operation->input);
            $id = data_get($operation->state, 'steps.thread');
            if (! is_int($id)) {
                return;
            }
            $thread = $this->thread($operation);
            $this->leases->cancelQueued($group, 'thread:'.$thread->id);
            $ownTurn = TaskWorkspaceLeaseRecord::query()->where('group_id', $group->id)->where('thread_id', $thread->id)
                ->where('dispatch_key', $this->key($operation))->exists();
            if (! $ownTurn) {
                $this->leases->abandonPreparation($thread);

                return;
            }
            $this->interrupt->executeExternal($group, Task::query()->findOrFail($input->subtask));
            $run->remember('cancelled_result', fn (): array => $this->result($run));
        });
    }

    public function abandon(Operation $operation, OperationOutcome $failure, ?string $failedStep): OperationOutcome
    {
        $id = data_get($operation->state, 'steps.thread');
        $thread = is_int($id) ? AgentThread::query()->find($id) : null;
        if ($thread instanceof AgentThread) {
            $group = Task::topLevel()->find($operation->target_id);
            if ($group instanceof Task) {
                $this->leases->cancelQueued($group, 'thread:'.$thread->id);
            }
            if ($this->leases->abandonPreparation($thread) && $thread->isPending()) {
                $thread->delete();
            }
        }

        $cancelled = $operation->state['cancelled_result'] ?? null;

        return $failure->status === OperationStatus::Cancelled && is_array($cancelled)
            ? OperationOutcome::cancelled(ValidatedData::object($cancelled)) : $failure;
    }
}
