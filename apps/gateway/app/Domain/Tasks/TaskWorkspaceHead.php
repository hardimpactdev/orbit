<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AgentThread;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;

/** Admission policy for Orbit's mechanical workspace head changes, under the group execution lock. */
final readonly class TaskWorkspaceHead
{
    public function __construct(private AgentThreadObserver $threads) {}

    public function held(Task $group, ?TaskComment $settledApproval = null): bool
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
        if (TaskCheck::query()->whereHas('task', static fn ($query) => $query->where('parent_id', $group->id))
            ->where('kind', TaskCheckKind::Handoff)->where('status', TaskCheckStatus::Running)->exists()) {
            return true;
        }
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
            if ($state === AgentThreadState::Working) {
                return true;
            }
            if ($state === null && ($current[$thread->task_id ?? 0] ?? null) === $thread->id) {
                throw new TaskCheckException('Orbit cannot verify whether reviewer thread #'.$thread->id.' has stopped: its observation is unavailable. Restore access to its agent runtime or retry the review to replace the conversation before changing workspace HEAD.');
            }
        }

        return false;
    }
}
