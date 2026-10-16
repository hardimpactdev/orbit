<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AgentThread;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;

/** Workspace ownership policy. Admission and process registration require the group execution lock. */
final readonly class TaskWorkspaceHead
{
    public function __construct(private AgentThreadObserver $threads) {}

    public function held(Task $group, ?TaskComment $settledApproval = null, ?int $ownCheck = null): bool
    {
        // The approval caller has validated this receipt on the locked scheduler turn.
        // The subtask stays Reviewing until commit/publication finishes, but its review has settled.
        $settledTask = $settledApproval?->getRawOriginal('type') === TaskCommentType::Approved->value
            && $settledApproval->author === TaskThreadRole::Reviewer->value
                ? $settledApproval->task_id : null;
        if ($group->tasks()->where('status', TaskStatus::Reviewing)
            ->when($settledTask !== null, static fn ($query) => $query->where('id', '!=', $settledTask))->exists()) {
            return true;
        }

        return $this->checksRunning($group, $ownCheck) || $this->reviewerWorking($group);
    }

    /** Detached processes retain workspace ownership until their result is recorded. */
    public function checksRunning(Task $group, ?int $except = null): bool
    {
        return TaskCheck::query()->whereHas('task', static fn ($query) => $query->where('parent_id', $group->id))
            ->where('status', TaskCheckStatus::Running)
            ->when($except !== null, static fn ($query) => $query->whereKeyNot($except))->exists();
    }

    private function reviewerWorking(Task $group): bool
    {
        $reviewers = AgentThread::query()->where('task_group_id', $group->id)
            ->where('role', TaskThreadRole::Reviewer->value)
            ->where('external_id', 'not like', TaskAgentSpawner::PendingPrefix.'%')->orderBy('id')->get();
        // Match reviewer selection in the spawner: the group's pointer wins for its
        // subtask, otherwise use that subtask's latest started conversation.
        $current = [];
        $pointed = Task::topLevel()->whereKey($group->id)->value('reviewer_agent_thread_id');
        foreach ($reviewers as $thread) {
            $current[$thread->task_id ?? 0] = $thread->id;
        }
        $preferred = is_numeric($pointed) ? $reviewers->find((int) $pointed) : null;
        if ($preferred instanceof AgentThread) {
            $current[$preferred->task_id ?? 0] = $preferred->id;
            $current[0] = $preferred->id; // Also supersedes the legacy shared reviewer.
        }
        foreach ($reviewers as $thread) {
            $state = $this->threads->observe($thread)?->state;
            if (in_array($state, [AgentThreadState::Working, AgentThreadState::AskingForInput], true)) {
                return true;
            }
            if ($state === null && ($current[$thread->task_id ?? 0] ?? null) === $thread->id) {
                throw new TaskCheckException('Orbit cannot verify whether reviewer thread #'.$thread->id.' has stopped: its observation is unavailable. Restore access to its agent runtime or retry the review to replace the conversation before changing workspace HEAD.');
            }
        }

        return false;
    }
}
