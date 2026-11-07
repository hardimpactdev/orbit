<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tasks;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Tasks\SubtaskResponse;
use Orbit\Sdk\Responses\Tasks\TaskGroupResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowTaskRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly int $groupId,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/tasks/{$this->groupId}";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): TaskGroupResponse|SubtaskResponse
    {
        $data = $this->unwrapData($response);

        return ($data['parent_id'] ?? null) === null
            ? TaskGroupResponse::fromGatewayData($data, $this->successRequestId($response))
            : SubtaskResponse::fromGatewayData($data, $this->successRequestId($response));
    }
}
