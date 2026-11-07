<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tasks;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Tasks\TaskEgressResponse;
use Orbit\Sdk\Responses\Tasks\TaskEgressRowResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

/** The outside hosts a task group's VM connected to (`tasks:egress`). */
final class ListTaskEgressRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly int $groupId,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/task-groups/{$this->groupId}/egress";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): TaskEgressResponse
    {
        $requestId = $this->successRequestId($response);
        $rows = [];

        foreach ($this->unwrapDataList($response, true) as $entry) {
            $rows[] = TaskEgressRowResponse::fromGatewayData($entry, $requestId);
        }

        return new TaskEgressResponse($rows, $requestId);
    }
}
