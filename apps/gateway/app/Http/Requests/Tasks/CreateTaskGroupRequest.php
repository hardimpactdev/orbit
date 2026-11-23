<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use App\Data\Tasks\CreateTaskData;
use App\Data\Tasks\CreateTaskGroupData;
use App\Data\Tasks\TaskInputData;
use App\Domain\Tasks\TaskAgent;
use App\Domain\Tasks\TaskDeliverableType;
use App\Domain\Tasks\TaskGroupGuard;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOrchestration;
use App\Domain\Tasks\TaskPrMode;
use App\Domain\Tasks\TaskReviewMode;
use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Models\Project;
use App\Models\Task;
use App\Rules\CommandPaths;
use App\Rules\DistinctDeliverableIds;
use App\Rules\FailsOnBase;
use App\Rules\NotBlank;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class CreateTaskGroupRequest extends FormRequest
{
    use ValidatesDeliverablePaths;

    /** @return array<string, string> */
    public function messages(): array
    {
        return TaskAgent::messages();
    }

    /** @return array<string, list<string|Exists|In|Enum|DistinctDeliverableIds|FailsOnBase|CommandPaths|NotBlank>> */
    public function rules(): array
    {
        if ($this->has('parent_id')) {
            return [...(new CreateTaskRequest)->rules(),
                'parent_id' => ['required', 'integer:strict', 'min:1', Rule::exists(Task::class, 'id')->whereNull('parent_id')],
                'project_id' => ['sometimes', 'integer:strict', Rule::in([Task::topLevel()->find($this->integer('parent_id'))?->project_id])],
            ];
        }

        return $this->parentRules();
    }

    /** @return array<string, list<string|Exists|In|Enum|DistinctDeliverableIds|FailsOnBase|CommandPaths|NotBlank>> */
    public function parentRules(): array
    {
        return [
            'project_id' => ['required', 'integer:strict', 'min:1', Rule::exists(Project::class, 'id')],
            'title' => ['required', 'string', 'max:160'],
            'brief' => ['required', 'string', 'max:8000'],
            'status' => ['sometimes', 'string', Rule::in([TaskGroupStatus::Backlog->value, TaskGroupStatus::Todo->value])],
            'orchestration' => ['sometimes', 'string', Rule::enum(TaskOrchestration::class)],
            'review' => ['sometimes', 'string', Rule::enum(TaskReviewMode::class)],
            'pr_mode' => ['sometimes', 'string', Rule::enum(TaskPrMode::class)],
            'issue' => ['sometimes', 'integer:strict', 'min:1', 'max:2147483647'],
            'preview' => ['sometimes', 'boolean:strict'],
            'current_recipe' => ['sometimes', 'boolean:strict'],
            ...TaskAgent::rules(),
            // A task with no subtask holds its own deliverables (docs/reference/tasks.md#a-task-with-no-subtask).
            ...array_filter((new CreateTaskRequest)->rules(), static fn (string $field): bool => str_starts_with($field, 'deliverables'), ARRAY_FILTER_USE_KEY),
            'subtasks' => ['sometimes', 'array', 'max:50'],
            'subtasks.*.title' => ['required', 'string', 'max:160'],
            'subtasks.*.brief' => ['required', 'string', 'max:8000'],
            'subtasks.*.topology' => ['sometimes', 'array', 'list'],
            'subtasks.*.deliverables' => ['sometimes', 'array', 'list', 'max:5', new DistinctDeliverableIds],
            'subtasks.*.deliverables.*' => ['required', 'array:id,type,description,path,change,command,directory,fails_on_base,paths'],
            'subtasks.*.deliverables.*.id' => ['required', 'string', 'max:64', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/'],
            'subtasks.*.deliverables.*.type' => ['required', 'string', Rule::enum(TaskDeliverableType::class)],
            'subtasks.*.deliverables.*.description' => ['required', 'string', 'max:500'],
            'subtasks.*.deliverables.*.path' => ['required_if:subtasks.*.deliverables.*.type,file', 'prohibited_unless:subtasks.*.deliverables.*.type,file', 'string', 'max:500', 'not_regex:#(?:\A/|(?:\A|/)\.\.(?:/|\z))#'],
            'subtasks.*.deliverables.*.change' => ['required_if:subtasks.*.deliverables.*.type,file', 'prohibited_unless:subtasks.*.deliverables.*.type,file', 'string', Rule::in(['created', 'modified', 'any'])],
            'subtasks.*.deliverables.*.fails_on_base' => ['sometimes', new FailsOnBase, 'boolean:strict'],
            'subtasks.*.deliverables.*.command' => ['required_if:subtasks.*.deliverables.*.type,command', 'prohibited_unless:subtasks.*.deliverables.*.type,command', 'string', 'max:1000'],
            'subtasks.*.deliverables.*.directory' => ['sometimes', 'prohibited_unless:subtasks.*.deliverables.*.type,command', 'string', 'max:500', 'not_regex:#(?:\A/|(?:\A|/)\.\.(?:/|\z))#'],
            'subtasks.*.deliverables.*.paths' => [new CommandPaths, 'prohibited_unless:subtasks.*.deliverables.*.type,command', 'array', 'list', 'max:100'],
            'subtasks.*.deliverables.*.paths.*' => ['required', 'string', 'max:500', 'not_regex:#(?:\A/|(?:\A|/)\.\.(?:/|\z))#'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            $body = app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), $this->has('parent_id')
                ? ['parent_id', 'project_id', 'title', 'brief', 'deliverables', 'topology']
                : [
                    'project_id',
                    'title',
                    'brief',
                    'status',
                    'orchestration',
                    'review',
                    'pr_mode',
                    'issue',
                    'preview',
                    'current_recipe',
                    ...TaskAgent::fields(),
                    'deliverables',
                    'subtasks',
                ]);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
        // The body chooses the shape: subtasks, or the task's own deliverables (docs/reference/tasks.md#a-task-with-no-subtask).
        // Both are refused here, before any rule or repository read.
        if (array_key_exists('deliverables', $body) && array_key_exists('subtasks', $body)) {
            throw ValidationException::withMessages(['subtasks' => ['A task takes either subtasks or its own deliverables, not both.']]);
        }
        // A create in todo with exactly one subtask is told to fold it before any field of that subtask is validated
        // (docs/reference/tasks.md#tasks-and-subtasks).
        if (($body['status'] ?? null) === TaskGroupStatus::Todo->value && is_array($body['subtasks'] ?? null) && array_is_list($body['subtasks'])
            && count($body['subtasks']) === 1) {
            throw TaskGroupGuard::oneSubtask();
        }

        return $body;
    }

    public function parentPayload(): CreateTaskGroupData
    {
        $body = $this->validationData();
        $tasks = [];
        $validatedTasks = $this->validated('subtasks');

        foreach (is_array($validatedTasks) ? $validatedTasks : [] as $task) {
            if (! is_array($task) || ! is_string($task['title'] ?? null) || ! is_string($task['brief'] ?? null)) {
                continue;
            }
            TaskGroupGuard::noTopology(is_array($task['topology'] ?? null) ? $task['topology'] : []);

            $tasks[] = new TaskInputData(
                title: $task['title'],
                brief: $task['brief'],
                deliverables: CreateTaskRequest::deliverables($task['deliverables'] ?? null),
            );
        }

        return new CreateTaskGroupData(
            projectId: $this->integer('project_id'),
            title: $this->string('title')->toString(),
            brief: $this->string('brief')->toString(),
            status: TaskGroupStatus::from($this->string('status', TaskGroupStatus::Backlog->value)->toString()),
            tasks: $tasks,
            deliverables: array_key_exists('deliverables', $body) ? CreateTaskRequest::deliverables($this->validated('deliverables')) : null,
            preview: $this->boolean('preview'),
            currentRecipe: $this->boolean('current_recipe'),
            orchestration: TaskOrchestration::from($this->string('orchestration', TaskOrchestration::External->value)->toString()),
            review: TaskReviewMode::from($this->string('review', TaskReviewMode::Manual->value)->toString()),
            agents: TaskAgent::provided($this->validated()),
            prMode: is_string($this->validated('pr_mode')) ? TaskPrMode::from($this->validated('pr_mode')) : null,
            issue: is_int($this->validated('issue')) ? $this->validated('issue') : null,
        );
    }

    public function parentTask(): ?Task
    {
        return $this->has('parent_id') ? Task::topLevel()->findOrFail($this->integer('parent_id')) : null;
    }

    public function childPayload(): CreateTaskData
    {
        TaskGroupGuard::noTopology(is_array($this->validated('topology')) ? $this->validated('topology') : []);

        return new CreateTaskData(
            title: $this->string('title')->toString(),
            brief: $this->string('brief')->toString(),
            deliverables: CreateTaskRequest::deliverables($this->validated('deliverables')),
        );
    }
}
