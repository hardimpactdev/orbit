<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AgentThread;
use App\Models\Task;

final readonly class TaskSessionActor
{
    public function __construct(
        private AgentDriverRegistry $drivers,
        private TaskTurnFetchNotice $fetchNotice = new TaskTurnFetchNotice,
    ) {}

    public function execute(Task $group, TaskSessionObservation $observation, TaskSessionDecision $decision): void
    {
        TaskGroupGuard::requireTick($group);
        TaskExecutionHold::run($group, fn () => $this->executeAdmitted($group, $observation, $decision));
    }

    private function executeAdmitted(Task $group, TaskSessionObservation $observation, TaskSessionDecision $decision): void
    {
        if ($decision->action === TaskSessionNextAction::RequestAssistance) {
            TaskAssistance::apply($group, AssistanceKind::Failure, null, $decision->reason);

            return;
        }
        if (in_array($decision->action, [TaskSessionNextAction::Noop, TaskSessionNextAction::MarkSubtaskDone, TaskSessionNextAction::SettleGroup], true)) {
            return;
        }
        if (in_array($decision->action, [TaskSessionNextAction::DrainApproval, TaskSessionNextAction::DrainUserInput], true)) {
            $responded = false;
            foreach ($observation->threads as $observed) {
                $kind = $decision->action === TaskSessionNextAction::DrainApproval ? 'approval' : 'question';
                foreach ($observed->inputRequests as $request) {
                    if ($request->kind !== $kind) {
                        continue;
                    }
                    $thread = $this->thread($group, $observed);
                    $answers = $kind === 'approval' ? ['approve' => true] : [
                        'continue' => true, 'expand_scope' => false, 'note' => 'Continue the current brief. Do not expand scope.',
                    ];
                    $this->drivers->get($thread->driver)->respond($thread, $request, $answers);
                    $responded = true;
                }
            }
            if (! $responded) {
                throw new AgentDriverException('No matching agent input request is available.');
            }

            return;
        }
        $implementer = $observation->thread(TaskThreadRole::Implementer);
        if ($implementer === null) {
            throw new AgentDriverException('Implementer observation is unavailable.');
        }
        $thread = $this->thread($group, $implementer);
        if ($implementer->sessState === AgentThreadState::Working->value || $implementer->sessState === AgentThreadState::AskingForInput->value) {
            throw new AgentDriverException('The implementer cannot start a follow-up turn in this state.');
        }
        $this->drivers->get($thread->driver)->send($thread, 'Continue the current brief. Do not expand scope.');
    }

    public function relayAnswer(Task $group, TaskThreadObservation $observed, string $summary, ?string $key = null): void
    {
        TaskGroupGuard::requireTick($group);
        $thread = $this->thread($group, $observed);
        $this->drivers->get($thread->driver)->send($thread, $summary."\n\n".TaskTurnInstructions::implementer(check: $group->project->taskCheckCommand(), threadId: $thread->id), $key);
    }

    public function relayReviewBody(Task $group, TaskThreadObservation $observed, string $body, ?string $key = null): void
    {
        TaskGroupGuard::requireTick($group);
        $thread = $this->thread($group, $observed);
        TaskExecutionHold::run($group, fn () => $this->drivers->get($thread->driver)->send($thread, $this->followupMessage($thread, 'Relay from the reviewer. Address these findings verbatim. '.TaskTurnInstructions::implementer(check: $group->project->taskCheckCommand(), threadId: $thread->id)."\n\n".$body, $key), $key));
    }

    public function remindRubric(Task $group, TaskThreadObservation $observed, string $message, ?string $key = null): void
    {
        TaskGroupGuard::requireTick($group);
        TaskExecutionHold::run($group, function () use ($group, $observed, $message, $key): void {
            $thread = $this->thread($group, $observed);
            $this->drivers->get($thread->driver)->send($thread, $this->followupMessage($thread, $message, $key), $key);
        });
    }

    /**
     * Continues the same thread after a server restart or resource request. The caller reserves a stable
     * key before this call. Pi posts it as the send key (ADR 0167).
     */
    public function resumeInterruptedTurn(Task $group, TaskThreadObservation $observed, string $message, string $key): void
    {
        TaskGroupGuard::requireTick($group);
        TaskExecutionHold::run($group, function () use ($group, $observed, $message, $key): void {
            $thread = $this->thread($group, $observed);
            $this->drivers->get($thread->driver)->send($thread, $this->followupMessage($thread, $message, $key), $key);
        });
    }

    private function followupMessage(AgentThread $thread, string $message, ?string $key): string
    {
        $pending = $key === null ? null : app(TaskWorkspaceLease::class)->pendingSend($thread, $key, includeCompleted: true);

        return $pending['message'] ?? $this->fetchNotice->apply($message);
    }

    private function thread(Task $group, TaskThreadObservation $observed): AgentThread
    {
        if (! $observed->available) {
            throw new AgentDriverException('Agent observation unavailable.');
        }
        $thread = AgentThread::query()->where('task_group_id', $group->id)->where('id', $observed->threadId)->first();

        return $thread ?? throw new AgentDriverException('Agent conversation is unavailable.');
    }
}
