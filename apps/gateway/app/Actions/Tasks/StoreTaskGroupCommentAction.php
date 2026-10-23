<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\StoreTaskGroupCommentData;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskCommentType;
use App\Domain\Tasks\TaskOperations;
use App\Domain\Tasks\TaskStatus;
use App\Models\Task;
use App\Models\TaskComment;
use Illuminate\Support\Facades\DB;

final readonly class StoreTaskGroupCommentAction
{
    public function __construct(private RequireTasksExtensionAction $extension, private TaskOperations $operations) {}

    public function execute(Task $group, StoreTaskGroupCommentData $input, ?string $requestId = null): TaskComment
    {
        $this->extension->execute();

        return DB::transaction(function () use ($group, $input, $requestId): TaskComment {
            $group = Task::topLevel()->whereKey($group->id)->lockForUpdate()->firstOrFail();
            $this->operations->requireOpenExternal($group);
            if ($group->tasks()->whereNotIn('status', [TaskStatus::Completed, TaskStatus::Failed, TaskStatus::Cancelled])->exists()) {
                throw new ResourceOperationException('tasks.assistance_subtask_open', 'Group assistance comments require all subtasks to have ended.', 409);
            }
            $comment = $group->comments()->create([
                'task_group_id' => $group->id,
                'type' => $input->type,
                'body' => $input->body,
                'author' => $input->author,
                'request_id' => $requestId,
                'posted_at' => now(),
            ]);
            if ($input->type === TaskCommentType::AssistanceRequested) {
                TaskAssistance::apply($group, AssistanceKind::Direction, $comment->body, $comment->body, replaceDirection: true, source: $comment);
            } elseif ($input->type === TaskCommentType::Resolution && $group->assistance_kind === AssistanceKind::Direction) {
                TaskAssistance::apply($group, source: $comment);
            }

            return $comment;
        });
    }
}
