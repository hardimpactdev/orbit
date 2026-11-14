<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use App\Data\Tasks\CreateTaskData;
use App\Data\Tasks\CreateTaskGroupData;
use App\Data\Tasks\TaskInputData;
use App\Domain\Tasks\TaskCopyAgent;
use App\Domain\Tasks\TaskDeliverableType;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOrchestration;
use App\Domain\Tasks\TaskReviewMode;
use App\Domain\Tasks\TaskTopology;
use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Models\Project;
use App\Models\Task;
use App\Rules\CommandPaths;
use App\Rules\DistinctDeliverableIds;
use App\Rules\FailsOnBase;
use App\Rules\NotBlank;
use App\Rules\TaskTopologyList;
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

    /** @return array<string, list<string|Exists|In|Enum|DistinctDeliverableIds|FailsOnBase|CommandPaths|TaskTopologyList|NotBlank>> */
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

    /** @return array<string, list<string|Exists|In|Enum|DistinctDeliverableIds|FailsOnBase|CommandPaths|TaskTopologyList|NotBlank>> */
    public function parentRules(): array
    {
        return [
            'project_id' => ['required', 'integer:strict', 'min:1', Rule::exists(Project::class, 'id')],
            'title' => ['required', 'string', 'max:160'],
            'brief' => ['required', 'string', 'max:8000'],
            'status' => ['sometimes', 'string', Rule::in([TaskGroupStatus::Backlog->value, TaskGroupStatus::Todo->value])],
            'orchestration' => ['sometimes', 'string', Rule::enum(TaskOrchestration::class)],
            'review' => ['sometimes', 'string', Rule::enum(TaskReviewMode::class)],
            'preview' => ['sometimes', 'boolean:strict'],
            'current_recipe' => ['sometimes', 'boolean:strict'],
            ...TaskCopyAgent::rules(),
            'subtasks' => ['sometimes', 'array', 'max:50'],
            'subtasks.*.title' => ['required', 'string', 'max:160'],
            'subtasks.*.brief' => ['required', 'string', 'max:8000'],
            'subtasks.*.topology' => ['sometimes', 'array', 'list', 'max:'.TaskTopology::maxNodes(), new TaskTopologyList],
            'subtasks.*.topology.*' => ['required', 'string', 'max:64', 'regex:'.TaskTopology::EntryPattern],
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
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), $this->has('parent_id')
                ? ['parent_id', 'project_id', 'title', 'brief', 'deliverables', 'topology']
                : [
                    'project_id',
                    'title',
                    'brief',
                    'status',
                    'orchestration',
                    'review',
                    'preview',
                    'current_recipe',
                    ...TaskCopyAgent::fields(),
                    'subtasks',
                ]);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function parentPayload(): CreateTaskGroupData
    {
        $tasks = [];
        $validatedTasks = $this->validated('subtasks');

        foreach (is_array($validatedTasks) ? $validatedTasks : [] as $task) {
            if (! is_array($task) || ! is_string($task['title'] ?? null) || ! is_string($task['brief'] ?? null)) {
                continue;
            }

            $tasks[] = new TaskInputData(
                title: $task['title'],
                brief: $task['brief'],
                deliverables: CreateTaskRequest::deliverables($task['deliverables'] ?? null),
                topology: TaskTopology::fromInput($task['topology'] ?? []),
            );
        }

        return new CreateTaskGroupData(
            projectId: $this->integer('project_id'),
            title: $this->string('title')->toString(),
            brief: $this->string('brief')->toString(),
            status: TaskGroupStatus::from($this->string('status', TaskGroupStatus::Backlog->value)->toString()),
            tasks: $tasks,
            preview: $this->boolean('preview'),
            currentRecipe: $this->boolean('current_recipe'),
            orchestration: TaskOrchestration::from($this->string('orchestration', TaskOrchestration::External->value)->toString()),
            review: TaskReviewMode::from($this->string('review', TaskReviewMode::Manual->value)->toString()),
            agents: TaskCopyAgent::provided($this->validated()),
        );
    }

    public function parentTask(): ?Task
    {
        return $this->has('parent_id') ? Task::topLevel()->findOrFail($this->integer('parent_id')) : null;
    }

    public function childPayload(): CreateTaskData
    {
        return new CreateTaskData(
            title: $this->string('title')->toString(),
            brief: $this->string('brief')->toString(),
            deliverables: CreateTaskRequest::deliverables($this->validated('deliverables')),
            topology: TaskTopology::fromInput($this->validated('topology', [])),
        );
    }
}
