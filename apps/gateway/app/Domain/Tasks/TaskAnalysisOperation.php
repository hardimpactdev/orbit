<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Data\Tasks\StartTaskAnalysisData;
use App\Data\Tasks\TaskAnalysisExecutionData;
use App\Data\Tasks\TaskAnalysisTerminalData;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationRun;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStepPlan;
use App\Domain\Operations\OperationStopPending;
use App\Domain\Operations\OperationWaitingKind;
use App\Domain\Operations\OperationWaitingStep;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Infrastructure\TaskVms\TaskVmWorkspace;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskWorkspaceLeaseRecord;
use Illuminate\Support\Str;
use Throwable;

/** One keyed fixed repository analysis, using the check runner's durable ownership, never a TaskCheck candidate. */
final readonly class TaskAnalysisOperation implements OperationWaitingKind, OperationWaitingStep
{
    public function __construct(private TaskOperations $operations, private TaskVmWorkspace $vms, private TaskWorkspaceLease $leases, private TaskExecutionLock $execution, private TaskAnalysisRunner $runner) {}

    public function validate(Task $group, int $subtask): void
    {
        $this->operations->requireCurrentSubtask($group, $subtask);
    }

    public function run(Operation $operation, OperationRun $run): OperationOutcome
    {
        $run->plan([new OperationStepPlan('workspace_ready', true), new OperationStepPlan('analysis', true), new OperationStepPlan('report', true)]);
        $run->step('workspace_ready', fn (): mixed => $this->read($run));
        $reading = $this->checkpoint($run->step('analysis', fn (): mixed => $this->read($run)));
        $result = $this->checkpoint($run->step('report', fn (): array => $this->result($operation, $reading)));

        return OperationOutcome::succeeded($result);
    }

    /** @return array<string, mixed> */
    private function checkpoint(mixed $value): array
    {
        if (! is_array($value)) {
            throw new \LogicException('The analysis step must retain a complete object checkpoint.');
        }
        $record = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new \LogicException('Analysis checkpoints must use named fields.');
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
                $input = StartTaskAnalysisData::from($operation->input);
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
                    throw new ResourceOperationException('tasks.no_workspace', 'The owned analysis workspace is unavailable.', 409);
                }
                $terminal = $operation->state['analysis_terminal'] ?? null;
                if (is_array($terminal)) {
                    $checkpoint = TaskAnalysisTerminalData::from($terminal);
                    $lease = TaskWorkspaceLeaseRecord::query()->findOrFail($checkpoint->lease_id);
                    $this->requireOwnership($operation, $group, $instance, $lease);

                    return $this->finishTerminal($lease, $terminal);
                }
                $reserved = $operation->state['execution'] ?? null;
                if (! is_array($reserved)) {
                    // A crash between the lease reservation and the state checkpoint cannot dispatch a second run.
                    $orphan = TaskWorkspaceLeaseRecord::query()->where('group_id', $group->id)->where('owner', 'operation:'.$operation->id)->where('kind', 'analysis')->where('status', 'held')->whereNotNull('check_run_id')->first();
                    if ($orphan instanceof TaskWorkspaceLeaseRecord) {
                        return $this->leases->within($orphan, function () use ($group, $orphan, $run): array {
                            $after = $this->stopAndSnapshot($group, $orphan);
                            $terminal = $run->remember('analysis_terminal', fn (): array => [
                                'lease_id' => $orphan->id, 'after' => ['head' => $after->head, 'tree' => $after->tree], 'reading' => null,
                                'error' => ['code' => 'tasks.analysis_failed', 'message' => 'The reserved analysis never dispatched; its identity is tombstoned.', 'status' => 422],
                            ]);

                            return $this->finishTerminal($orphan, $terminal);
                        });
                    }
                    $this->leases->run($group, 'analysis', 'operation:'.$operation->id, function () use ($operation, $run, $group, $instance, $input): void {
                        $inspection = $this->runner->inspectAnalysis($instance, $input->base);
                        $this->requireFresh($operation, $inspection);
                        $lease = $this->leases->currentScope() ?? throw new TaskWorkspaceWaiting('Analysis requires its admitted workspace scope.');
                        $execution = $run->remember('execution', function () use ($operation, $inspection, $lease, $instance, $group): array {
                            $id = str_replace('-', '', (string) Str::uuid());
                            $lease->update(['pid' => 0, 'process_started' => '', 'check_run_id' => $id, 'head_before' => $inspection['head'], 'tree_before' => $inspection['tree']]);

                            return ['execution_id' => $id, 'lease_id' => $lease->id, 'instance_id' => $instance->id, 'node_id' => $instance->node_id,
                                'repository' => $group->project->repository_url, 'branch' => $inspection['branch'], 'entrypoint_sha256' => $inspection['entrypoint_sha256'],
                                'base' => $operation->input['base'], 'head' => $inspection['head'], 'tree' => $inspection['tree'], 'plan_sha256' => $operation->input['plan_sha256']];
                        });
                        $input = array_intersect_key($operation->input, array_flip(['plan', 'plan_sha256', 'base', 'head', 'tree']));
                        $execution = TaskAnalysisExecutionData::from($execution);
                        $input['execution_id'] = $execution->execution_id;
                        $input['entrypoint_sha256'] = $execution->entrypoint_sha256;
                        $process = $this->runner->startAnalysis($instance, $input, $execution->execution_id);
                        $lease->update(['pid' => $process->pid, 'process_started' => $process->started]);
                    }, taskId: $input->subtask);
                    $run->wait(10);
                }
                $reserved = TaskAnalysisExecutionData::from($reserved);
                $lease = TaskWorkspaceLeaseRecord::query()->findOrFail($reserved->lease_id);
                $this->requireOwnership($operation, $group, $instance, $lease);
                $this->leases->reconcile($group);

                return $this->leases->within($lease, function () use ($operation, $run, $instance, $lease, $reserved): array {
                    $process = $this->runner->recoverStart($instance, $reserved->execution_id);
                    if ($process === null) {
                        $after = app(TaskCheckRunner::class)->snapshot($instance);
                        $terminal = $run->remember('analysis_terminal', fn (): array => [
                            'lease_id' => $lease->id, 'after' => ['head' => $after->head, 'tree' => $after->tree], 'reading' => null,
                            'error' => ['code' => 'tasks.analysis_failed', 'message' => 'The analysis start never dispatched; its identity is tombstoned.', 'status' => 422],
                        ]);

                        return $this->finishTerminal($lease, $terminal);
                    }
                    $this->requireProcessIdentity($lease, $process);
                    $lease->update(['pid' => $process->pid, 'process_started' => $process->started]);
                    $reading = $this->runner->readAnalysis($instance, $process);
                    if (! in_array($reading['state'] ?? null, ['finished', 'interrupted'], true)) {
                        $run->wait(10);
                    }
                    $error = null;
                    $failure = null;
                    try {
                        $this->result($operation, $reading);
                        $after = new TaskWorkspaceSnapshot($reading['result']['head_after'], $reading['result']['tree_after']);
                        // Store only validated safe complete evidence, never raw untrusted diagnostics.
                        unset($reading['output']);
                    } catch (ResourceOperationException $exception) {
                        // Rejected completion metadata cannot become the lease's release snapshot.
                        $after = app(TaskCheckRunner::class)->snapshot($instance);
                        if ($exception instanceof TaskAnalysisFailure && (! is_array($reading['result'] ?? null) || ($reading['result']['head_after'] ?? null) !== $after->head || ($reading['result']['tree_after'] ?? null) !== $after->tree)) {
                            $exception = new ResourceOperationException('tasks.analysis_report_invalid', 'The analysis failure completion does not match the owned workspace.', 422);
                        }
                        $error = ['code' => $exception->errorCode, 'message' => $exception->getMessage(), 'status' => $exception->status];
                        $failure = $exception instanceof TaskAnalysisFailure ? $exception->result : null;
                        $reading = null;
                    }
                    // Both success and rejection survive a crash after release, before step/result persistence.
                    $terminal = $run->remember('analysis_terminal', fn (): array => ['lease_id' => $lease->id, 'after' => ['head' => $after->head, 'tree' => $after->tree], 'reading' => $reading, 'error' => $error, 'failure' => $failure]);

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
        $terminal = TaskAnalysisTerminalData::from($checkpoint);
        $this->release($lease, new TaskWorkspaceSnapshot($terminal->after['head'], $terminal->after['tree']));
        if ($terminal->error !== null) {
            throw new ResourceOperationException($terminal->error['code'], $terminal->error['message'], $terminal->error['status']);
        }

        return $terminal->reading ?? throw new \LogicException('The successful terminal checkpoint has no complete reading.');
    }

    private function requireOwnership(Operation $operation, Task $group, Instance $instance, TaskWorkspaceLeaseRecord $lease): void
    {
        $checkpoint = $operation->state['execution'] ?? null;
        $execution = is_array($checkpoint) ? TaskAnalysisExecutionData::from($checkpoint) : null;
        if ($lease->kind !== 'analysis' || $lease->group_id !== $group->id || $lease->instance_id !== $instance->id || $lease->owner !== 'operation:'.$operation->id
            || ($execution !== null && ($execution->lease_id !== $lease->id || $execution->instance_id !== $instance->id
                || $execution->execution_id !== $lease->check_run_id || $execution->head !== $lease->head_before || $execution->tree !== $lease->tree_before
                || ($lease->status === 'held' && $execution->node_id !== $instance->node_id)))) {
            throw new TaskWorkspaceWaiting('The reserved analysis belongs to another workspace, Node, or owner.');
        }
    }

    private function requireProcessIdentity(TaskWorkspaceLeaseRecord $lease, TaskCheckProcess $process): void
    {
        if ($process->runId !== $lease->check_run_id || $process->head !== $lease->head_before || $process->tree !== $lease->tree_before) {
            throw new TaskWorkspaceWaiting('The recovered process does not identify the reserved analysis.');
        }
    }

    /** @param array<string, mixed> $inspection */
    private function requireFresh(Operation $operation, array $inspection): void
    {
        if (($inspection['stale'] ?? false) === true) {
            throw new ResourceOperationException('tasks.analysis_stale', 'The full base is missing or is not an ancestor of the candidate.', 409);
        }
        if (($inspection['unavailable'] ?? false) === true || ! is_string($inspection['entrypoint_sha256'] ?? null) || ! is_string($inspection['branch'] ?? null)) {
            throw new ResourceOperationException('tasks.analysis_unavailable', 'The fixed tracked executable, base, or required repository tooling is unavailable.', 422);
        }
        $group = Task::topLevel()->with('taskable', 'project')->findOrFail($operation->target_id);
        $instance = $group->taskable;
        $provision = Operation::query()->where('kind', OperationKinds::TasksProvision)->where('target_type', OperationKinds::TaskGroupTarget)
            ->where('target_id', $group->id)->where('status', OperationStatus::Succeeded->value)->orderBy('id')->first();
        $recorded = $instance instanceof Instance ? TaskAnalysisBase::fromRecord($provision?->state['analysis_base'] ?? null, $instance->id) : null;
        if ($recorded === null || $recorded['repository'] !== $group->project->repository_url
            || $recorded['branch'] !== $instance->branch) {
            throw new ResourceOperationException('tasks.analysis_unavailable', 'Provision did not record an initial analysis base for this workspace.', 422);
        }
        if ($recorded['base'] !== $operation->input['base'] || $inspection['head'] !== $operation->input['head'] || $inspection['tree'] !== $operation->input['tree']) {
            throw new ResourceOperationException('tasks.analysis_stale', 'The fixed base or fresh whole-workspace candidate differs.', 409);
        }
    }

    /** @param array<string, mixed> $reading
     * @phpstan-assert array{result: array{head_after: string, tree_after: string}} $reading
     *
     * @return array<string, mixed>
     */
    private function result(Operation $operation, array $reading): array
    {
        $result = $reading['result'] ?? [];
        if (($reading['state'] ?? null) !== 'finished') {
            throw new ResourceOperationException('tasks.analysis_failed', 'The analysis outcome is unknown after terminal proof.', 422);
        }
        if (! is_array($result) || ($result['analysis'] ?? null) !== true) {
            throw new ResourceOperationException('tasks.analysis_report_invalid', 'The analysis completion is not an object.', 422);
        }
        foreach (['head_after', 'tree_after'] as $identity) {
            if (! is_string($result[$identity] ?? null) || preg_match('/\A(?:[a-f0-9]{40}|[a-f0-9]{64})\z/D', $result[$identity]) !== 1) {
                throw new ResourceOperationException('tasks.analysis_report_invalid', 'Analysis completion identity is invalid.', 422);
            }
        }
        if (! is_int($result['exit_code'] ?? null) || ! is_int($result['duration_ms'] ?? null) || $result['duration_ms'] < 0
            || ! is_string($result['started_at'] ?? null) || ! is_string($result['finished_at'] ?? null)
            || ! is_int($result['report_bytes'] ?? null) || $result['report_bytes'] < 0) {
            throw new ResourceOperationException('tasks.analysis_report_invalid', 'Analysis completion timing or exit status is invalid.', 422);
        }
        if ($result['report_bytes'] > TaskAnalysisReport::MaxBytes) {
            // The transport retains a bounded prefix, not the complete oversized envelope.
            // Its reported count alone cannot establish an exact public required size.
            throw new TaskAnalysisFailure('tasks.analysis_report_too_large');
        }
        $bytes = is_string($result['report_base64'] ?? null) ? base64_decode($result['report_base64'], true) : false;
        if (! is_string($bytes) || strlen($bytes) !== $result['report_bytes']) {
            throw new ResourceOperationException('tasks.analysis_report_invalid', 'The complete analysis report is missing.', 422);
        }
        if ($result['exit_code'] !== 0) {
            TaskAnalysisReport::rejectFailure($bytes);
        }
        $execution = TaskAnalysisExecutionData::from($operation->state['execution'] ?? []);
        $envelope = TaskAnalysisReport::validate($bytes, $execution->toArray());
        $changed = $result['head_after'] !== $execution->head || $result['tree_after'] !== $execution->tree;
        if (! $changed && $result['exit_code'] !== 0) {
            throw new ResourceOperationException('tasks.analysis_failed', 'The repository analysis exited unsuccessfully.', 422);
        }
        // Structured evidence cannot be silently changed by transport redaction.
        if (app(CommandActivityInputSanitizer::class)->redactText($bytes) !== $bytes) {
            throw new ResourceOperationException('tasks.analysis_report_invalid', 'Analysis evidence contains fields requiring redaction.', 422);
        }
        $plan = StartTaskAnalysisData::from($operation->input)->plan;

        return ['outcome' => $changed ? 'changed' : $envelope['status'],
            'artifact' => ['sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes), 'content' => $bytes],
            'provenance' => ['operation_id' => $operation->id, 'group' => $operation->target_id, 'subtask' => $operation->input['subtask'],
                ...array_intersect_key($execution->toArray(), array_flip(['repository', 'branch', 'plan_sha256', 'base', 'instance_id', 'node_id', 'lease_id', 'execution_id', 'entrypoint_sha256'])),
                'plan_id' => $plan['id'], 'plan_revision' => $plan['revision'], 'before' => ['head' => $execution->head, 'tree' => $execution->tree],
                'after' => ['head' => $result['head_after'], 'tree' => $result['tree_after']], 'started_at' => $result['started_at'], 'finished_at' => $result['finished_at'],
                'duration_ms' => $result['duration_ms'], 'exit_code' => $result['exit_code'], 'mechanical_llm_calls' => 0]];
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
                ->where('kind', 'analysis')->where('status', 'held')->first();
            if ($lease instanceof TaskWorkspaceLeaseRecord) {
                $instance = $group->taskable;
                if (! $instance instanceof Instance) {
                    throw new TaskWorkspaceWaiting('The owned analysis workspace is unavailable.');
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
            throw new TaskWorkspaceWaiting('The exact analysis workspace is unavailable.');
        }
        $id = $lease->getAttribute('check_run_id');
        if (is_string($id)) {
            $process = $this->runner->recoverStart($instance, $id);
            if ($process !== null) {
                $this->requireProcessIdentity($lease, $process);
                $this->runner->cancel($instance, $process);
                $reading = $this->runner->readAnalysis($instance, $process);
                if (! in_array($reading['state'] ?? null, ['finished', 'interrupted'], true)) {
                    throw new TaskWorkspaceWaiting('The owned analysis and its descendants are not proven terminal.');
                }
            }
        }

        return is_string($id) ? app(TaskCheckRunner::class)->snapshot($instance) : new TaskWorkspaceSnapshot($lease->head_before ?? '', $lease->tree_before ?? '');
    }

    private function release(TaskWorkspaceLeaseRecord $lease, TaskWorkspaceSnapshot $after): void
    {
        $this->leases->release($lease, $after);
        if ($lease->fresh()?->status !== 'released') {
            throw new TaskWorkspaceWaiting('The analysis still has unresolved remote ownership.');
        }
    }

    public function abandon(Operation $operation, OperationOutcome $failure, ?string $failedStep): OperationOutcome
    {
        $group = Task::topLevel()->find($operation->target_id);
        if ($group instanceof Task) {
            $this->leases->cancelQueued($group, 'operation:'.$operation->id);
        }

        $this->stopOwned($operation);

        // The kind, not the generic runner, owns this closed failure contract. A lost
        // failed-step/result write must retain the original typed refusal, not worker_lost.
        $checkpoint = $operation->state['analysis_terminal'] ?? null;
        if ($failure->status === OperationStatus::Failed && $failedStep === 'analysis' && is_array($checkpoint)) {
            $terminal = TaskAnalysisTerminalData::from($checkpoint);
            if ($terminal->failure !== null) {
                $group = Task::topLevel()->with('taskable')->findOrFail($operation->target_id);
                $instance = $group->taskable;
                $lease = TaskWorkspaceLeaseRecord::query()->findOrFail($terminal->lease_id);
                if (! $instance instanceof Instance || ! is_array($operation->state['execution'] ?? null)) {
                    return $failure;
                }
                $this->requireOwnership($operation, $group, $instance, $lease);
                $typed = TaskAnalysisFailure::fromResult($terminal->failure);
                if ($lease->status === 'released' && $terminal->reading === null && ($terminal->error['code'] ?? null) === $typed->errorCode) {
                    return OperationOutcome::failed($typed->errorCode, $typed->result);
                }
            }
        }

        return $failure;
    }
}
