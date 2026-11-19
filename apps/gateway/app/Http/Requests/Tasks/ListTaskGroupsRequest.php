<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use App\Actions\Tasks\ListTaskGroupsAction;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The list filters and page. A page applies to groups; the subtasks of one parent are listed whole. */
final class ListTaskGroupsRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (in_array($this->input('compact'), ['true', 'false'], true)) {
            $this->merge(['compact' => $this->input('compact') === 'true']);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'compact' => ['sometimes', 'boolean'],
            'project_id' => ['sometimes', 'integer', 'min:1'],
            'parent_id' => ['sometimes', 'integer', 'min:1', 'prohibits:limit,cursor,repository,pull_request', Rule::exists(Task::class, 'id')->whereNull('parent_id')],
            'status' => ['sometimes', 'string', Rule::enum($this->has('parent_id') ? TaskStatus::class : TaskGroupStatus::class)],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.ListTaskGroupsAction::MaxLimit],
            'cursor' => ['sometimes', 'string', 'max:20', 'regex:/\A[1-9][0-9]*\z/'],
            'repository' => ['required_with:pull_request', 'string', ListTaskGroupsAction::RepositoryRule],
            'pull_request' => ['required_with:repository', 'integer', 'min:1', 'max:9999999999'],
        ];
    }

    /**
     * The pull request filter: the groups whose published or watched pull request this is.
     *
     * @return array{repository: string, number: int}|null
     */
    public function pullRequest(): ?array
    {
        return $this->has('pull_request') ? ['repository' => $this->string('repository')->value(), 'number' => $this->integer('pull_request')] : null;
    }

    public function projectId(): ?int
    {
        $value = $this->validated('project_id');

        return is_numeric($value) ? (int) $value : null;
    }

    public function status(): ?TaskGroupStatus
    {
        $value = $this->validated('status');

        return is_string($value) ? TaskGroupStatus::from($value) : null;
    }

    public function subtaskStatus(): ?TaskStatus
    {
        $value = $this->validated('status');

        return is_string($value) ? TaskStatus::from($value) : null;
    }

    public function limit(): int
    {
        return $this->integer('limit', ListTaskGroupsAction::DefaultLimit);
    }

    /** The ID the next page starts below. */
    public function cursor(): ?int
    {
        $cursor = $this->validated('cursor');

        return is_string($cursor) ? (int) $cursor : null;
    }

    public function parentTask(): ?Task
    {
        return $this->has('parent_id') ? Task::topLevel()->select(['id', 'project_id'])->findOrFail($this->integer('parent_id')) : null;
    }
}
