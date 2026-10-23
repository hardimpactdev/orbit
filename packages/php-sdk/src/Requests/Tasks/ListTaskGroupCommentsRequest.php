<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tasks;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Tasks\TaskCommentResponse;
use Orbit\Sdk\Responses\Tasks\TaskCommentsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListTaskGroupCommentsRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly int $groupId,
        private readonly ?string $type = null,
        private readonly ?int $limit = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/task-groups/{$this->groupId}/comments";
    }

    /** @return array<string, int|string> */
    protected function defaultQuery(): array
    {
        return array_filter(['type' => $this->type, 'limit' => $this->limit], static fn (mixed $value): bool => $value !== null);
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): TaskCommentsResponse
    {
        $requestId = $this->successRequestId($response);
        $comments = [];

        foreach ($this->unwrapDataList($response, true) as $entry) {
            $comments[] = TaskCommentResponse::fromGatewayData($entry, $requestId);
        }

        return new TaskCommentsResponse($comments, $requestId);
    }
}
