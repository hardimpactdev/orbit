<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use App\Data\Tasks\UpdateTaskGroupData;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOrchestration;
use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class UpdateTaskGroupRequest extends FormRequest
{
    /** @return array<string, list<string|Enum>> */
    public function rules(): array
    {
        $group = $this->route('group');
        $external = $group instanceof Task && $group->groupOrchestration() === TaskOrchestration::External;

        return [
            'preview' => ['sometimes', 'boolean:strict'],
            'title' => ['sometimes', 'string', 'max:160'],
            'brief' => ['sometimes', 'string', 'max:8000'],
            'status' => ['sometimes', 'string', Rule::enum(TaskGroupStatus::class)->only($external ? TaskGroupStatus::cases() : [TaskGroupStatus::Backlog, TaskGroupStatus::Todo])],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['title', 'brief', 'status', 'preview']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function payload(): UpdateTaskGroupData
    {
        $status = $this->validated('status');

        return new UpdateTaskGroupData(
            title: is_string($this->validated('title')) ? $this->validated('title') : null,
            brief: is_string($this->validated('brief')) ? $this->validated('brief') : null,
            status: is_string($status) ? TaskGroupStatus::from($status) : null,
            preview: is_bool($this->validated('preview')) ? $this->validated('preview') : null,
        );
    }
}
