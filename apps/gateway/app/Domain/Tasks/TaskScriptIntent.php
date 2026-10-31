<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Operations\StartOperationAction;
use App\Data\Tasks\StartTaskScriptData;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;

/** Admission only. The exact VM worker owns script execution and workspace locking. */
final readonly class TaskScriptIntent
{
    public function __construct(private StartOperationAction $start, private OperationIdempotency $idempotency) {}

    public function start(Task $group, StartTaskScriptData $input, string $caller, ?string $request): Operation
    {
        if (($existing = $this->idempotency->replay('tasks.script')) !== null) {
            return $existing;
        }
        $group->refresh()->load('taskable', 'project');
        if (in_array($group->status, TaskGroupStatus::endedStatuses(), true)) {
            throw new ResourceOperationException('tasks.group_closed', 'This task group has ended.', 409);
        }
        $instance = $group->taskable;
        if (! $instance instanceof Instance || $instance->task_workspace_owner_id !== $group->id || $instance->project_id !== $group->project_id
            || TaskVm::forGroup($group)?->node_id !== $instance->node_id || $instance->checkout_path === '') {
            throw new ResourceOperationException('tasks.no_workspace', 'The group requires its owned task VM workspace.', 409);
        }
        $task = $group->tasks()->findOrFail($input->subtask);
        $current = $group->tasks()->whereNotIn('status', [TaskStatus::Completed, TaskStatus::Failed, TaskStatus::Cancelled])->orderBy('position')->orderBy('id')->value('id');
        if ($current !== $task->id) {
            throw new ResourceOperationException('tasks.subtask_not_current', 'Only the current open subtask can run.', 409);
        }

        return $this->start->execute('tasks.script', 'task_group', $group->id, $instance->node_id, [
            ...$input->toArray(), 'group_id' => $group->id, 'instance_id' => $instance->id, 'node_id' => $instance->node_id,
            'checkout_path' => $instance->checkout_path, 'ssr_port' => $instance->ssr_port,
        ], $caller, $request);
    }
}
