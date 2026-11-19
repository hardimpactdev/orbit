<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Requests;

use App\Actions\Tasks\ListTaskGroupsAction;
use App\Domain\Tasks\TaskGroupStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The Tasks panel's scope and page ([Tasks panel](/reference/conn#tasks-panel)): one Project's or one workspace's top-level tasks, newest first. */
final class ListConnTasksRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'project' => ['sometimes', 'integer', 'min:1'],
            'instance' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', Rule::enum(TaskGroupStatus::class)],
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
        return $this->has('project') ? $this->integer('project') : null;
    }

    public function instanceId(): ?int
    {
        return $this->has('instance') ? $this->integer('instance') : null;
    }

    public function status(): ?TaskGroupStatus
    {
        $value = $this->validated('status');

        return is_string($value) ? TaskGroupStatus::from($value) : null;
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
}
