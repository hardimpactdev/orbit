<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskCommentType;
use App\Domain\Tasks\TaskExecutionLock;
use App\Domain\Tasks\TaskQuestions;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskComment;
use Illuminate\Support\Facades\DB;

final readonly class StoreTaskCommentAction
{
    public function __construct(private TaskExecutionLock $execution) {}

    /** @param array<string, mixed> $payload */
    public function execute(Task $task, array $payload, ?Node $actor = null, ?string $requestId = null): TaskComment
    {
        return $this->execution->synchronized($task->requireGroupId(), fn (): TaskComment => DB::transaction(static function () use ($task, $payload, $requestId): TaskComment {
            $group = Task::topLevel()->lockForUpdate()->findOrFail($task->parent_id);
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);
            $comment = TaskComment::query()->create([
                ...$payload,
                'request_id' => $requestId,
                'task_group_id' => $group->id,
                'task_id' => $task->id,
                'completion_attempt' => $task->completion_attempt,
                'posted_at' => now(),
            ]);
            if ($comment->type === TaskCommentType::Resolution && trim($comment->body) !== '') {
                TaskAssistance::resolveExternalSubtask($task, $group, $comment);
            } elseif ($comment->type === TaskCommentType::AssistanceRequested) {
                TaskAssistance::apply($task, AssistanceKind::Direction, $comment->body, $comment->body, replaceDirection: true, source: $comment);
                TaskAssistance::apply($group, AssistanceKind::Direction, $comment->body, $comment->body, replaceDirection: true, source: $comment);
                TaskQuestions::recordOperator($task, $comment);
            }

            return $comment;
        }));
    }
}
