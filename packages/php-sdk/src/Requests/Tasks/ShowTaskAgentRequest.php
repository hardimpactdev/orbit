<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tasks;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Tasks\TaskAgentSnapshotResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowTaskAgentRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly int $groupId,
        private readonly int $sessionId,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/task-groups/{$this->groupId}/agents/{$this->sessionId}";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): TaskAgentSnapshotResponse
    {
        return TaskAgentSnapshotResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
