<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AgentThread;
use App\Models\Task;

/** What a check's admission reads of the group's implementers. Chooses no lifecycle action. */
final readonly class TaskCheckEvidence
{
    public function groupImplementerStarted(Task $group): bool
    {
        return Task::query()->where('parent_id', $group->id)->whereNotNull('implementer_agent_thread_id')->exists()
            || AgentThread::query()->where('task_group_id', $group->id)->where('role', TaskThreadRole::Implementer->value)->where('external_id', 'not like', AgentThread::PendingPrefix.'%')->exists();
    }
}
