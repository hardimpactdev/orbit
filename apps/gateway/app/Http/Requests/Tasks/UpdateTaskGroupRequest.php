<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use App\Data\Tasks\UpdateTaskData;
use App\Data\Tasks\UpdateTaskGroupData;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskTopology;
use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Models\Task;
use App\Rules\CommandPaths;
use App\Rules\DistinctDeliverableIds;
use App\Rules\FailsOnBase;
use App\Rules\TaskTopologyList;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Rules\ProhibitedIf;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class UpdateTaskGroupRequest extends FormRequest
{
    use ValidatesDeliverablePaths;

    /** @return array<string, list<string|Enum|CommandPaths|DistinctDeliverableIds|FailsOnBase|TaskTopologyList|In|ProhibitedIf>> */
    public function rules(): array
    {
        if ($this->route('task') instanceof Task && $this->route('task')->parent_id !== null) {
            return (new UpdateTaskRequest)->rules();
        }

        return $this->parentRules();
    }

    /** @return array<string, list<string|Enum|CommandPaths|DistinctDeliverableIds|FailsOnBase|TaskTopologyList|In|ProhibitedIf>> */
    public function parentRules(): array
    {
        return [
            'preview' => ['sometimes', 'boolean:strict'],
            'title' => ['sometimes', 'string', 'max:160'],
            'brief' => ['sometimes', 'string', 'max:8000'],
            'status' => ['sometimes', 'string', Rule::enum(TaskGroupStatus::class)],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), $this->route('task') instanceof Task && $this->route('task')->parent_id !== null
                ? ['title', 'brief', 'position', 'deliverables', 'topology', 'status']
                : ['title', 'brief', 'status', 'preview']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function parentPayload(): UpdateTaskGroupData
    {
        $status = $this->validated('status');

        return new UpdateTaskGroupData(
            title: is_string($this->validated('title')) ? $this->validated('title') : null,
            brief: is_string($this->validated('brief')) ? $this->validated('brief') : null,
            status: is_string($status) ? TaskGroupStatus::from($status) : null,
            preview: is_bool($this->validated('preview')) ? $this->validated('preview') : null,
        );
    }

    public function childPayload(): UpdateTaskData
    {
        return new UpdateTaskData(
            title: is_string($this->validated('title')) ? $this->validated('title') : null,
            brief: is_string($this->validated('brief')) ? $this->validated('brief') : null,
            position: is_int($this->validated('position')) ? $this->validated('position') : null,
            status: is_string($this->validated('status')) ? TaskStatus::from($this->validated('status')) : null,
            topology: array_key_exists('topology', $this->validated()) ? TaskTopology::fromInput($this->validated('topology')) : null,
            deliverables: array_key_exists('deliverables', $this->validated()) ? CreateTaskRequest::deliverables($this->validated('deliverables')) : null,
        );
    }
}
