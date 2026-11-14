<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tasks;

/** One page of task:list; with a parent, all of its subtasks and no next page. */
final readonly class TaskGroupsResponse
{
    /** @param list<TaskGroupResponse|SubtaskResponse> $taskGroups */
    public function __construct(
        public array $taskGroups,
        public string $requestId,
        public ?string $nextCursor = null,
    ) {}

    /** @return array{task_groups: list<array<string, mixed>>, next_cursor: ?string, request_id: string} */
    public function toArray(): array
    {
        return [
            'task_groups' => array_map(static function (TaskGroupResponse|SubtaskResponse $group): array {
                $data = $group->toArray();
                unset($data['request_id']);

                return $data;
            }, $this->taskGroups),
            'next_cursor' => $this->nextCursor,
            'request_id' => $this->requestId,
        ];
    }
}
