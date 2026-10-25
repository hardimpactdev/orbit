<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Data\Tasks\StartTaskScriptData;
use App\Data\Tasks\TaskScriptExecutionData;
use App\Data\Tasks\TaskScriptTerminalData;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationRun;
use App\Domain\Operations\OperationStepPlan;
use App\Domain\Operations\OperationStopPending;
use App\Domain\Operations\OperationWaitingKind;
use App\Domain\Operations\OperationWaitingStep;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\TaskVms\TaskVmWorkspace;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskWorkspaceLeaseRecord;
use Illuminate\Support\Str;
use Throwable;

/** One keyed tracked repository script under the existing detached ownership. */
final readonly class TaskScriptOperation implements OperationWaitingKind, OperationWaitingStep
{
    public function __construct(private TaskVmWorkspace $vms, private TaskWorkspaceLease $leases, private TaskExecutionLock $execution, private TaskScriptRunner $runner, private TaskCheckOutputBuilder $output) {}

    public function run(Operation $operation, OperationRun $run): OperationOutcome
    {
        $run->plan([new OperationStepPlan('workspace_ready', true), new OperationStepPlan('script', true)]);
        $run->step('workspace_ready', fn (): mixed => $this->read($run));
        $result = $this->checkpoint($run->step('script', fn (): mixed => $this->read($run)));

        return OperationOutcome::succeeded($result);
    }

    /** @return array<string, mixed> */
    private function checkpoint(mixed $value): array
    {
        if (! is_array($value)) {
            throw new \LogicException('The script step must retain a complete object checkpoint.');
        }
        $record = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new \LogicException('Script checkpoints must use named fields.');
            }
            $record[$key] = $item;
        }

        return $record;
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
                $input = StartTaskScriptData::from($operation->input);
                $group = Task::topLevel()->with('taskable', 'project')->findOrFail($operation->target_id);
                if ($operation->current_step === 'workspace_ready') {
                    try {
                        $this->vms->node($group);
                    } catch (TaskCapacityException) {
                        $run->wait(10);
                    }

                    return null;
                }
                $instance = $group->taskable;
                if (! $instance instanceof Instance) {
                    throw new ResourceOperationException('tasks.no_workspace', 'The owned script workspace is unavailable.', 409);
                }
                $terminal = $operation->state['script_terminal'] ?? null;
                if (is_array($terminal)) {
                    $checkpoint = TaskScriptTerminalData::from($terminal);
                    $lease = TaskWorkspaceLeaseRecord::query()->findOrFail($checkpoint->lease_id);
                    $this->requireOwnership($operation, $group, $instance, $lease);

                    return $this->finishTerminal($lease, $terminal);
                }
                $reserved = $operation->state['execution'] ?? null;
                if (! is_array($reserved)) {
                    // A crash between the lease reservation and the state checkpoint cannot dispatch a second run.
                    $orphan = TaskWorkspaceLeaseRecord::query()->where('group_id', $group->id)->where('owner', 'operation:'.$operation->id)->where('kind', 'script')->where('status', 'held')->whereNotNull('check_run_id')->first();
                    if ($orphan instanceof TaskWorkspaceLeaseRecord) {
                        return $this->leases->within($orphan, function () use ($group, $orphan, $run): array {
                            $after = $this->stopAndSnapshot($group, $orphan);
                            $terminal = $run->remember('script_terminal', fn (): array => [
                                'lease_id' => $orphan->id, 'after' => ['head' => $after->head, 'tree' => $after->tree], 'reading' => null,
                                'error' => ['code' => 'tasks.script_not_started', 'message' => 'The reserved script never dispatched; its identity is tombstoned.', 'status' => 422],
                            ]);

                            return $this->finishTerminal($orphan, $terminal);
                        });
                    }
                    $this->leases->run($group, 'script', 'operation:'.$operation->id, function () use ($run, $group, $instance, $input): void {
                        $inspection = $this->runner->inspectScript($instance, $input->script);
                        $this->requireFresh($inspection);
                        $lease = $this->leases->currentScope() ?? throw new TaskWorkspaceWaiting('Script requires its admitted workspace scope.');
                        $execution = $run->remember('execution', function () use ($inspection, $lease, $instance, $group, $input): array {
                            $id = str_replace('-', '', (string) Str::uuid());
                            $lease->update(['pid' => 0, 'process_started' => '', 'check_run_id' => $id, 'head_before' => $inspection['head'], 'tree_before' => $inspection['tree']]);

                            return ['execution_id' => $id, 'lease_id' => $lease->id, 'instance_id' => $instance->id, 'node_id' => $instance->node_id,
                                'repository' => $group->project->repository_url, 'branch' => $inspection['branch'], 'entrypoint_sha256' => $inspection['entrypoint_sha256'],
                                'script' => $input->script, 'args' => $input->args, 'head' => $inspection['head'], 'tree' => $inspection['tree']];
                        });
                        $execution = TaskScriptExecutionData::from($execution);
                        $process = $this->runner->startScript($instance, $execution->toArray(), $execution->execution_id);
                        $lease->update(['pid' => $process->pid, 'process_started' => $process->started]);
                    }, taskId: $input->subtask);
                    $run->wait(10);
                }
                $reserved = TaskScriptExecutionData::from($reserved);
                $lease = TaskWorkspaceLeaseRecord::query()->findOrFail($reserved->lease_id);
                $this->requireOwnership($operation, $group, $instance, $lease);
                $this->leases->reconcile($group);

                return $this->leases->within($lease, function () use ($operation, $run, $instance, $lease, $reserved): array {
                    $process = $this->runner->recoverStart($instance, $reserved->execution_id);
                    if ($process === null) {
                        $after = app(TaskCheckRunner::class)->snapshot($instance);
                        $terminal = $run->remember('script_terminal', fn (): array => [
                            'lease_id' => $lease->id, 'after' => ['head' => $after->head, 'tree' => $after->tree], 'reading' => null,
                            'error' => ['code' => 'tasks.script_not_started', 'message' => 'The script start never dispatched; its identity is tombstoned.', 'status' => 422],
                        ]);

                        return $this->finishTerminal($lease, $terminal);
                    }
                    $this->requireProcessIdentity($lease, $process);
                    $lease->update(['pid' => $process->pid, 'process_started' => $process->started]);
                    $reading = $this->runner->readScript($instance, $process);
                    if (! in_array($reading['state'] ?? null, ['finished', 'interrupted'], true)) {
                        $run->wait(10);
                    }
                    if ($reading['state'] === 'interrupted') {
                        $snapshot = app(TaskCheckRunner::class)->snapshot($instance);
                        $reading['result'] = ['head_after' => $snapshot->head, 'tree_after' => $snapshot->tree];
                    }
                    // Validate and redact before retaining any output in operation state.
                    $result = $this->result($operation, $reading);
                    $terminal = $run->remember('script_terminal', fn (): array => ['lease_id' => $lease->id,
                        'after' => ['head' => $result['head'], 'tree' => $result['tree']], 'reading' => $result]);

                    return $this->finishTerminal($lease, $terminal);
                });
            });
        } catch (TaskCheckException|TaskWorkspaceWaiting) {
            $run->wait(10);
        }
    }

    /** @return array<string, mixed> */
    private function finishTerminal(TaskWorkspaceLeaseRecord $lease, mixed $checkpoint): array
    {
        $terminal = TaskScriptTerminalData::from($checkpoint);
        $this->release($lease, new TaskWorkspaceSnapshot($terminal->after['head'], $terminal->after['tree']));
        if ($terminal->error !== null) {
            throw new ResourceOperationException($terminal->error['code'], $terminal->error['message'], $terminal->error['status']);
        }

        return $terminal->reading ?? throw new \LogicException('The successful terminal checkpoint has no complete reading.');
    }

    private function requireOwnership(Operation $operation, Task $group, Instance $instance, TaskWorkspaceLeaseRecord $lease): void
    {
        $checkpoint = $operation->state['execution'] ?? null;
        $execution = is_array($checkpoint) ? TaskScriptExecutionData::from($checkpoint) : null;
        if ($lease->kind !== 'script' || $lease->group_id !== $group->id || $lease->instance_id !== $instance->id || $lease->owner !== 'operation:'.$operation->id
            || ($execution !== null && ($execution->lease_id !== $lease->id || $execution->instance_id !== $instance->id
                || $execution->execution_id !== $lease->check_run_id || $execution->head !== $lease->head_before || $execution->tree !== $lease->tree_before
                || ($lease->status === 'held' && $execution->node_id !== $instance->node_id)))) {
            throw new TaskWorkspaceWaiting('The reserved script belongs to another workspace, Node, or owner.');
        }
    }

    private function requireProcessIdentity(TaskWorkspaceLeaseRecord $lease, TaskCheckProcess $process): void
    {
        if ($process->runId !== $lease->check_run_id || $process->head !== $lease->head_before || $process->tree !== $lease->tree_before) {
            throw new TaskWorkspaceWaiting('The recovered process does not identify the reserved script.');
        }
    }

    /** @param array<string, mixed> $inspection */
    private function requireFresh(array $inspection): void
    {
        foreach (['head', 'tree', 'branch', 'entrypoint_sha256'] as $field) {
            if (! is_string($inspection[$field] ?? null)) {
                throw new ResourceOperationException('tasks.script_unavailable', 'The tracked executable repository script is unavailable.', 422);
            }
        }
    }

    /** @param array<string, mixed> $reading
     * @return array<string, mixed>
     */
    private function result(Operation $operation, array $reading): array
    {
        $result = $reading['result'] ?? [];
        if (! is_array($result) || ! is_string($reading['output'] ?? null)) {
            throw new TaskWorkspaceWaiting('The script has no complete terminal reading.');
        }
        foreach (['head_after', 'tree_after'] as $identity) {
            if (! is_string($result[$identity] ?? null) || preg_match('/\A(?:[a-f0-9]{40}|[a-f0-9]{64})\z/D', $result[$identity]) !== 1) {
                throw new TaskWorkspaceWaiting('The script completion identity is unavailable.');
            }
        }
        $interrupted = $reading['state'] === 'interrupted' || ($result['causality_uncertain'] ?? false) === true;
        if (! $interrupted && (($result['script'] ?? false) !== true || ! is_int($result['exit_code'] ?? null)
            || ! is_int($result['output_bytes'] ?? null) || $result['output_bytes'] < 0)) {
            throw new TaskWorkspaceWaiting('The script completion is incomplete.');
        }
        $execution = TaskScriptExecutionData::from($operation->state['execution'] ?? []);
        $output = $this->output->tail($reading['output']);
        $exit = $interrupted ? null : $result['exit_code'];

        return ['outcome' => $exit === 0 ? 'passed' : 'failed', 'exit_code' => $exit, 'output' => $output,
            'output_truncated' => $interrupted || $result['output_bytes'] > 16384,
            'changed' => $result['head_after'] !== $execution->head || $result['tree_after'] !== $execution->tree,
            'head' => $result['head_after'], 'tree' => $result['tree_after']];
    }

    public function stop(OperationRun $run): void
    {
        try {
            $this->stopOwned($run->operation());
        } catch (Throwable $exception) {
            throw new OperationStopPending(previous: $exception);
        }
    }

    private function stopOwned(Operation $operation): void
    {
        $this->execution->synchronized($operation->target_id, function () use ($operation): void {
            $group = Task::topLevel()->with('taskable')->findOrFail($operation->target_id);
            $this->leases->cancelQueued($group, 'operation:'.$operation->id);
            // Find the reservation even if the worker died before checkpointing state.execution.
            $lease = TaskWorkspaceLeaseRecord::query()->where('group_id', $group->id)->where('owner', 'operation:'.$operation->id)
                ->where('kind', 'script')->where('status', 'held')->first();
            if ($lease instanceof TaskWorkspaceLeaseRecord) {
                $instance = $group->taskable;
                if (! $instance instanceof Instance) {
                    throw new TaskWorkspaceWaiting('The owned script workspace is unavailable.');
                }
                $this->requireOwnership($operation, $group, $instance, $lease);
                $this->leases->reconcile($group);
                $this->leases->within($lease, fn () => $this->release($lease, $this->stopAndSnapshot($group, $lease)));
            }
        });
    }

    private function stopAndSnapshot(Task $group, TaskWorkspaceLeaseRecord $lease): TaskWorkspaceSnapshot
    {
        $instance = $group->taskable;
        if (! $instance instanceof Instance || $instance->id !== $lease->instance_id) {
            throw new TaskWorkspaceWaiting('The exact script workspace is unavailable.');
        }
        $id = $lease->getAttribute('check_run_id');
        if (is_string($id)) {
            $process = $this->runner->recoverStart($instance, $id);
            if ($process !== null) {
                $this->requireProcessIdentity($lease, $process);
                $this->runner->cancel($instance, $process);
                $reading = $this->runner->readScript($instance, $process);
                if (! in_array($reading['state'] ?? null, ['finished', 'interrupted'], true)) {
                    throw new TaskWorkspaceWaiting('The owned script and its descendants are not proven terminal.');
                }
            }
        }

        return is_string($id) ? app(TaskCheckRunner::class)->snapshot($instance) : new TaskWorkspaceSnapshot($lease->head_before ?? '', $lease->tree_before ?? '');
    }

    private function release(TaskWorkspaceLeaseRecord $lease, TaskWorkspaceSnapshot $after): void
    {
        $this->leases->release($lease, $after);
        if ($lease->fresh()?->status !== 'released') {
            throw new TaskWorkspaceWaiting('The script still has unresolved remote ownership.');
        }
    }

    public function abandon(Operation $operation, OperationOutcome $failure, ?string $failedStep): OperationOutcome
    {
        $group = Task::topLevel()->find($operation->target_id);
        if ($group instanceof Task) {
            $this->leases->cancelQueued($group, 'operation:'.$operation->id);
        }

        $this->stopOwned($operation);

        return $failure;
    }
}
