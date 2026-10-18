<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Tasks\ResumeDeliverableCorrectionAction;
use App\Actions\Tasks\StoreTaskCommentAction;
use App\Domain\TaskVms\TaskVmSettings;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;
use App\Models\TaskQuestion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final readonly class TaskConsultation
{
    private const string PiRestartPending = 'pending';

    private const string PiRestartAccepted = 'accepted';

    private const string PiRestartSuperseded = 'superseded';

    public function __construct(
        private TaskSubtaskRunner $runner,
        private AgentSpawner $spawner,
        private CoderSettleNotifier $coder,
        private TaskSessionObserver $observer,
        private TaskSessionActor $actor,
        private TaskTurnReceipts $receipts,
        private TaskReviewPacketBuilder $reviewPackets,
        private TaskTurnFetcher $turnFetcher,
        private ResumeDeliverableCorrectionAction $correctionResume,
    ) {}

    /**
     * ADR 0200: a group whose own changes against origin/main touch a path in `task_vms.topology_paths`
     * needs a declared topology on some subtask of the group. Null when the rule does not apply.
     */
    public function topologyRequiredItem(Task $group, ?TaskDeliverableEvidence $evidence): ?TaskRubricItem
    {
        $patterns = app(TaskVmSettings::class)->topologyPaths[(int) $group->project_id] ?? [];
        if ($patterns === [] || ! TaskTopology::forGroup($group)->isEmpty()) {
            return null;
        }
        if (! $evidence instanceof TaskDeliverableEvidence || $evidence->topologyDiff === null) {
            return new TaskRubricItem('topology_required', false, "Orbit could not read the group's diff against origin/main to check the paths that need a declared topology.");
        }
        $paths = array_values(array_unique(array_map(
            static fn (array $entry): string => $entry['path'],
            array_filter($evidence->topologyDiff, static fn (array $entry): bool => array_any($patterns, static fn (string $pattern): bool => TaskDeliverableVerifier::matches($pattern, $entry['path']))),
        )));
        if ($paths === []) {
            return null;
        }

        return new TaskRubricItem('topology_required', false, 'This subtask changes paths that need real machines ('.implode(', ', array_slice($paths, 0, 10)).(count($paths) > 10 ? ', and '.(count($paths) - 10).' more' : '').'), but no subtask of the group declares a topology, so the change cannot be proven on test nodes. Revert those changes, or end your turn blocked so the operator can add a subtask that declares a topology.');
    }

    /**
     * ADR 0133: a receipt confirms every deliverable it must, as the turn command requires. A hand-written
     * receipt that misses one fails the `deliverables` item.
     */
    public function confirmationItem(Task $task, ?TaskComment $receipt, TaskThreadRole $role): ?TaskRubricItem
    {
        $deliverables = $task->deliverableList();
        if ($deliverables === [] || ! $receipt instanceof TaskComment) {
            return null;
        }
        $missing = TaskDeliverableVerifier::unconfirmed($deliverables, $receipt->deliverables ?? [], $role);

        return new TaskRubricItem('deliverables', $missing === [], $missing === [] ? '' : 'The turn receipt does not confirm the deliverables '.implode(', ', $missing).'. Pass --deliverable=ID=evidence for each one.');
    }

    public function receiptItem(?TaskTurnReceipt $read, ?TaskComment $receipt): TaskRubricItem
    {
        if ($receipt instanceof TaskComment) {
            return new TaskRubricItem('turn_receipt', true, '');
        }

        return new TaskRubricItem('turn_receipt', false, $read instanceof TaskTurnReceipt ? 'The turn receipt was not valid for this turn.' : 'No turn receipt was found.');
    }

    /**
     * Stores a receipt that fits the turn as a comment, then removes the file. A crash before the
     * removal reads the receipt again, and its hash matches the stored comment.
     *
     * @throws TaskTurnReceiptException
     */
    public function collectReceipt(Task $group, Task $task, TaskThreadRole $role): ?TaskTurnReceipt
    {
        return app(TaskExecutionLock::class)->synchronized($group->id, function () use ($group, $task, $role): ?TaskTurnReceipt {
            $group = $group->fresh(['taskable']) ?? $group;
            $task = $task->fresh() ?? $task;
            $instance = $group->taskable;
            if (! $instance instanceof Instance) {
                throw new TaskTurnReceiptException('The task workspace is unavailable.');
            }
            $actingThreadId = $this->actingThreadId($group, $task, $role);

            return app(TaskWorkspaceLease::class)->withReceipt($group, $task->id, $actingThreadId,
                fn (): ?TaskTurnReceipt => $this->collectAdmittedReceipt($group, $task, $role, $instance, $actingThreadId));
        }, wait: false);
    }

    private function collectAdmittedReceipt(Task $group, Task $task, TaskThreadRole $role, Instance $instance, ?int $actingThreadId): ?TaskTurnReceipt
    {
        $receipt = $this->receipts->read($instance, $actingThreadId);
        if (! $receipt instanceof TaskTurnReceipt || ! $this->receiptMatchesActingThread($receipt, $actingThreadId)) {
            return null;
        }
        if ($receipt->outcome instanceof TaskTurnOutcome && $receipt->fits($role)) {
            TaskComment::query()->firstOrCreate(['task_id' => $task->id, 'receipt_hash' => $receipt->hash], [
                'task_group_id' => $group->id,
                'agent_thread_id' => $role === TaskThreadRole::Implementer ? $task->implementer_agent_thread_id : $group->reviewer_agent_thread_id,
                'completion_attempt' => $task->completion_attempt,
                'review_attempt' => $role === TaskThreadRole::Reviewer ? $task->review_attempt : null,
                'type' => $receipt->outcome->commentType(),
                'body' => $receipt->body(),
                'pull_request' => $receipt->pullRequest?->toArray(),
                'deliverables' => $receipt->deliverables === [] ? null : $receipt->deliverables,
                'cause' => $receipt->cause,
                'author' => $role->value,
                'posted_at' => now(),
            ]);
        }
        $this->receipts->clear($instance, $receipt);

        return $receipt;
    }

    /** The Orbit id of the thread this phase acts as, or null when that thread has not been stored. */
    private function actingThreadId(Task $group, Task $task, TaskThreadRole $role): ?int
    {
        $id = $role === TaskThreadRole::Implementer ? $task->implementer_agent_thread_id : $group->reviewer_agent_thread_id;

        return is_numeric($id) ? (int) $id : null;
    }

    /** A receipt applies only when it names the acting thread. An unbound receipt does not. */
    private function receiptMatchesActingThread(TaskTurnReceipt $receipt, ?int $actingThreadId): bool
    {
        return $actingThreadId === null || $receipt->threadId === $actingThreadId;
    }

    /**
     * Returns the latest stored receipt of this attempt that the scheduler has not acted on.
     */
    public function pendingReceipt(Task $task, TaskThreadRole $role): ?TaskComment
    {
        $implementer = $role === TaskThreadRole::Implementer;
        $receipt = $task->comments()
            ->whereNotNull('receipt_hash')
            ->where('author', $role->value)
            ->where($implementer ? 'completion_attempt' : 'review_attempt', $implementer ? $task->completion_attempt : $task->review_attempt)
            ->latest('id')
            ->first();
        $handled = $implementer ? $task->completion_handoff_comment_id : $task->review_handled_comment_id;

        return $receipt instanceof TaskComment && $receipt->id !== $handled ? $receipt : null;
    }

    public function receiptOutcome(TaskComment $receipt): ?TaskTurnOutcome
    {
        $type = $receipt->getRawOriginal('type');

        return TaskTurnOutcome::tryFrom(is_string($type) ? $type : '');
    }

    public function waitingItem(TaskThreadObservation $thread): ?TaskRubricItem
    {
        $waiting = $thread->sessState === AgentThreadState::AskingForInput->value
            || $thread->pendingApprovalId !== null
            || $thread->pendingUserInputId !== null;
        if (! $waiting) {
            return null;
        }

        $work = $thread->role === TaskThreadRole::Implementer ? 'brief' : 'review';

        return new TaskRubricItem('waiting_for_input', false, 'The thread is waiting for input. Continue the current '.$work.' without it.');
    }

    public static function reminderKey(Task $task, TaskThreadRole $role): string
    {
        $attempt = $role === TaskThreadRole::Reviewer ? $task->review_attempt : $task->completion_attempt;

        return 'tasks.reminder:'.$task->id.':'.$role->value.':'.$attempt;
    }

    /**
     * Sends three failed-handoff reminders, or one ordinary reminder per attempt, then asks for assistance.
     *
     * @param  list<TaskRubricItem>  $items
     * @return bool whether the scheduler reminded the agent or asked for assistance
     */
    public function remindOrAssist(TaskScheduler $scheduler, Task $group, Task $task, TaskThreadObservation $thread, array $items, ?TaskCheck $failedCheck = null): bool
    {
        $failures = $this->failedItems($items);
        $implementer = $thread->role === TaskThreadRole::Implementer;
        $attempt = $implementer ? 'completion_attempt' : 'review_attempt';
        $reminder = $implementer ? 'completion_reminder_attempt' : 'review_reminder_attempt';
        $input = $implementer ? 'completion_reminder_input_id' : 'review_reminder_input_id';
        $pendingId = $thread->pendingUserInputId ?? $thread->pendingApprovalId;
        if ($failedCheck === null && $task->{$reminder} === $task->{$attempt} && $pendingId !== null && $pendingId === $task->{$input}) {
            return false;
        }
        $checkFailures = 0;
        if ($failedCheck !== null) {
            $lastPass = $task->checks()->where('kind', TaskCheckKind::Handoff)->where('status', TaskCheckStatus::Passed)->where('id', '<', $failedCheck->id)->max('id');
            $checkFailures = $task->checks()->where('kind', TaskCheckKind::Handoff)->where('status', TaskCheckStatus::Failed)
                ->where('id', '>', $lastPass ?? 0)->where('id', '<=', $failedCheck->id)->count();
        }
        if ($failedCheck !== null ? $checkFailures <= 3 : $task->{$reminder} !== $task->{$attempt}) {
            $key = $failedCheck === null ? self::reminderKey($task, $thread->role) : 'tasks.check-reminder:'.$failedCheck->id;
            try {
                if (! $this->runner->prepareTurn($scheduler, $group, $task, $thread->role, $thread->threadId, sendKey: $key)) {
                    return false;
                }
                $mode = TaskTurnMode::forTask($task, $thread->role);
                $this->actor->remindRubric($group, $thread, TaskRubricReminder::compose($thread->role, $failures, ! $implementer && $task->opensPullRequest(), $task->deliverableList(), $group->project->taskCheckCommand(), $thread->threadId, $mode), $key);
            } catch (AgentDriverException|TaskTurnReceiptException $exception) {
                $this->recordCommunicationFailure($task, $group, $exception->getMessage());

                return false;
            }
            $task->update($failedCheck === null
                ? [$reminder => $task->{$attempt}, $input => $pendingId, 'communication_failures' => 0]
                : ['communication_failures' => 0]);

            return true;
        }

        $reasons = array_map(static fn (TaskRubricItem $item): string => $item->reminder, $failures);
        $this->requestAssistance($task, $group, ($failedCheck === null ? 'Checks still failed after the reminder. ' : 'The handoff check failed '.$checkFailures.' times in a row. ').implode(' ', $reasons));

        return true;
    }

    /** @param list<TaskRubricItem> $items
     * @return list<TaskRubricItem>
     */
    public function failedItems(array $items): array
    {
        return array_values(array_filter($items, static fn (TaskRubricItem $item): bool => ! $item->passed));
    }

    public function clearCommunicationFailures(Task $task): void
    {
        if ($task->communication_failures > 0) {
            $task->update(['communication_failures' => 0]);
        }
    }

    public function recordCommunicationFailure(Task $task, Task $group, string $reason): void
    {
        $task->increment('communication_failures');
        $task->refresh();
        if ($task->communication_failures >= 5) {
            $this->requestAssistance($task, $group, $reason);
        }
    }

    /**
     * An implementer's blocked receipt asks the reviewer first. The third block in one attempt
     * asks for direction at once, with both earlier answers in the reason.
     */
    public function beginConsult(Task $group, Task $task, TaskComment $receipt, TaskSessionObservation $observation): void
    {
        if (TaskQuestions::consultCount($task) >= TaskQuestions::ConsultLimit) {
            $this->requestAssistance($task, $group, $this->thirdBlockReason($task, $receipt), $observation, AssistanceKind::Direction, TaskAssistance::questionFromBlockedReason($receipt->body), ['completion_handoff_comment_id' => $receipt->id], $receipt);

            return;
        }

        if (! $this->spawner instanceof TaskAgentSpawner) {
            $this->recordCommunicationFailure($task, $group, TaskScheduler::ReviewRequestFailedReason.' (AgentSpawner).');

            return;
        }
        $instance = $group->taskable;
        if (! $instance instanceof Instance) {
            $this->recordCommunicationFailure($task, $group, 'The task workspace is unavailable.');

            return;
        }

        $this->recordConsultIntent($task, $receipt, $observation);
        $task->refresh();
        $this->dispatchConsult($group, $task, $receipt, $observation);
    }

    /** Records the consult for this blocked receipt before the send, so a lost response can be recovered. */
    private function recordConsultIntent(Task $task, TaskComment $receipt, TaskSessionObservation $observation): void
    {
        $own = $this->ownReviewer($task, $observation);
        $source = $own instanceof TaskThreadObservation && is_string($own->turnId) && $own->turnId !== '' ? $own->turnId : null;
        $key = $this->consultSendKey($receipt);
        DB::transaction(function () use ($task, $receipt, $key, $source): void {
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            if ($locked->consult_comment_id !== null || $locked->assistance_requested) {
                return;
            }
            TaskQuestions::openConsult($locked, $receipt);
            $locked->update([
                'consult_comment_id' => $receipt->id,
                'completion_handoff_comment_id' => $receipt->id,
                'direction_answer_key' => $key,
                'direction_answer_source_turn_id' => $source,
                'communication_failures' => 0,
            ]);
        });
    }

    /** Sends the reserved consult once. An accepted key, or a later reviewer turn, is not sent again. */
    private function dispatchConsult(Task $group, Task $task, TaskComment $receipt, TaskSessionObservation $observation): void
    {
        $task->refresh();
        if (! $this->consultSendPending($task, $receipt)) {
            return;
        }
        $own = $this->ownReviewer($task, $observation);
        if ($own instanceof TaskThreadObservation && $this->relayAnswerAccepted($own, $task)) {
            $this->confirmConsultDispatch($group, $task, $own->threadId);

            return;
        }
        if ($own instanceof TaskThreadObservation && $this->isWorking($own)) {
            return;
        }
        $existing = $this->subtaskReviewer($task);
        if ($existing instanceof AgentThread && $own === null && $observation->available && $observation->threads === []) {
            return;
        }
        if (! $this->spawner instanceof TaskAgentSpawner) {
            $this->recordCommunicationFailure($task, $group, TaskScheduler::ReviewRequestFailedReason.' (AgentSpawner).');

            return;
        }
        $instance = $group->taskable;
        if (! $instance instanceof Instance) {
            $this->recordCommunicationFailure($task, $group, 'The task workspace is unavailable.');

            return;
        }

        try {
            $reserved = $this->spawner->reserveReviewer($task);
            $thread = AgentThread::query()->findOrFail($reserved);
            $threadId = app(TaskWorkspaceLease::class)->withThreadLock($thread, function () use ($group, $task, $instance, $reserved, $thread, $receipt): int {
                if (! app(TaskWorkspaceLease::class)->retrying($thread, $this->consultSendKey($receipt))) {
                    app(TaskWorkspaceLease::class)->turn($group, TaskThreadRole::Reviewer, 'thread:'.$reserved, $reserved);
                    $this->turnFetcher->beforeTurn($group);
                    $this->receipts->prepare($instance, TaskThreadRole::Reviewer, false, $task->deliverableList(), $reserved, new TaskTurnMode(consult: true), $this->reviewPackets->reviewContext($task));
                }
                $threadId = $this->spawner->consult($task, trim($receipt->body)."\n\n".TaskTurnInstructions::consult($reserved), $this->consultSendKey($receipt));
                if ($threadId === null) {
                    throw new AgentDriverException('The reviewer conversation could not be started.');
                }

                return $threadId;
            });
        } catch (TaskWorkspaceWaiting) {
            return;
        } catch (Throwable $exception) {
            report($exception);
            $this->recordCommunicationFailure($task, $group, TaskScheduler::ReviewRequestFailedReason.' ('.class_basename($exception).').');

            return;
        }
        $this->confirmConsultDispatch($group, $task, $threadId);
    }

    private function confirmConsultDispatch(Task $group, Task $task, ?int $threadId): void
    {
        DB::transaction(function () use ($group, $task, $threadId): void {
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            if ($locked->consult_comment_id === null) {
                return;
            }
            $locked->update([
                'direction_answer_key' => null,
                'direction_answer_source_turn_id' => null,
                'communication_failures' => 0,
            ]);
            if (is_int($threadId)) {
                Task::topLevel()->whereKey($group->id)->update(['reviewer_agent_thread_id' => $threadId]);
            }
        });
    }

    private function consultSendPending(Task $task, TaskComment $receipt): bool
    {
        return $task->consult_comment_id === $receipt->id && $task->direction_answer_key === $this->consultSendKey($receipt);
    }

    /** The same blocked receipt always reserves the same reviewer send. */
    private function consultSendKey(TaskComment $receipt): string
    {
        return $this->relayAnswerKey($receipt, 'consult');
    }

    private function blockedConsultReceipt(Task $task): ?TaskComment
    {
        $id = $task->consult_comment_id;
        if (! is_int($id)) {
            return null;
        }
        $receipt = TaskComment::query()->find($id);

        return $receipt instanceof TaskComment ? $receipt : null;
    }

    /** This subtask's reviewer, not an earlier subtask's thread that happens to be observed first. */
    private function ownReviewer(Task $task, TaskSessionObservation $observation): ?TaskThreadObservation
    {
        $existing = $this->subtaskReviewer($task);
        if (! $existing instanceof AgentThread) {
            return null;
        }
        foreach ($observation->threads as $thread) {
            if ($thread->role === TaskThreadRole::Reviewer && $thread->threadId === $existing->id) {
                return $thread;
            }
        }

        return null;
    }

    /** Reads the consult receipt. `answered` continues the implementer. `blocked` asks for direction. */
    public function handleConsult(TaskScheduler $scheduler, Task $group, Task $task): void
    {
        $observation = $this->observer->observe($group, $task);
        if (! $observation->available) {
            $decision = $scheduler->unavailableDecision($group);
            if ($decision->action === TaskSessionNextAction::EscalateCoder) {
                $this->requestAssistance($task, $group, $decision->reason, $observation, AssistanceKind::Failure);
            }

            return;
        }
        $scheduler->clearUnavailable($group);
        $blocked = $this->blockedConsultReceipt($task);
        if ($blocked instanceof TaskComment && $this->consultSendPending($task, $blocked)) {
            $this->dispatchConsult($group, $task, $blocked, $observation);
            $task->refresh();
            if ($this->consultSendPending($task, $blocked)) {
                return;
            }
            $observation = $this->observer->observe($group, $task);
        }
        $reviewer = $this->ownReviewer($task, $observation);
        if ($reviewer === null || $this->isWorking($reviewer)) {
            return;
        }
        $this->reconcilePiRestart($task, $reviewer);
        $state = AgentThreadState::tryFrom($reviewer->sessState);
        if ($state === AgentThreadState::Failed) {
            if (! $this->retryProviderFailure($task, $group, $reviewer) && $this->resumePiServerRestart($task, $group, $reviewer) !== 'handled') {
                $this->requestAssistance($task, $group, 'The reviewer thread failed.', $observation, AssistanceKind::Failure);
            }

            return;
        }
        if (! in_array($state, [AgentThreadState::Idle, AgentThreadState::Done, AgentThreadState::AskingForInput], true)) {
            return;
        }
        if (! $this->newerTurnHasStopped($task->review_notified_turn_id, $reviewer)) {
            return;
        }

        $receipt = $this->pendingReceipt($task, TaskThreadRole::Reviewer);
        $read = null;
        // Once the answer is durable, retry its implementer delivery under that actor's
        // lease. A new reviewer checkout read would wait behind the very send being retried.
        if (! $receipt instanceof TaskComment || $this->receiptOutcome($receipt) !== TaskTurnOutcome::Answered
            || ! $receipt->cause instanceof QuestionCause) {
            try {
                $read = $this->collectReceipt($group, $task, TaskThreadRole::Reviewer);
            } catch (TaskTurnReceiptException $exception) {
                $this->recordCommunicationFailure($task, $group, $exception->getMessage());

                return;
            }
            $receipt = $this->pendingReceipt($task, TaskThreadRole::Reviewer);
        }
        $outcome = $receipt instanceof TaskComment ? $this->receiptOutcome($receipt) : null;
        if (! $receipt instanceof TaskComment || ! in_array($outcome, [TaskTurnOutcome::Answered, TaskTurnOutcome::Blocked], true)) {
            $this->remindOrAssist($scheduler, $group, $task, $reviewer, [
                $receipt instanceof TaskComment
                    ? new TaskRubricItem('turn_receipt', false, 'A consult needs --outcome=answered or --outcome=blocked, with --cause.')
                    : $this->receiptItem($read, $receipt),
            ]);

            return;
        }
        if (! $receipt->cause instanceof QuestionCause) {
            $this->remindOrAssist($scheduler, $group, $task, $reviewer, [
                new TaskRubricItem('turn_receipt', false, 'A consult needs --cause with one of: brief_unclear, contract_gap, scope, environment, missed_contract.'),
            ]);

            return;
        }
        if ($outcome === TaskTurnOutcome::Blocked) {
            $this->requestAssistance($task, $group, TaskAssistance::ReviewerBlockedPrefix.$receipt->body, $observation, AssistanceKind::Direction, TaskAssistance::questionFromBlockedReason($receipt->body), ['review_handled_comment_id' => $receipt->id], $receipt);

            return;
        }
        if (! TaskQuestions::answerConsult($task, $receipt)) {
            return;
        }
        $this->deliverConsultAnswer($scheduler, $group, $task, $observation, $receipt);
    }

    /** Sends the reviewer's answer to the implementer and keeps the same attempt. */
    private function deliverConsultAnswer(TaskScheduler $scheduler, Task $group, Task $task, TaskSessionObservation $observation, TaskComment $receipt): void
    {
        $implementer = $observation->thread(TaskThreadRole::Implementer);
        if ($implementer === null || $this->isWorking($implementer)) {
            return;
        }
        $key = $this->relayAnswerKey($receipt, 'consult-answer');
        if ($task->direction_answer_key !== $key) {
            $sourceTurn = $implementer->turnId;
            $task->update([
                'direction_answer_key' => $key,
                'direction_answer_source_turn_id' => is_string($sourceTurn) && $sourceTurn !== '' ? $sourceTurn : null,
            ]);
            $task->refresh();
        }
        if ($this->relayAnswerAccepted($implementer, $task)) {
            $this->finishConsultDelivery($task, $receipt);

            return;
        }
        try {
            if (! $this->runner->prepareTurn($scheduler, $group, $task, TaskThreadRole::Implementer, $implementer->threadId, sendKey: $key)) {
                return;
            }
            $this->actor->relayAnswer($group, $implementer, $this->correctionResume->continuationMessage($task, $receipt->body), $key);
        } catch (TaskWorkspaceWaiting) {
            return;
        } catch (Throwable $exception) {
            report($exception);
            $this->recordCommunicationFailure($task, $group, 'The consult answer could not be sent to the implementer. ('.class_basename($exception).').');

            return;
        }
        $this->finishConsultDelivery($task, $receipt);
    }

    private function finishConsultDelivery(Task $task, TaskComment $receipt): void
    {
        DB::transaction(function () use ($task, $receipt): void {
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            if ($locked->consult_comment_id === null) {
                return;
            }
            $this->correctionResume->supersede($locked, $receipt);
            $locked->update([
                'consult_comment_id' => null,
                'direction_answer_key' => null,
                'direction_answer_source_turn_id' => null,
                'review_handled_comment_id' => $receipt->id,
                'communication_failures' => 0,
            ]);
        });
    }

    /** The third block in one attempt asks the operator, and the reason keeps both earlier answers. */
    private function thirdBlockReason(Task $task, TaskComment $receipt): string
    {
        $lines = array_map(
            static fn (string $answer): string => '- '.($answer !== '' ? $answer : '(no answer)'),
            TaskQuestions::earlierAnswers($task),
        );

        return TaskAssistance::ImplementerBlockedPrefix.$receipt->body."\n\nEarlier answers:\n".implode("\n", $lines);
    }

    /** A direction resolution waits for a reviewer or for workspace admission. */
    public function pendingRelayResolution(Task $task): ?TaskComment
    {
        if ($task->assistance_kind !== AssistanceKind::Direction || $task->direction_relay_comment_id !== null) {
            return null;
        }
        $question = TaskQuestion::query()
            ->where('subtask_id', $task->id)
            ->where('status', QuestionStatus::Escalated)
            ->latest('id')
            ->first();
        if (! $question instanceof TaskQuestion) {
            return null;
        }
        if (is_int($question->resolution_comment_id)) {
            $held = TaskComment::query()->find($question->resolution_comment_id);
            $rawType = $held?->getRawOriginal('type');

            return $held instanceof TaskComment && $rawType === TaskCommentType::Resolution->value ? $held : null;
        }

        $openedCommentId = $question->opened_comment_id;
        $resolution = TaskComment::query()
            ->where('task_id', $task->id)
            ->where('type', TaskCommentType::Resolution)
            ->when(is_int($openedCommentId), static fn ($query) => $query->where('id', '>', $openedCommentId))
            ->latest('id')
            ->first();

        return $resolution instanceof TaskComment ? $resolution : null;
    }

    /** Starts the reviewer and sends the operator's resolution as a relay, not as a review packet. */
    public function beginDirectionRelay(Task $group, Task $task, TaskComment $resolution): void
    {
        $instance = $group->taskable;
        if (! $instance instanceof Instance || ! $this->spawner instanceof TaskAgentSpawner) {
            return;
        }

        try {
            $reserved = $this->spawner->reserveReviewer($task);
            $thread = AgentThread::query()->findOrFail($reserved);
            $threadId = app(TaskWorkspaceLease::class)->withThreadLock($thread, function () use ($group, $task, $instance, $reserved, $resolution): int {
                app(TaskWorkspaceLease::class)->turn($group, TaskThreadRole::Reviewer, 'thread:'.$reserved, $reserved);
                $this->turnFetcher->beforeTurn($group);
                $this->receipts->prepare($instance, TaskThreadRole::Reviewer, $task->opensPullRequest(), $task->deliverableList(), $reserved, new TaskTurnMode(relay: true), $this->reviewPackets->reviewContext($task));
                $threadId = $this->spawner->relay($task, trim($resolution->body)."\n\n".TaskTurnInstructions::relay($reserved));
                if ($threadId === null) {
                    throw new AgentDriverException('The reviewer conversation could not be started.');
                }

                return $threadId;
            });
        } catch (TaskWorkspaceWaiting) {
            return;
        } catch (Throwable $exception) {
            report($exception);
            $this->recordCommunicationFailure($task, $group, TaskScheduler::ReviewRequestFailedReason.' ('.class_basename($exception).').');

            return;
        }

        DB::transaction(function () use ($task, $resolution, $threadId): void {
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            $parent = $locked->parent()->lockForUpdate()->firstOrFail();
            if (! TaskAssistance::sameRequest($locked, $task) || $locked->assistance_kind !== AssistanceKind::Direction) {
                return;
            }
            TaskAssistance::clearResolvedParent($locked, $parent);
            $locked->update([
                ...TaskAssistance::cleared(),
                'communication_failures' => 0,
                'direction_relay_comment_id' => $resolution->id,
                'resolution_delivered_comment_id' => $resolution->id,
            ]);
            $parent->update(['reviewer_agent_thread_id' => $threadId]);
            TaskQuestions::attachResolution($locked, $resolution);
        });
    }

    /** Reads the relay receipt. `answered` records the operator's answer. `blocked` keeps the same question. */
    public function handleDirectionRelay(TaskScheduler $scheduler, Task $group, Task $task): void
    {
        $observation = $this->observer->observe($group, $task);
        $reviewer = $observation->thread(TaskThreadRole::Reviewer);
        if (! $observation->available || $reviewer === null || $this->isWorking($reviewer)) {
            return;
        }
        if (! $this->newerTurnHasStopped($task->review_notified_turn_id, $reviewer)) {
            return;
        }

        $receipt = $this->pendingReceipt($task, TaskThreadRole::Reviewer);
        $read = null;
        // Once the answer is durable, retry its implementer delivery under that actor's
        // lease. A new reviewer checkout read would wait behind the very send being retried.
        if (! $receipt instanceof TaskComment || $this->receiptOutcome($receipt) !== TaskTurnOutcome::Answered
            || ! $receipt->cause instanceof QuestionCause) {
            try {
                $read = $this->collectReceipt($group, $task, TaskThreadRole::Reviewer);
            } catch (TaskTurnReceiptException $exception) {
                $this->recordCommunicationFailure($task, $group, $exception->getMessage());

                return;
            }
            $receipt = $this->pendingReceipt($task, TaskThreadRole::Reviewer);
        }
        $outcome = $receipt instanceof TaskComment ? $this->receiptOutcome($receipt) : null;
        if (! $receipt instanceof TaskComment || ! in_array($outcome, [TaskTurnOutcome::Answered, TaskTurnOutcome::Blocked], true)) {
            if ($read instanceof TaskTurnReceipt || $receipt instanceof TaskComment) {
                $this->remindOrAssist($scheduler, $group, $task, $reviewer, [
                    new TaskRubricItem('turn_receipt', false, 'A relay needs --outcome=answered or --outcome=blocked, with --cause.'),
                ]);
            }

            return;
        }
        if (! $receipt->cause instanceof QuestionCause) {
            $this->remindOrAssist($scheduler, $group, $task, $reviewer, [
                new TaskRubricItem('turn_receipt', false, 'A relay needs --cause with one of: brief_unclear, contract_gap, scope, environment, missed_contract.'),
            ]);

            return;
        }
        if ($outcome === TaskTurnOutcome::Blocked) {
            $this->requestAssistance($task, $group, TaskAssistance::ReviewerBlockedPrefix.$receipt->body, $observation, AssistanceKind::Direction, TaskAssistance::questionFromBlockedReason($receipt->body), ['review_handled_comment_id' => $receipt->id], $receipt);

            return;
        }

        // The answer is durable immediately. The relay stays until the implementer has the summary.
        // A receipt-keyed reservation is reused, and an accepted or superseded turn is not sent again.
        TaskQuestions::answerPending($task, $receipt);
        $implementer = $observation->thread(TaskThreadRole::Implementer);
        if ($implementer === null || $this->isWorking($implementer)) {
            return;
        }
        $key = $this->relayAnswerKey($receipt);
        if ($task->direction_answer_key !== $key) {
            $sourceTurn = $implementer->turnId;
            $task->update([
                'direction_answer_key' => $key,
                'direction_answer_source_turn_id' => is_string($sourceTurn) && $sourceTurn !== '' ? $sourceTurn : null,
            ]);
            $task->refresh();
        }
        if ($this->relayAnswerAccepted($implementer, $task)) {
            $this->finishRelayDelivery($task, $receipt);

            return;
        }
        try {
            if (! $this->runner->prepareTurn($scheduler, $group, $task, TaskThreadRole::Implementer, $implementer->threadId, sendKey: $key)) {
                return;
            }
            $this->actor->relayAnswer($group, $implementer, $this->correctionResume->continuationMessage($task, $receipt->body), $key);
        } catch (TaskWorkspaceWaiting) {
            return;
        } catch (Throwable $exception) {
            // Keep this receipt's key. An uncertain response may still have been accepted, and a
            // rejected T3 command id stays rejected, so the same id is not replaced on this failure.
            report($exception);
            $this->recordCommunicationFailure($task, $group, 'The relay answer could not be sent to the implementer. ('.class_basename($exception).').');

            return;
        }
        $this->finishRelayDelivery($task, $receipt);
    }

    /** The same reviewer receipt always reserves the same implementer send. */
    private function relayAnswerKey(TaskComment $receipt, string $prefix = 'relay-answer'): string
    {
        $hash = md5($prefix.':'.$receipt->id);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 12, 4),
            substr($hash, 16, 4),
            substr($hash, 20, 12),
        );
    }

    /** An accepted key, its message, or a later implementer turn means the send already landed. */
    private function relayAnswerAccepted(TaskThreadObservation $implementer, Task $task): bool
    {
        $key = $task->direction_answer_key;
        if (! is_string($key) || $key === '') {
            return false;
        }
        $turnId = $implementer->turnId;
        if (is_string($turnId) && $turnId !== '' && $turnId === $key) {
            return true;
        }
        foreach ($implementer->recentMessages as $message) {
            if ($message['id'] === $key) {
                return true;
            }
        }
        $source = $task->direction_answer_source_turn_id;

        return is_string($turnId) && $turnId !== ''
            && is_string($source) && $source !== ''
            && $turnId !== $source;
    }

    private function finishRelayDelivery(Task $task, TaskComment $receipt): void
    {
        DB::transaction(function () use ($task, $receipt): void {
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            if ($locked->direction_relay_comment_id === null) {
                return;
            }
            $this->correctionResume->supersede($locked, $receipt);
            $locked->update([
                'direction_relay_comment_id' => null,
                'direction_answer_key' => null,
                'direction_answer_source_turn_id' => null,
                'review_handled_comment_id' => $receipt->id,
                'communication_failures' => 0,
            ]);
        });
    }

    /** Finishes a reviewing direction hold from the resolution comment already stored. */
    public function replayHeldDirectionReview(Task $task): void
    {
        $opened = TaskQuestion::query()
            ->where('subtask_id', $task->id)
            ->where('status', QuestionStatus::Escalated)
            ->latest('id')
            ->value('opened_comment_id');
        $resolution = TaskComment::query()
            ->where('task_id', $task->id)
            ->where('type', TaskCommentType::Resolution)
            ->when(is_int($opened), fn ($query) => $query->where('id', '>', $opened))
            ->latest('id')
            ->first();
        if (! $resolution instanceof TaskComment) {
            return;
        }
        app(StoreTaskCommentAction::class)->commitHeldDirectionReview($task, $resolution);
    }

    /**
     * @param  array<string, int>  $handled
     */
    public function requestAssistance(Task $task, Task $group, string $reason, ?TaskSessionObservation $observation = null, AssistanceKind $kind = AssistanceKind::Failure, ?string $question = null, array $handled = [], ?TaskComment $source = null): void
    {
        $notifyReason = null;
        DB::transaction(function () use ($task, $group, $reason, $kind, $question, $handled, $source, &$notifyReason): void {
            if ($handled !== []) {
                $task->update($handled);
            }
            $wasAsking = (bool) DB::table('tasks')->where('id', $group->id)->value('assistance_requested');
            TaskAssistance::apply($task, $kind, $question, $reason);
            $task->refresh();
            $taskKind = $task->assistance_kind;
            $taskReason = $task->assistance_reason;
            if ($task->assistance_requested && $taskKind instanceof AssistanceKind && is_string($taskReason)) {
                TaskAssistance::apply($group, $taskKind, $task->assistance_question, $taskReason);
            } else {
                TaskAssistance::apply($group, $kind, $question, $reason);
            }
            $group->refresh();
            if ($kind === AssistanceKind::Direction && $source instanceof TaskComment) {
                if (TaskQuestions::escalateOpen($task, $source)) {
                    $task->update(['consult_comment_id' => null]);
                } else {
                    TaskQuestions::recordBlocked($task, $source);
                }
            }
            $groupReason = $group->assistance_reason;
            if (! $wasAsking && $group->assistance_requested && is_string($groupReason)) {
                $notifyReason = $groupReason;
            }
        });
        if (is_string($notifyReason)) {
            $this->coder->assistance($group, $notifyReason);
        }
    }

    /**
     * Clears successful provider retries and reconciles pending Pi restart resumes from the acting thread (ADR 0167).
     * An accepted key is not sent again. A different turn does not reuse it either.
     */
    public function reconcilePiRestart(Task $task, TaskThreadObservation $acting): void
    {
        $retry = $task->provider_retry;
        if ($retry !== null && $retry['thread_id'] === $acting->threadId) {
            if ($acting->sessState === AgentThreadState::Done->value || $acting->sessState === AgentThreadState::Idle->value) {
                $task->update(['provider_retry' => null]);
            } elseif (is_string($acting->turnId) && $acting->turnId !== '' && $acting->turnId === $task->pi_restart_key) {
                // A server restart during a provider retry must not buy a fresh budget.
                $task->update(['provider_retry' => [...$retry, 'key' => $acting->turnId]]);
            }
        }
        if ($task->pi_restart_reservation !== self::PiRestartPending || (int) $task->pi_restart_thread_id !== $acting->threadId) {
            return;
        }
        $turnId = $acting->turnId;
        if (! is_string($turnId) || $turnId === '') {
            return;
        }
        if ($turnId === $task->pi_restart_key) {
            $task->update(['pi_restart_reservation' => self::PiRestartAccepted]);

            return;
        }
        if ($turnId !== $task->pi_restart_source_turn_id) {
            $task->update(['pi_restart_reservation' => self::PiRestartSuperseded]);
        }
    }

    /** Reserves a delayed, idempotent continuation of the same work before asking for assistance. */
    public function retryProviderFailure(Task $task, Task $group, TaskThreadObservation $acting): bool
    {
        if (! in_array($acting->providerError, ['overloaded', 'rate_limited', 'server_error'], true)
            || ! is_string($acting->turnId) || $acting->turnId === ''
            || AgentThread::query()->find($acting->threadId)?->driver !== 'pi') {
            return false;
        }

        $retry = $task->provider_retry;
        if ($retry !== null && ($retry['thread_id'] !== $acting->threadId
            || ! in_array($acting->turnId, [$retry['source_turn_id'], $retry['key']], true))) {
            $retry = null;
        }
        if ($retry === null || $acting->turnId !== $retry['source_turn_id']) {
            $history = $retry['history'] ?? [];
            $attempt = count($history) + 1;
            if ($attempt > 4) {
                $this->requestAssistance($task, $group, "Provider retries exhausted after 4 retries.\n"
                    .implode("\n", $history)."\nFinal failure: turn {$acting->turnId}, {$acting->providerError}.");

                return true;
            }
            $due = now()->addMinutes(2 ** ($attempt - 1))->toIso8601String();
            $entry = "Provider retry {$attempt}/4: {$acting->role->value} turn {$acting->turnId}, {$acting->providerError}; due {$due}.";
            $retry = [
                'thread_id' => $acting->threadId, 'source_turn_id' => $acting->turnId,
                'key' => (string) Str::uuid(), 'due_at' => $due, 'history' => [...$history, $entry],
            ];
            DB::transaction(function () use ($task, $group, $acting, $retry, $entry): void {
                $task->update(['provider_retry' => $retry]);
                TaskComment::query()->create([
                    'task_group_id' => $group->id, 'task_id' => $task->id, 'agent_thread_id' => $acting->threadId,
                    'type' => TaskCommentType::ProviderRetry, 'body' => $entry, 'author' => 'orbit', 'posted_at' => now(),
                ]);
            });
        }
        if (now()->lt(Carbon::parse($retry['due_at']))) {
            return true;
        }
        $thread = AgentThread::query()->findOrFail($acting->threadId);
        app(TaskWorkspaceLease::class)->withThreadLock($thread, function () use ($group, $acting, $thread, $retry): void {
            if (! app(TaskWorkspaceLease::class)->retrying($thread, $retry['key'])) {
                app(TaskWorkspaceLease::class)->turn($group, $acting->role, 'thread:'.$acting->threadId, $acting->threadId);
                $this->turnFetcher->beforeTurn($group);
            }
        });
        try {
            $this->actor->resumeInterruptedTurn($group, $acting,
                'Your previous turn failed because of a transient provider error. Continue the same assignment from the existing workspace. Check git status and git diff before continuing, and hand off with the turn command.',
                $retry['key']);
        } catch (AgentDriverException $exception) {
            $this->recordCommunicationFailure($task, $group, $exception->getMessage());

            return true;
        }
        $this->clearCommunicationFailures($task);

        return true;
    }

    /**
     * Resumes a Pi turn that failed only because the server restarted (ADR 0167).
     *
     * @return 'handled'|'assist'|'skip' handled owns the tick, assist asks for assistance, skip keeps today's failure path
     */
    public function resumePiServerRestart(Task $task, Task $group, TaskThreadObservation $acting): string
    {
        $record = AgentThread::query()->find($acting->threadId);
        if (! $record instanceof AgentThread || ! $this->isServerRestartError($record->driver, $acting->error)) {
            return 'skip';
        }
        if (! is_string($acting->turnId) || $acting->turnId === '') {
            return 'skip';
        }
        if ($task->pi_restart_reservation === self::PiRestartPending
            && (int) $task->pi_restart_thread_id === $acting->threadId
            && $acting->turnId === $task->pi_restart_source_turn_id
            && is_string($task->pi_restart_key)
            && $task->pi_restart_key !== '') {
            $this->sendPiRestartResume($task, $group, $acting, $task->pi_restart_key);

            return 'handled';
        }
        if ((int) $task->pi_restart_resumes >= TaskScheduler::PiServerRestartResumeLimit) {
            return 'assist';
        }
        $key = (string) Str::uuid();
        $task->update([
            'pi_restart_resumes' => (int) $task->pi_restart_resumes + 1,
            'pi_restart_key' => $key,
            'pi_restart_thread_id' => $acting->threadId,
            'pi_restart_source_turn_id' => $acting->turnId,
            'pi_restart_reservation' => self::PiRestartPending,
        ]);
        $this->sendPiRestartResume($task, $group, $acting, $key);

        return 'handled';
    }

    /** Only Pi restart failures can resume a task-agent turn. */
    private function isServerRestartError(string $driver, ?string $error): bool
    {
        return $driver === 'pi' && $error === TaskScheduler::PiServerRestartError;
    }

    private function sendPiRestartResume(Task $task, Task $group, TaskThreadObservation $acting, string $key): void
    {
        $thread = AgentThread::query()->findOrFail($acting->threadId);
        app(TaskWorkspaceLease::class)->withThreadLock($thread, function () use ($group, $acting, $thread, $key): void {
            if (! app(TaskWorkspaceLease::class)->retrying($thread, $key)) {
                app(TaskWorkspaceLease::class)->turn($group, $acting->role, 'thread:'.$acting->threadId, $acting->threadId);
                $this->turnFetcher->beforeTurn($group);
            }
        });
        try {
            $this->actor->resumeInterruptedTurn($group, $acting, TaskScheduler::PiServerRestartContinue, $key);
        } catch (AgentDriverException $exception) {
            $this->recordCommunicationFailure($task, $group, $exception->getMessage());

            return;
        }
        $this->clearCommunicationFailures($task);
    }

    /** The reviewer thread for this subtask, preferring the one the group currently points at. */
    public function subtaskReviewer(Task $task): ?AgentThread
    {
        $query = AgentThread::query()
            ->where('task_group_id', $task->parent_id)
            ->where('task_id', $task->id)
            ->where('role', TaskThreadRole::Reviewer->value)
            ->where('external_id', 'not like', TaskAgentSpawner::PendingPrefix.'%');
        $pointed = Task::topLevel()->whereKey($task->parent_id)->value('reviewer_agent_thread_id');
        if (is_numeric($pointed)) {
            $match = (clone $query)->whereKey((int) $pointed)->first();
            if ($match instanceof AgentThread) {
                return $match;
            }
        }

        return $query->orderByDesc('id')->first();
    }

    /**
     * A working thread never receives a turn; the scheduler sends it on a later tick.
     */
    public function isWorking(?TaskThreadObservation $thread): bool
    {
        return $thread?->sessState === AgentThreadState::Working->value;
    }

    public function newerTurnHasStopped(?string $previousTurnId, TaskThreadObservation $thread): bool
    {
        return is_string($thread->turnId) && $thread->turnId !== ''
            && $thread->turnId !== $previousTurnId
            && in_array($thread->sessState, [AgentThreadState::Done->value, AgentThreadState::AskingForInput->value], true);
    }

    public function failSpawn(?Task $group, ?Task $task, string $agent): void
    {
        if ($task instanceof Task) {
            $task->status = TaskStatus::Failed;
            $task->save();
        }

        if ($group instanceof Task) {
            $group->status = TaskGroupStatus::Failed;
            $group->save();
        }

        Log::error('A task group agent spawn returned no thread id.', [
            'task_group_id' => $group?->id,
            'task_id' => $task?->id,
            'agent' => $agent,
        ]);
    }
}
