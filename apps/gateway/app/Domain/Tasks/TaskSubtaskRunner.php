<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Tasks\RequestEndedPullRequestAssistanceAction;
use App\Actions\Tasks\ResumeDeliverableCorrectionAction;
use App\Actions\Tasks\RetryTaskBaselineAction;
use App\Actions\Tasks\StoreTaskCommentAction;
use App\Domain\GitHub\RequiredCheckState;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmSettings;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Project;
use App\Models\ProjectLifecycleStep;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class TaskSubtaskRunner
{
    private const int BASELINE_UNSTARTED_PID = 0;

    private const string INVALID_DELIVERABLE_STEP = 'invalid_deliverable';

    private const string CHECK_ERROR_STEP = 'check_error';

    public function __construct(
        private AgentSpawner $spawner,
        private TaskWorkspaceStateReader $workspace,
        private TaskPullRequestWatcher $pullRequestWatcher,
        private TaskTurnReceipts $receipts,
        private TaskCheckRunner $checks,
        private TaskBroadcasts $broadcasts,
        private TaskReviewPacketBuilder $reviewPackets,
        private TaskTurnFetcher $turnFetcher,
        private TaskTopologyAdmission $topologyAdmission,
        private DeliverablePathChecker $deliverablePaths,
        private TaskWorkspaceRoute $workspaceRoute,
        private TaskSessionObserver $observer,
        private TaskSessionActor $actor,
        private RetryTaskBaselineAction $retryBaseline,
        private ResumeDeliverableCorrectionAction $correctionResume,
        private TaskObservationAvailability $availability,
        private TaskCheckOutputBuilder $outputBuilder,
        private TaskHandoffCi $handoffCi,
    ) {}

    public function handleImplementerCompletion(TaskScheduler $scheduler, Task $group, Task $task, TaskSessionObservation $observation): bool
    {
        TaskGroupGuard::requireTick($group);
        $implementer = $observation->thread(TaskThreadRole::Implementer);
        if ($implementer === null) {
            return false;
        }
        $state = AgentThreadState::tryFrom($implementer->sessState);
        if ($state === AgentThreadState::Failed) {
            $scheduler->requestAssistance($task, $group, 'The implementer thread failed.', $observation);

            return true;
        }
        if (! in_array($state, [AgentThreadState::Idle, AgentThreadState::Done, AgentThreadState::AskingForInput], true)) {
            return false;
        }

        $thread = AgentThread::query()->find($implementer->threadId);
        $key = TaskConsultation::reminderKey($task, TaskThreadRole::Implementer);
        $pending = $thread instanceof AgentThread ? app(TaskWorkspaceLease::class)->pendingSend($thread, $key) : null;
        if ($thread instanceof AgentThread && $pending !== null) {
            try {
                app(AgentDriverRegistry::class)->get($thread->driver)->send($thread, $pending['message'], $pending['key']);
                $task->update(['completion_reminder_attempt' => $task->completion_attempt,
                    'completion_reminder_input_id' => $implementer->pendingUserInputId ?? $implementer->pendingApprovalId, 'communication_failures' => 0]);
            } catch (AgentDriverException $exception) {
                $scheduler->recordCommunicationFailure($task, $group, $exception->getMessage());
            }

            return true;
        }

        if ($task->completion_handoff_attempt !== null && ! $scheduler->newerTurnHasStopped($task->completion_handoff_turn_id, $implementer)) {
            return true;
        }

        $handoff = $scheduler->pendingReceipt($task, TaskThreadRole::Implementer);
        if ($handoff instanceof TaskComment && $scheduler->receiptOutcome($handoff) === TaskTurnOutcome::ReadyForReview
            && $task->checks()->where('task_comment_id', $handoff->id)->where('kind', TaskCheckKind::Handoff)->exists()) {
            // The durable receipt already admitted this check. Handle its result before
            // asking to read another receipt from the checkout that the check still owns.
            $this->checkHandoff($scheduler, $group, $task, $implementer, $handoff, $observation);

            return true;
        }

        try {
            $read = $scheduler->collectReceipt($group, $task, TaskThreadRole::Implementer);
        } catch (TaskTurnReceiptException $exception) {
            $scheduler->recordCommunicationFailure($task, $group, $exception->getMessage());

            return true;
        }
        $receipt = $scheduler->pendingReceipt($task, TaskThreadRole::Implementer);
        if ($receipt instanceof TaskComment && $scheduler->receiptOutcome($receipt) === TaskTurnOutcome::Blocked) {
            $scheduler->beginConsult($group, $task, $receipt, $observation);

            return true;
        }

        $items = $this->implementerItems($scheduler, $task, $implementer, $read, $receipt);
        if ($scheduler->failedItems($items) === [] && $receipt instanceof TaskComment) {
            $this->checkHandoff($scheduler, $group, $task, $implementer, $receipt, $observation);

            return true;
        }

        if ($scheduler->remindOrAssist($group, $task, $implementer, $items) && $receipt instanceof TaskComment) {
            $task->update(['completion_handoff_comment_id' => $receipt->id]);
        }

        return true;
    }

    /**
     * Runs the Project check for a handoff and acts on its state. The process state decides; no timer ends a check.
     * With a merge check, a passed check then waits for that check in CI on the handoff commit (/reference/tasks#handoff-ci).
     */
    private function checkHandoff(TaskScheduler $scheduler, Task $group, Task $task, TaskThreadObservation $implementer, TaskComment $receipt, TaskSessionObservation $observation): void
    {
        $instance = $group->taskable;
        if (! $instance instanceof Instance) {
            $scheduler->recordCommunicationFailure($task, $group, 'The task workspace is unavailable.');

            return;
        }
        $check = TaskCheck::query()->where('task_comment_id', $receipt->id)->latest('id')->first();
        if ($check instanceof TaskCheck && $check->status === TaskCheckStatus::Running) {
            try {
                $reading = app(TaskWorkspaceLease::class)->readCheck($instance, $check->process());
            } catch (TaskCheckException $exception) {
                $scheduler->recordCommunicationFailure($task, $group, $exception->getMessage());

                return;
            }
            if ($reading->state === 'running') {
                $scheduler->clearCommunicationFailures($task);

                return;
            }
            $this->recordReading($check, $reading);
        }

        $status = $check?->status;
        if ($check instanceof TaskCheck && $status === TaskCheckStatus::Passed) {
            $evidence = TaskDeliverableEvidence::fromArray($check->deliverable_evidence);
            $failures = TaskDeliverableVerifier::failures($task->deliverableList(), $evidence, $this->outputBuilder);
            $items = $failures === [] ? [] : [new TaskRubricItem('deliverables', false, "Orbit could not verify these deliverables:\n".implode("\n", array_map(static fn (string $failure): string => '- '.$failure, $failures))."\n", 'Deliverable verification failed; see check #'.$check->id.' for its evidence.')];
            $required = $scheduler->topologyRequiredItem($group, $evidence);
            if ($required instanceof TaskRubricItem) {
                $items[] = $required;
            }
            if ($items !== []) {
                if ($scheduler->remindOrAssist($group, $task, $implementer, $items)) {
                    $task->update(['completion_handoff_comment_id' => $receipt->id]);
                }

                return;
            }
            $mergeCheck = $group->project->handoffCheckName();
            $ci = $mergeCheck === null ? RequiredCheckState::Passed
                : $this->handoffCi->test($scheduler, $group, $task, $check, $receipt, $implementer->threadId, $mergeCheck);
            if ($ci === RequiredCheckState::Passed) {
                $handoff = ['completion_handoff_comment_id' => $receipt->id, 'communication_failures' => 0];
                if (is_string($implementer->turnId) && $implementer->turnId !== '') {
                    // The turn that handed off. A later implementer turn during review is a new handoff, not a reviewer edit.
                    $handoff['completion_handoff_turn_id'] = $implementer->turnId;
                }
                $task->update($handoff);
                $scheduler->settleImplementer($task, $observation->thread(TaskThreadRole::Reviewer));

                return;
            }
            if ($ci !== RequiredCheckState::Failed) {
                return;
            }
            // CI failed, and the check now records it: remind as for a failed check in the workspace.
            $status = $check->status;
        }
        // The implementer cannot change deliverables, so an invalid project or file asks for assistance with no reminder.
        if ($check instanceof TaskCheck && $status === TaskCheckStatus::Failed && $check->failed_step === self::INVALID_DELIVERABLE_STEP) {
            $reason = 'Command deliverable validation failed in check #'.$check->id.'; see its output for the invalid directory or overlay path.';
            $scheduler->requestAssistance(
                $task,
                $group,
                $reason,
                $observation,
                handled: ['completion_handoff_comment_id' => $receipt->id],
            );

            return;
        }
        $repeats = $check instanceof TaskCheck
            ? TaskCheck::query()->where('task_comment_id', $receipt->id)->where('status', $check->status->value)->count()
            : 0;
        $command = $group->project->taskCheckCommand();
        $name = $command ?? 'the task check';
        $owned = $command === null ? "Orbit's task check" : "Orbit's {$command}";
        $item = match (true) {
            $check instanceof TaskCheck && $status === TaskCheckStatus::Failed && $check->failed_step === TaskHandoffCi::FailedStep => new TaskRubricItem('check_passed', false, "{$name} passed, and Orbit committed your work as {$check->commit_sha}. The handoff did not pass CI:\n\n".$check->output."\n\nYour work stays committed: build on that commit, and do not reset or rewrite it.\n"),
            $check instanceof TaskCheck && $status === TaskCheckStatus::Failed => new TaskRubricItem('check_passed', false, "Orbit ran {$name}, and it failed with exit code {$check->exit_code}. The end of its output:\n\n".$this->outputBuilder->build((string) $check->output)."\n"),
            $status === TaskCheckStatus::Cancelled => new TaskRubricItem('check_passed', false, "An operator cancelled {$owned} before it finished."),
            $check instanceof TaskCheck && $status === TaskCheckStatus::Changed => new TaskRubricItem('check_passed', false, "The workspace changed while {$name} ran. Changed paths: ".implode(', ', $check->changed_paths ?? []).'. Make the check leave the tree unchanged, for example by ignoring the files it writes.'),
            default => null,
        };
        if ($item instanceof TaskRubricItem) {
            if ($scheduler->remindOrAssist($group, $task, $implementer, [$item], $status === TaskCheckStatus::Failed ? $check : null)) {
                $task->update(['completion_handoff_comment_id' => $receipt->id]);
            }

            return;
        }
        if ($status === TaskCheckStatus::Lost && $repeats >= 2) {
            $scheduler->requestAssistance($task, $group, "{$owned} stopped twice without a result.", $observation, handled: ['completion_handoff_comment_id' => $receipt->id]);

            return;
        }

        try {
            $leases = app(TaskWorkspaceLease::class);
            $process = $leases->withReceipt($group, $task->id, $implementer->threadId,
                fn (): TaskCheckProcess => $leases->startCheck($group, $task, $command, [], $this->handoffCheck($task), commentId: $receipt->id));
        } catch (TaskCheckException $exception) {
            $scheduler->recordCommunicationFailure($task, $group, $exception->getMessage());

            return;
        }
        $task->update(['handoff_reopen_reason' => null]);
        TaskCheck::query()->updateOrCreate([
            'task_id' => $task->id, 'pid' => $process->pid, 'process_started' => $process->started,
        ], [
            'task_id' => $task->id,
            'task_comment_id' => $receipt->id,
            'kind' => TaskCheckKind::Handoff,
            'status' => TaskCheckStatus::Running,
            'pid' => $process->pid,
            'process_started' => $process->started,
            'head_before' => $process->head,
            'tree_before' => $process->tree,
            'started_at' => now(),
        ]);
        $scheduler->clearCommunicationFailures($task);
    }

    /**
     * Records a finished or lost check. The write applies only while the check is still running, so a cancel wins.
     */
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

    /** @return list<TaskRubricItem> */
    private function implementerItems(TaskScheduler $scheduler, Task $task, TaskThreadObservation $thread, ?TaskTurnReceipt $read, ?TaskComment $receipt): array
    {
        $items = [];
        if (! $receipt instanceof TaskComment && is_string($task->handoff_reopen_reason)) {
            // Orbit reopened the handoff (/reference/tasks#handoff-ci); the reminder names why first.
            $items[] = new TaskRubricItem('handoff_reopened', false, $task->handoff_reopen_reason);
        }
        $items[] = $scheduler->receiptItem($read, $receipt);
        $confirmation = $scheduler->confirmationItem($task, $receipt, TaskThreadRole::Implementer);
        if ($confirmation instanceof TaskRubricItem) {
            $items[] = $confirmation;
        }
        $waiting = $scheduler->waitingItem($thread);
        if ($waiting instanceof TaskRubricItem) {
            $items[] = $waiting;
        }

        return $items;
    }

    /**
     * @return array{start: string|null, commands: list<array{id: string, command: string, directory: string, fails_on_base?: bool, paths?: list<string>}>, topology?: bool}|null
     */
    private function handoffCheck(Task $task): ?array
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

    /** @throws TaskTurnReceiptException */
    public function prepareTurn(TaskScheduler $scheduler, Task $group, Task $task, TaskThreadRole $role, ?int $threadId = null, bool $alreadyFetched = false, ?string $sendKey = null, bool $topologyAdmitted = false): bool
    {
        TaskGroupGuard::requireTick($group);
        $leases = app(TaskWorkspaceLease::class);
        if ($threadId === null) {
            return $leases->run($group, $role->value, 'prepare:'.$task->id.':'.$role->value,
                fn (): bool => $this->prepareAdmittedTurn($scheduler, $group, $task, $role, $threadId, $alreadyFetched, $sendKey, $topologyAdmitted), taskId: $task->id);
        }
        $thread = AgentThread::query()->findOrFail($threadId);

        return $leases->withThreadLock($thread,
            fn (): bool => $this->prepareAdmittedTurn($scheduler, $group, $task, $role, $threadId, $alreadyFetched, $sendKey, $topologyAdmitted));
    }

    private function prepareAdmittedTurn(TaskScheduler $scheduler, Task $group, Task $task, TaskThreadRole $role, ?int $threadId, bool $alreadyFetched, ?string $sendKey, bool $topologyAdmitted): bool
    {
        $thread = $threadId === null ? null : AgentThread::query()->find($threadId);
        if ($thread instanceof AgentThread && (app(TaskWorkspaceLease::class)->retrying($thread, $sendKey)
            || app(TaskWorkspaceLease::class)->inputContinuation($thread))) {
            return true;
        }
        if ($threadId !== null) {
            app(TaskWorkspaceLease::class)->turn($group, $role, 'thread:'.$threadId, $threadId);
            if ($role === TaskThreadRole::Implementer) {
                $this->recordSubtaskStart($task);
            }
        }
        $instance = $group->taskable;
        if (! $instance instanceof Instance) {
            throw new TaskTurnReceiptException('The task workspace is unavailable.');
        }
        if (! $topologyAdmitted && ! $this->admitTopology($scheduler, $group, $task)) {
            return false;
        }
        if (! $alreadyFetched) {
            $this->turnFetcher->beforeTurn($group);
        }
        $context = $role === TaskThreadRole::Reviewer ? $this->reviewPackets->reviewContext($task) : null;
        $mode = TaskTurnMode::forTask($task, $role);
        $this->receipts->prepare($instance, $role, $role === TaskThreadRole::Reviewer && $task->opensPullRequest(), $task->deliverableList(), $threadId, $mode, $context);

        return true;
    }

    /**
     * A preparation wait never consumes a spawn or communication-failure attempt. A failed preparation asks for
     * assistance, and a resolution prepares the topology again.
     */
    private function admitTopology(TaskScheduler $scheduler, Task $group, Task $task): bool
    {
        try {
            $this->topologyAdmission->prepare($group, $task);
        } catch (TaskCapacityException $exception) {
            $group->update(['capacity_wait_reason' => $exception->getMessage()]);

            return false;
        } catch (TaskTopologyFailedException $exception) {
            $group->update(['capacity_wait_reason' => null]);
            $scheduler->requestAssistance($task, $group, $exception->getMessage());

            return false;
        }
        if ($group->capacity_wait_reason !== null) {
            $group->update(['capacity_wait_reason' => null]);
        }

        return true;
    }

    /**
     * Finishes the group's pending workspace Route and returns why it failed, or null. Only a routed task VM workspace
     * is pending: its baseline check starts before its Route.
     */
    public function finishWorkspaceRoute(Task $group): ?string
    {
        $group = $group->fresh(['project', 'taskable']) ?? $group;
        if (! $this->workspaceRoute->pending($group)) {
            return null;
        }
        try {
            return $this->workspaceRoute->complete($group)?->cause;
        } catch (Throwable $exception) {
            report($exception);

            return InstanceProvisionFailure::fromException($exception)->cause;
        }
    }

    /**
     * Work that uses the workspace Route waits for it. A failure counts as a communication failure, so Orbit tries
     * again on the next ticks and asks for assistance after the fifth.
     */
    public function workspaceRouteReady(TaskScheduler $scheduler, Task $group, Task $task): bool
    {
        $failure = $this->finishWorkspaceRoute($group);
        if ($failure === null) {
            return true;
        }
        $scheduler->recordCommunicationFailure($task, $group, TaskScheduler::WorkspaceRouteFailedReason.' '.$failure);

        return false;
    }

    public function startTask(TaskScheduler $scheduler, Task $task): Task
    {
        TaskGroupGuard::requireTick($task);
        $task->load('parent');
        if (TaskExecutionHold::active($task->parent) || RequestEndedPullRequestAssistanceAction::isReason($task->parent->assistance_reason)) {
            return $task->parent->fresh(['tasks', 'project', 'taskable']) ?? $task->parent;
        }
        $started = $this->activateRunningTask($task);
        $this->beginRunningTask($scheduler, $started);

        $group = $started->parent;

        return $group->fresh(['tasks', 'project', 'taskable']) ?? $group;
    }

    public function startFirstTask(TaskScheduler $scheduler, Task $group): void
    {
        TaskGroupGuard::requireTick($group);
        $first = $this->orderedTasks($group->tasks)->first();

        if (! $first instanceof Task || $first->status !== TaskStatus::Todo) {
            return;
        }

        try {
            $this->startTask($scheduler, $first);
        } catch (TaskSequenceException) {
        }
    }

    /**
     * @throws TaskSequenceException
     */
    private function activateRunningTask(Task $task): Task
    {
        return DB::transaction(function () use ($task): Task {
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            $group = Task::topLevel()
                ->lockForUpdate()
                ->findOrFail($locked->parent_id);
            $tasks = $this->lockedTasks($group);

            if (! TaskExecutionHold::active($group)) {
                $this->markRunning($locked, $tasks);
            }

            return $locked->fresh(['parent.tasks', 'parent.project', 'parent.taskable']) ?? $locked;
        });
    }

    /**
     * @param  Collection<int, Task>  $tasks
     *
     * @throws TaskSequenceException
     */
    public function markRunning(Task $task, Collection $tasks): void
    {
        TaskGroupGuard::requireTick($task);
        $running = $this->runningSibling($tasks, $task);

        if ($running instanceof Task) {
            throw TaskSequenceException::siblingRunning($task->requireGroupId(), $running->id);
        }

        $next = $this->lowestTodo($tasks);

        if (! $next instanceof Task || $next->id !== $task->id || ! $this->predecessorsCompleted($task, $tasks)) {
            throw TaskSequenceException::notNext($task->id, $task->requireGroupId());
        }

        $task->status = TaskStatus::Running;
        $task->started_at ??= now();
        $task->save();
    }

    /**
     * Starts a subtask the way startTask does: records the start commit, then runs the baseline check
     * when no implementer has started in the group yet, or starts the implementer.
     */
    public function beginRunningTask(TaskScheduler $scheduler, Task $task, bool $alreadyFetched = false): void
    {
        TaskGroupGuard::requireTick($task);
        TaskExecutionHold::run($task->parent, fn () => $this->beginAdmittedTask($scheduler, $task, $alreadyFetched));
    }

    private function beginAdmittedTask(TaskScheduler $scheduler, Task $task, bool $alreadyFetched = false): void
    {
        $group = $task->parent()->with(['project', 'taskable'])->firstOrFail();
        if ($task->isFinalReview()) {
            // An incoming pull request has no implementer before its first final review, so that review runs the
            // baseline on the fresh workspace first. Setup then serves the fixups that follow.
            if ($group->reviewsIncomingPullRequest() && ($this->baselineCheck($task)?->status === TaskCheckStatus::Running || $this->needsBaseline($task))) {
                $this->handleBaseline($scheduler, $group, $task);

                return;
            }
            $scheduler->startFinalReview($group, $task, $alreadyFetched);

            return;
        }
        if ($this->baselineCheck($task)?->status === TaskCheckStatus::Running) {
            $this->handleBaseline($scheduler, $group, $task);

            return;
        }
        if ($this->needsBaseline($task)) {
            $this->handleBaseline($scheduler, $group, $task);
        } else {
            $this->assignImplementer($scheduler, $task, $alreadyFetched);
        }
    }

    /**
     * A group checks its fresh workspace once, before any implementer has started in it.
     */
    private function needsBaseline(Task $task): bool
    {
        $started = Task::query()->where('parent_id', $task->parent_id)->whereNotNull('implementer_agent_thread_id')->exists()
            || AgentThread::query()->where('task_group_id', $task->parent_id)->where('role', TaskThreadRole::Implementer->value)->where('external_id', 'not like', TaskAgentSpawner::PendingPrefix.'%')->exists();

        // ADR 0203: an incoming pull request's first final review ran the baseline without an implementer.
        $scope = $task->parent->reviewsIncomingPullRequest()
            ? TaskCheck::query()->whereHas('task', fn ($query) => $query->where('parent_id', $task->parent_id))
            : TaskCheck::query()->where('task_id', $task->id);

        return ! $started && ! $scope->where('kind', TaskCheckKind::Baseline->value)
            ->where('status', TaskCheckStatus::Passed->value)->exists();
    }

    public function hasImplementer(Task $task): bool
    {
        return $task->implementer_agent_thread_id !== null
            || AgentThread::query()->where('task_id', $task->id)->where('role', TaskThreadRole::Implementer->value)->where('external_id', 'not like', TaskAgentSpawner::PendingPrefix.'%')->exists();
    }

    /**
     * The implementer's first turn has started once its conversation exists.
     * A reserved row has not started, so a failed start read can still be retried.
     */
    private function implementerTurnStarted(Task $task): bool
    {
        $threadId = $task->implementer_agent_thread_id;
        if ($threadId !== null) {
            $externalId = AgentThread::query()->whereKey($threadId)->value('external_id');
            if (is_string($externalId) && ! str_starts_with($externalId, TaskAgentSpawner::PendingPrefix)) {
                return true;
            }
        }

        return AgentThread::query()
            ->where('task_id', $task->id)
            ->where('role', TaskThreadRole::Implementer->value)
            ->where('external_id', 'not like', TaskAgentSpawner::PendingPrefix.'%')
            ->exists();
    }

    /**
     * Runs the Project's setup steps and check on the fresh workspace. The first implementer starts only
     * after it passes, so an agent never starts on a broken checkout.
     */
    private function handleBaseline(TaskScheduler $scheduler, Task $group, Task $task): void
    {
        $check = $this->baselineCheck($task);
        $instance = $group->taskable;
        if ($check instanceof TaskCheck && $check->status === TaskCheckStatus::Running && ($check->pid === self::BASELINE_UNSTARTED_PID || $check->process_started === '')) {
            if ($check->started_at->lt(now()->subSeconds(TaskScheduler::BASELINE_START_LIMIT_SECONDS))) {
                $scheduler->requestAssistance($task, $group, 'The baseline start was interrupted, and a check may still run in the workspace.');
            }

            return;
        }
        if ($check instanceof TaskCheck && $check->status === TaskCheckStatus::Running && $instance instanceof Instance) {
            try {
                $reading = app(TaskWorkspaceLease::class)->readCheck($instance, $check->process());
            } catch (TaskCheckException $exception) {
                $scheduler->recordCommunicationFailure($task, $group, $exception->getMessage());

                return;
            }
            if ($reading->state === 'running') {
                $scheduler->clearCommunicationFailures($task);

                return;
            }
            $this->recordReading($check, $reading);
        }

        $status = $check?->status;
        if ($status === TaskCheckStatus::Passed) {
            if (! $this->workspaceRouteReady($scheduler, $group, $task)) {
                return;
            }
            $scheduler->clearCommunicationFailures($task);
            if ($task->isFinalReview()) {
                $scheduler->startFinalReview($group, $task, false);

                return;
            }
            $this->assignImplementer($scheduler, $task);

            return;
        }
        $repeats = $check instanceof TaskCheck
            ? TaskCheck::query()->where('task_id', $task->id)->where('kind', TaskCheckKind::Baseline->value)->where('status', $check->status->value)->count()
            : 0;
        $branch = 'task-'.$group->id;
        if ($check instanceof TaskCheck && TaskSetupFailure::rateLimited($check)) {
            $attempts = TaskCheck::query()->where('task_id', $task->id)->where('kind', TaskCheckKind::Baseline->value)
                ->where('task_comment_id', $check->task_comment_id)->get()->filter(TaskSetupFailure::rateLimited(...))->count();
            if ($attempts <= 4) {
                $finished = $check->finished_at ?? $check->updated_at ?? $check->started_at;
                if (now()->lt($finished->copy()->addMinutes(2 ** ($attempts - 1)))) {
                    return;
                }
                $this->startBaseline($scheduler, $group, $task);

                return;
            }
        }
        $reason = match (true) {
            $check instanceof TaskCheck && $status === TaskCheckStatus::Failed && $check->failed_step !== null && $check->failed_step !== self::CHECK_ERROR_STEP => "The Project setup step \"{$check->failed_step}\" failed with exit code {$check->exit_code} on a fresh checkout of {$branch}, before any agent started. Fix the setup or the branch, then post a resolution on this subtask to retry the baseline. The task's check shows the output.",
            $check instanceof TaskCheck && $status === TaskCheckStatus::Failed => "The Project baseline check failed with exit code {$check->exit_code} on a fresh checkout of {$branch}, before any agent started. Fix the configured check or the branch, then post a resolution on this subtask to retry the baseline. The task's check shows the output.",
            $status === TaskCheckStatus::Cancelled => 'An operator cancelled the baseline check before any agent started.',
            $check instanceof TaskCheck && $status === TaskCheckStatus::Changed => 'The workspace changed while the baseline check ran. Changed paths: '.implode(', ', $check->changed_paths ?? []).'.',
            $status === TaskCheckStatus::Lost && $repeats >= 2 => 'The baseline check stopped twice without a result.',
            default => null,
        };
        if ($reason !== null) {
            $scheduler->requestAssistance($task, $group, $reason);

            return;
        }

        $this->startBaseline($scheduler, $group, $task);
    }

    private function startBaseline(TaskScheduler $scheduler, Task $group, Task $task): void
    {
        $instance = $group->taskable;
        if (! $instance instanceof Instance) {
            $scheduler->recordCommunicationFailure($task, $group, 'The task workspace is unavailable.');

            return;
        }
        $setup = array_values(ProjectLifecycleStep::query()
            ->where('project_id', $group->project_id)
            ->where('phase', LifecyclePhase::Setup->value)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(static fn (ProjectLifecycleStep $step): array => ['name' => $step->name, 'command' => $step->command, 'timeout_seconds' => $step->timeout_seconds])
            ->all());
        $command = $instance->project->taskCheckCommand();
        $claim = null;
        try {
            $process = app(TaskWorkspaceLease::class)->run($group, 'check', 'check:'.$task->id, function () use ($group, $task, $command, $setup, &$claim): ?TaskCheckProcess {
                $this->recordSubtaskStart($task);
                $claim = $this->claimBaseline($task);
                if (! $claim instanceof TaskCheck) {
                    return null;
                }

                return app(TaskWorkspaceLease::class)->startCheck($group, $task, $command, $setup);
            }, taskId: $task->id);
        } catch (TaskCheckException $exception) {
            if ($exception->startNotDispatched && $claim instanceof TaskCheck) {
                $this->releaseBaselineClaim($claim);
            }
            $scheduler->recordCommunicationFailure($task, $group, $exception->getMessage());

            return;
        }
        if (! $claim instanceof TaskCheck || ! $process instanceof TaskCheckProcess) {
            return;
        }
        $stored = TaskCheck::query()->whereKey($claim->id)
            ->where('status', TaskCheckStatus::Running->value)
            ->where('pid', self::BASELINE_UNSTARTED_PID)
            ->update([
                'pid' => $process->pid,
                'process_started' => $process->started,
                'head_before' => $process->head,
                'tree_before' => $process->tree,
                'updated_at' => now(),
            ]);
        $recorded = $claim->fresh();
        if ($stored !== 1 && (! $recorded instanceof TaskCheck || $recorded->status !== TaskCheckStatus::Running
            || $recorded->pid !== $process->pid || $recorded->process_started !== $process->started)) {
            try {
                $this->checks->cancel($instance, $process);
            } catch (TaskCheckException $exception) {
                $scheduler->recordCommunicationFailure($task, $group, $exception->getMessage());
            }

            return;
        }
        $this->broadcasts->groupChanged($group->id);
        $scheduler->clearCommunicationFailures($task);
    }

    /**
     * Reserves the one running baseline for this subtask before its process starts.
     * The subtask row lock decides, so the scheduler tick and the Todo move cannot both start one.
     */
    private function claimBaseline(Task $task): ?TaskCheck
    {
        return DB::transaction(function () use ($task): ?TaskCheck {
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            $group = Task::topLevel()->lockForUpdate()->findOrFail($locked->parent_id);
            if (TaskExecutionHold::active($group) || $locked->status !== TaskStatus::Running) {
                return null;
            }
            $running = TaskCheck::query()->where('task_id', $locked->id)
                ->where('kind', TaskCheckKind::Baseline->value)
                ->where('status', TaskCheckStatus::Running->value)
                ->lockForUpdate()
                ->exists();
            if ($running) {
                return null;
            }

            return TaskCheck::query()->create([
                'task_id' => $locked->id,
                'kind' => TaskCheckKind::Baseline,
                'task_comment_id' => $locked->resolution_delivered_comment_id,
                'status' => TaskCheckStatus::Running,
                'pid' => self::BASELINE_UNSTARTED_PID,
                'process_started' => '',
                'head_before' => '',
                'tree_before' => '',
                'started_at' => now(),
            ]);
        });
    }

    private function releaseBaselineClaim(TaskCheck $claim): void
    {
        TaskCheck::query()->whereKey($claim->id)
            ->where('status', TaskCheckStatus::Running->value)
            ->where('pid', self::BASELINE_UNSTARTED_PID)
            ->delete();
    }

    /**
     * The baseline that decides this subtask. A running claim wins, even before its process
     * starts. A newer row is a duplicate and is not a verdict.
     */
    private function baselineCheck(Task $task): ?TaskCheck
    {
        $running = TaskCheck::query()->where('task_id', $task->id)
            ->where('kind', TaskCheckKind::Baseline->value)
            ->where('status', TaskCheckStatus::Running->value)
            ->orderBy('id')
            ->first();
        if ($running instanceof TaskCheck) {
            return $running;
        }

        return TaskCheck::query()->where('task_id', $task->id)
            ->where('kind', TaskCheckKind::Baseline->value)
            ->when($task->resolution_delivered_comment_id !== null, static fn ($query) => $query->where('task_comment_id', $task->resolution_delivered_comment_id))
            ->latest('id')
            ->first();
    }

    /** Recheck immediately before spawning, including retries of an already running fixup. */
    private function skipStaleConflictFixup(TaskScheduler $scheduler, Task $task): bool
    {
        $feedback = is_string($task->fixup_problem) && str_starts_with($task->fixup_problem, 'review:');
        if ($scheduler->conflictBase($task) === null && ! $feedback) {
            return false;
        }
        $group = $task->parent()->with(['project', 'taskable'])->firstOrFail();
        $health = $this->pullRequestWatcher->health($group);
        if (! $health instanceof TaskPullRequestHealth) {
            return true;
        }
        if ($health->state === 'open' && ($health->conflicts || $feedback)) {
            return false;
        }
        $reason = match ($health->state) {
            'merged' => $feedback ? 'Cancelled because the pull request merged; no feedback fixup can proceed.' : 'Cancelled because the pull request merged; no conflict fixup is needed.',
            'closed' => $feedback ? 'Cancelled because the pull request closed without merging; no feedback fixup can proceed.' : 'Cancelled because the pull request closed without merging; no conflict fixup can proceed.',
            default => $health->mergeable === true
                ? 'Cancelled because the pull request is mergeable again; no conflict fixup is needed.'
                : null,
        };
        if ($reason === null) {
            return true;
        }

        $settled = DB::transaction(function () use ($task, $group, $reason): ?Task {
            $lockedGroup = Task::topLevel()->lockForUpdate()->findOrFail($group->id);
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            if ($lockedGroup->status !== TaskGroupStatus::Running
                || $locked->status !== TaskStatus::Running || $this->implementerTurnStarted($locked)) {
                return null;
            }
            TaskAssistance::apply($locked);
            $locked->update([
                'status' => TaskStatus::Cancelled,
                'settled_at' => now(),
                'completion_summary' => $reason,
            ]);
            $lockedGroup->update(['status' => TaskGroupStatus::Settling]);

            return $lockedGroup;
        });
        if ($settled instanceof Task) {
            // Reuse the observed terminal state so a missed approval is held before merge cleanup.
            $scheduler->checkReturningPullRequest($settled, $health);
            $scheduler->settle($settled, checkReturningPullRequest: false);
        }

        return true;
    }

    public function assignImplementer(TaskScheduler $scheduler, Task $task, bool $alreadyFetched = false): void
    {
        TaskGroupGuard::requireTick($task);
        TaskExecutionHold::run($task->parent, fn () => $this->assignAdmittedImplementer($scheduler, $task, $alreadyFetched));
    }

    private function assignAdmittedImplementer(TaskScheduler $scheduler, Task $task, bool $alreadyFetched = false): void
    {
        if ($task->status !== TaskStatus::Running || $this->skipStaleConflictFixup($scheduler, $task)) {
            return;
        }
        // A preparing topology can wait for many ticks, so admit it before the remote work below. prepareTurn then
        // does not admit it again.
        $group = $task->parent()->with(['project', 'taskable'])->first();
        if ($group instanceof Task && ! $this->admitTopology($scheduler, $group, $task)) {
            return;
        }

        if ($group instanceof Task && ! $this->workspaceRouteReady($scheduler, $group, $task)) {
            return;
        }
        if ($group instanceof Task && ! $this->validateImplementerDeliverablePaths($scheduler, $task, $group)) {
            return;
        }

        $threadId = null;
        try {
            if ($group instanceof Task) {
                $reserved = $task->implementer_agent_thread_id;
                if ($reserved === null && $this->spawner instanceof TaskAgentSpawner) {
                    $reserved = $this->spawner->reserveImplementer($task->fresh() ?? $task);
                }
                if (! $this->prepareTurn($scheduler, $group, $task, TaskThreadRole::Implementer, $reserved === null ? null : (int) $reserved, $alreadyFetched, topologyAdmitted: true)) {
                    return;
                }
                $threadId = $this->spawner->spawnImplementer($task->fresh() ?? $task);
            }
        } catch (TaskTurnReceiptException $exception) {
            Log::error('The turn command could not be installed for the implementer.', ['task_id' => $task->id, 'reason' => $exception->getMessage()]);
        }

        if ($threadId === null) {
            $scheduler->failSpawn($group, $task, 'implementer');

            return;
        }

        $task->implementer_agent_thread_id = $threadId;
        $task->save();
    }

    /** No thread is reserved or spawned until its deliverables pass on the actual review base. */
    private function validateImplementerDeliverablePaths(TaskScheduler $scheduler, Task $task, Task $group): bool
    {
        $base = TaskReviewBase::commit($task);
        if ($base === '') {
            $scheduler->requestAssistance($task, $group, TaskGroupGuard::DeliverableGatePrefix.'cannot start the implementer: the review base is unresolved.');

            return false;
        }

        try {
            $workspace = $group->taskable instanceof Instance ? $group->taskable : null;
            $read = fn (): array => $this->deliverablePaths->check($group->project, $task->deliverables ?? [], $base, 'resolved', $workspace);
            $leases = app(TaskWorkspaceLease::class);
            $pending = AgentThread::query()->where('task_group_id', $group->id)->where('task_id', $task->id)
                ->where('role', TaskThreadRole::Implementer->value)->where('external_id', 'like', TaskAgentSpawner::PendingPrefix.'%')->latest('id')->first();
            $errors = $pending instanceof AgentThread
                ? $leases->withThreadLock($pending, function () use ($leases, $group, $pending, $read): array {
                    $leases->turn($group, TaskThreadRole::Implementer, 'thread:'.$pending->id, $pending->id);

                    return $read();
                })
                : $leases->run($group, 'verdict', 'deliverable-paths:'.$task->id, $read, taskId: $task->id);
        } catch (TaskWorkspaceWaiting $exception) {
            throw $exception;
        } catch (ResourceOperationException $exception) {
            $scheduler->requestAssistance($task, $group, TaskGroupGuard::DeliverableGatePrefix."could not read base {$base}: ".$exception->getMessage());

            return false;
        }
        if ($errors !== []) {
            $scheduler->requestAssistance($task, $group, TaskGroupGuard::DeliverableGatePrefix.'failed before implementer start. '.implode(' ', $errors));

            return false;
        }

        return true;
    }

    /**
     * Records the workspace HEAD as this subtask's start. A failed read stays empty so a later tick
     * can try again, until the implementer's first turn starts. After that turn, a later HEAD would
     * hide the implementer's commits, so the start stays empty and the review uses its fallback base.
     * A start that is already recorded is not replaced.
     */
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

    /** @return Collection<int, Task> */
    public function lockedTasks(Task $group): Collection
    {
        $tasks = Task::query()
            ->where('parent_id', $group->id)
            ->orderBy('position')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $group->setRelation('tasks', $tasks);

        return $tasks;
    }

    /**
     * @param  Collection<int, Task>  $tasks
     * @return Collection<int, Task>
     */
    public function orderedTasks(Collection $tasks): Collection
    {
        return $tasks
            ->sortBy(static fn (Task $task): array => [$task->position, $task->id])
            ->values();
    }

    /** @param  Collection<int, Task>  $tasks */
    public function lowestTodo(Collection $tasks): ?Task
    {
        return $this->orderedTasks($tasks)->first(
            static fn (Task $task): bool => $task->status === TaskStatus::Todo,
        );
    }

    /** @param  Collection<int, Task>  $tasks */
    public function runningSibling(Collection $tasks, ?Task $except = null): ?Task
    {
        return $this->orderedTasks($tasks)->first(
            static fn (Task $task): bool => $task->status === TaskStatus::Running
                && ($except === null || $task->id !== $except->id),
        );
    }

    /** @param  Collection<int, Task>  $tasks */
    private function predecessorsCompleted(Task $task, Collection $tasks): bool
    {
        return $this->orderedTasks($tasks)
            ->filter(static fn (Task $candidate): bool => $candidate->position < $task->position
                || ($candidate->position === $task->position && $candidate->id < $task->id))
            ->every(static fn (Task $candidate): bool => in_array($candidate->status, [
                TaskStatus::Completed,
                TaskStatus::Cancelled,
                TaskStatus::Failed,
            ], true));
    }

    /** @param  list<TaskSessionDecision>  $decisions */
    public function tickSubtasks(TaskScheduler $scheduler, Task $group, bool $runningAtStart, array &$decisions): void
    {
        TaskGroupGuard::requireTick($group);
        if ($group->status === TaskGroupStatus::Completed
            || in_array($group->watched_pr_completion, ['merged', 'closed'], true)
            || RequestEndedPullRequestAssistanceAction::isReason($group->assistance_reason)) {
            return;
        }
        if ($runningAtStart) {
            $scheduler->resumeStrandedSubtask($group);
        }
        $tasks = $group->tasks
            ->filter(static fn (Task $task): bool => in_array($task->status, [TaskStatus::Running, TaskStatus::Reviewing], true))
            ->sortBy(static fn (Task $task): array => [$task->position, $task->id]);

        foreach ($tasks as $task) {
            $task = $task->fresh();

            if (! $task instanceof Task || ! in_array($task->status, [TaskStatus::Running, TaskStatus::Reviewing], true)) {
                continue;
            }
            if ($task->pending_resolution_comment_id !== null) {
                app(StoreTaskCommentAction::class)->retryPendingResolution($task);
                $task->refresh();
                $group = $group->fresh(['project', 'tasks', 'taskable']) ?? $group;
            }
            if ($task->status === TaskStatus::Running && ($task->deliverable_correction_resume['state'] ?? null) === 'pending') {
                $this->correctionResume->execute($task);
                $task->refresh();
                $group = $group->fresh(['project', 'tasks', 'taskable']) ?? $group;
            }
            if ($task->status === TaskStatus::Running && $task->assistance_requested && $scheduler->queueBaselineRetryOnGreenTip($group, $task)) {
                $task->refresh();
            }
            if ($this->retryBaseline->pending($task)) {
                try {
                    if (TaskExecutionHold::run($group, fn (): bool => $this->retryBaseline->recover($task)) === true) {
                        $task->refresh();
                        $group = $group->fresh(['project', 'tasks', 'taskable']) ?? $group;
                    }
                } catch (AgentDriverException $exception) {
                    $scheduler->recordCommunicationFailure($task, $group, $exception->getMessage());

                    continue;
                }
            }
            if ($task->assistance_requested || $scheduler->progressBlockedByAssistance($group)) {
                if ($task->assistance_requested && ! $group->assistance_requested && $task->assistance_kind instanceof AssistanceKind && is_string($task->assistance_reason)) {
                    $group = $group->fresh(['project', 'tasks', 'taskable']) ?? $group;
                    if (! $group->assistance_requested) {
                        $scheduler->requestAssistance($task, $group, $task->assistance_reason, null, $task->assistance_kind, $task->assistance_question);
                    }
                }
                if ($task->status === TaskStatus::Reviewing) {
                    $group = $group->fresh(['project', 'tasks', 'taskable']) ?? $group;
                    $scheduler->retryCommittedApproval($group, $task);
                }
                $resolution = $task->status === TaskStatus::Running ? $scheduler->pendingRelayResolution($task) : null;
                if ($resolution instanceof TaskComment && $this->spawner instanceof TaskAgentSpawner) {
                    $group = $group->fresh(['project', 'tasks', 'taskable']) ?? $group;
                    $scheduler->beginDirectionRelay($group, $task, $resolution);
                }
                if ($task->status === TaskStatus::Reviewing && $task->assistance_kind === AssistanceKind::Direction && $scheduler->subtaskReviewer($task) === null) {
                    $scheduler->replayHeldDirectionReview($task);
                }

                continue;
            }

            $group = $group->fresh(['project', 'tasks', 'taskable']) ?? $group;
            if ($task->status === TaskStatus::Running && $task->direction_relay_comment_id !== null) {
                $scheduler->handleDirectionRelay($group, $task);

                continue;
            }
            if ($task->status === TaskStatus::Running && $task->consult_comment_id !== null) {
                $scheduler->handleConsult($group, $task);

                continue;
            }
            if ($task->status === TaskStatus::Reviewing && $scheduler->retryCommittedApproval($group, $task)) {
                continue;
            }
            if ($task->status === TaskStatus::Running && ! $this->hasImplementer($task)) {
                $this->beginRunningTask($scheduler, $task);

                continue;
            }
            if ($task->status === TaskStatus::Reviewing && $task->isFinalReview() && $task->review_notified_attempt !== $task->review_attempt) {
                $scheduler->nudgeReviewer($task, null);

                continue;
            }
            $observation = $this->observer->observe($group, $task);

            if ($observation->available) {
                $this->clearUnavailable($group);
            }
            if (($observation->available && $observation->threads === []) || ($task->status === TaskStatus::Running && $observation->thread(TaskThreadRole::Implementer) === null)) {
                continue;
            }

            if ($observation->available && $task->status === TaskStatus::Running && (TaskExecutionHold::run($group, fn (): bool => $this->handleImplementerCompletion($scheduler, $group, $task, $observation)) ?? true)) {
                continue;
            }

            if ($observation->available && $task->status === TaskStatus::Reviewing && (TaskExecutionHold::run($group, fn (): bool => $scheduler->handleReviewerOutcome($group, $task, $observation)) ?? true)) {
                continue;
            }

            try {
                $decision = ! $observation->available
                    ? $this->unavailableDecision($group)
                    : $this->classifyAvailable($group, $observation);
            } catch (TaskSessionClassificationException $exception) {
                $decision = TaskSessionDecision::escalate($exception->getMessage());
            }

            if ($decision->action === TaskSessionNextAction::RequestAssistance) {
                if (! $observation->available) {
                    $decisions[] = $decision;

                    continue;
                }
                $scheduler->requestAssistance($task, $group, $decision->reason, $observation);
                $decisions[] = $decision;

                continue;
            }

            try {
                $this->actor->execute($group, $observation, $decision);
                $this->advance($scheduler, $group, $task, $decision, $observation);
                if ($task->communication_failures > 0) {
                    $task->update(['communication_failures' => 0]);
                }
            } catch (AgentDriverException $exception) {
                $decision = TaskSessionDecision::escalate($exception->getMessage());
                $task->increment('communication_failures');
                $task->refresh();
                if ($task->communication_failures >= 5) {
                    $scheduler->requestAssistance($task, $group, $exception->getMessage());
                }
            }

            $decisions[] = $decision;
        }
    }

    private function classifyAvailable(Task $group, TaskSessionObservation $observation): TaskSessionDecision
    {
        $this->clearUnavailable($group);

        return new TaskSessionDecision(TaskSessionNextAction::Noop, 1.0, 'Waiting for a known agent state.');
    }

    public function clearUnavailable(Task $group): void
    {
        $this->availability->clear($group);
    }

    public function unavailableDecision(Task $group): TaskSessionDecision
    {
        return $this->availability->unavailableDecision($group);
    }

    private function advance(TaskScheduler $scheduler, Task $group, Task $task, TaskSessionDecision $decision, TaskSessionObservation $observation): void
    {
        $group = $group->fresh(['tasks', 'project', 'taskable']) ?? $group;
        $current = $task->fresh();

        if ($decision->action === TaskSessionNextAction::MarkSubtaskDone && $current instanceof Task) {
            if ($current->status === TaskStatus::Running) {
                $scheduler->settleImplementer($current, $observation->thread(TaskThreadRole::Reviewer));
            } elseif ($current->status === TaskStatus::Reviewing) {
                $scheduler->acceptReview($current);
            }

            return;
        }

        if ($decision->action !== TaskSessionNextAction::SettleGroup) {
            return;
        }

        if ($current instanceof Task && $current->status === TaskStatus::Reviewing) {
            $scheduler->acceptReview($current);

            return;
        }

        if (in_array($group->status, TaskGroupStatus::awaitingCompletion(), true)) {
            $scheduler->settle($group);
        }
    }
}
