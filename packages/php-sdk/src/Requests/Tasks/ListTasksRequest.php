<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tasks;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Tasks\SubtaskResponse;
use Orbit\Sdk\Responses\Tasks\TaskGroupResponse;
use Orbit\Sdk\Responses\Tasks\TaskGroupsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

/** Lists one page of task groups, newest first, or the subtasks of one parent. Subtask rows carry no completion summary, fixup problem or check output. */
final class ListTasksRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly ?int $projectId = null,
        private readonly ?string $status = null,
        private readonly ?int $parentId = null,
        private readonly ?int $limit = null,
        private readonly ?string $cursor = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/tasks';
    }

    /** @return array<string, int|string> */
    protected function defaultQuery(): array
    {
        return array_filter(
            ['project_id' => $this->projectId, 'status' => $this->status, 'parent_id' => $this->parentId, 'limit' => $this->limit, 'cursor' => $this->cursor],
            static fn (int|string|null $value): bool => $value !== null,
        );
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): TaskGroupsResponse
    {
        $requestId = $this->successRequestId($response);
        $taskGroups = [];

        foreach ($this->unwrapDataList($response, true) as $entry) {
            $taskGroups[] = ($entry['parent_id'] ?? null) === null ? TaskGroupResponse::fromGatewayData($entry, $requestId) : SubtaskResponse::fromGatewayData($entry, $requestId);
        }

        return new TaskGroupsResponse($taskGroups, $requestId, is_string($response->json('meta.next_cursor')) ? $response->json('meta.next_cursor') : null);
    }
}
