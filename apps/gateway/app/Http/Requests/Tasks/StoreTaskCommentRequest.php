<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class StoreTaskCommentRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        if ($this->target()->parent_id === null) {
            return (new StoreTaskGroupCommentRequest)->rules();
        }

        return [
            'type' => ['required', 'string', Rule::in(['assistance_requested', 'resolution'])],
            'body' => ['required', 'string', 'max:100000'],
            'author' => ['required', 'string', 'max:255'],
            'agent_thread_id' => ['nullable', 'integer', 'exists:agent_threads,id'],
        ];
    }

    /**
     * The record that takes the comment. A task with no subtask takes it on its work as a subtask does, while the task is open
     * (docs/reference/tasks.md#a-task-with-no-subtask): a resolution goes where the assistance request it answers is recorded, and
     * a request goes to the open work, else to the task.
     */
    public function target(): Task
    {
        $task = $this->route('task');
        if (! $task instanceof Task) {
            abort(404);
        }
        $work = $task->parent_id === null ? $task->work()->first() : null;
        if (! $work instanceof Task || in_array($task->status, TaskGroupStatus::endedStatuses(), true)) {
            return $task;
        }
        if ($this->json('type') === 'resolution' && ($work->assistance_requested || $task->assistance_requested)) {
            return $work->assistance_requested ? $work : $task;
        }

        return in_array($work->status, [TaskStatus::Completed, TaskStatus::Failed, TaskStatus::Cancelled], true) ? $task : $work;
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        $fields = $this->target()->parent_id === null ? ['type', 'body', 'author'] : ['type', 'body', 'author', 'agent_thread_id'];
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), $fields);
        } catch (\UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }
}
