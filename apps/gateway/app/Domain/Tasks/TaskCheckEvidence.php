<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Projects\LifecyclePhase;
use App\Domain\TaskVms\TaskVmSettings;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\ProjectLifecycleStep;
use App\Models\Task;
use App\Models\TaskCheck;
use Illuminate\Support\Carbon;

/** Check evidence and setup for a requested keyed operation. Chooses no lifecycle action. */
final readonly class TaskCheckEvidence
{
    private const string INVALID_DELIVERABLE_STEP = 'invalid_deliverable';

    private const string CHECK_ERROR_STEP = 'check_error';

    public function __construct(private TaskWorkspaceStateReader $workspace, private TaskBroadcasts $broadcasts, private TaskCheckOutputBuilder $outputBuilder) {}

    public function recordReading(TaskCheck $check, TaskCheckReading $reading): void
    {
        $changed = $reading->headAfter !== $check->head_before || $reading->treeAfter !== ($reading->treeBefore ?? $check->tree_before);
        $values = $reading->state === 'lost'
            ? ['status' => TaskCheckStatus::Lost->value, 'output' => $this->outputBuilder->tail($reading->output)]
            : [
                'status' => match (true) {
                    // A changed tree must not hide these. The task would retry as changed and drop the cause.
                    $reading->failedStep === self::CHECK_ERROR_STEP || $reading->failedStep === self::INVALID_DELIVERABLE_STEP => TaskCheckStatus::Failed->value,
                    $changed => TaskCheckStatus::Changed->value,
                    $reading->exitCode === 0 => TaskCheckStatus::Passed->value,
                    default => TaskCheckStatus::Failed->value,
                },
                'head_after' => $reading->headAfter,
                'tree_after' => $reading->treeAfter,
                'exit_code' => $reading->exitCode,
                'changed_paths' => json_encode($reading->changedPaths, JSON_THROW_ON_ERROR),
                'failed_step' => $reading->failedStep,
                'output' => $this->outputBuilder->tail($reading->output),
                'deliverable_evidence' => $this->redactedEvidence($reading),
            ];

        $finishedAt = $reading->finishedAt === null ? now() : Carbon::createFromTimestamp($reading->finishedAt);
        $updated = TaskCheck::query()->whereKey($check->id)->where('status', TaskCheckStatus::Running->value)
            ->update([...$values, 'finished_at' => $finishedAt, 'updated_at' => now()]);
        $check->refresh();
        app(TaskWorkspaceLease::class)->finishedCheck($check, $reading);
        $groupId = Task::query()->whereKey($check->task_id)->value('parent_id');
        if ($updated === 1 && is_int($groupId)) {
            $this->broadcasts->groupChanged($groupId);
        }
    }

    private function redactedEvidence(TaskCheckReading $reading): ?string
    {
        $evidence = TaskDeliverableEvidence::fromArray($reading->deliverables);
        if ($evidence === null) {
            return null;
        }
        $commands = $evidence->commands;
        foreach ($commands as &$command) {
            $command['output'] = $this->outputBuilder->tail($command['output']);
            if (isset($command['base_output'])) {
                $command['base_output'] = $this->outputBuilder->tail($command['base_output']);
            }
        }

        $data = $reading->deliverables;
        $data['commands'] = $commands;

        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{start: string|null, commands: list<array{id: string, command: string, directory: string, fails_on_base?: bool, paths?: list<string>}>, topology?: bool}|null
     */
    public function handoffCheck(Task $task): ?array
    {
        $check = $this->deliverableCheck($task);
        $group = $task->parent;

        $patterns = app(TaskVmSettings::class)->topologyPaths[(int) $group->project_id] ?? [];
        if ($patterns !== [] && TaskTopology::forGroup($group)->isEmpty()) {
            $check = [...($check ?? ['start' => null, 'commands' => []]), 'topology' => true];
        }

        return $check;
    }

    /**
     * ADR 0133: what the handoff check needs to record deliverable evidence, or null for a subtask without deliverables.
     * ADR 0163: a command with fails_on_base true also runs against the start commit with its paths overlaid.
     *
     * @return array{start: string|null, commands: list<array{id: string, command: string, directory: string, fails_on_base?: bool, paths?: list<string>}>}|null
     */
    private function deliverableCheck(Task $task): ?array
    {
        $deliverables = $task->deliverableList();
        if ($deliverables === []) {
            return null;
        }
        $commands = [];
        foreach ($deliverables as $deliverable) {
            if ($deliverable->type !== TaskDeliverableType::Command) {
                continue;
            }
            $command = ['id' => $deliverable->id, 'command' => $deliverable->command, 'directory' => TaskDeliverable::relative($deliverable->directory) ?: '.'];
            if ($deliverable->fails_on_base) {
                $command['fails_on_base'] = true;
                $command['paths'] = $deliverable->paths;
            }
            $commands[] = $command;
        }

        $start = TaskReviewBase::commit($task);

        return ['start' => $start !== '' ? $start : null, 'commands' => $commands];
    }

    public function groupImplementerStarted(Task $group): bool
    {
        return Task::query()->where('parent_id', $group->id)->whereNotNull('implementer_agent_thread_id')->exists()
            || AgentThread::query()->where('task_group_id', $group->id)->where('role', TaskThreadRole::Implementer->value)->where('external_id', 'not like', AgentThread::PendingPrefix.'%')->exists();
    }

    private function implementerTurnStarted(Task $task): bool
    {
        $threadId = $task->implementer_agent_thread_id;
        if ($threadId !== null) {
            $externalId = AgentThread::query()->whereKey($threadId)->value('external_id');
            if (is_string($externalId) && ! str_starts_with($externalId, AgentThread::PendingPrefix)) {
                return true;
            }
        }

        return AgentThread::query()
            ->where('task_id', $task->id)
            ->where('role', TaskThreadRole::Implementer->value)
            ->where('external_id', 'not like', AgentThread::PendingPrefix.'%')
            ->exists();
    }

    /** @return list<array{name: string, command: string, timeout_seconds: int}> */
    public function baselineSetup(Task $group): array
    {
        return array_values(ProjectLifecycleStep::query()
            ->where('project_id', $group->project_id)
            ->where('phase', LifecyclePhase::Setup->value)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(static fn (ProjectLifecycleStep $step): array => ['name' => $step->name, 'command' => $step->command, 'timeout_seconds' => $step->timeout_seconds])
            ->all());
    }

    public function recordSubtaskStart(Task $task): void
    {
        if (is_string($task->subtask_start_commit) && $task->subtask_start_commit !== '') {
            return;
        }
        if ($task->continuation_of_task_id !== null) {
            $source = Task::query()->find($task->continuation_of_task_id);
            if ($source instanceof Task) {
                $sourceStart = TaskReviewBase::commit($source);
                if ($sourceStart !== '') {
                    $task->update(['subtask_start_commit' => $sourceStart]);
                }

                return;
            }
        }
        if ($this->implementerTurnStarted($task)) {
            return;
        }
        $instance = $task->parent()->with('taskable')->first()?->taskable;
        if (! $instance instanceof Instance) {
            return;
        }
        $head = $this->workspace->headCommit($instance);
        if (! is_string($head) || $head === '') {
            return;
        }
        $task->update(['subtask_start_commit' => $head]);
    }
}
