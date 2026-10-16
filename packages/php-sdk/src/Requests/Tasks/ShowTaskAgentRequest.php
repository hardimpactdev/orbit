<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tasks;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Tasks\TaskAgentSnapshotResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

/**
 * Reads one agent thread. With `afterSequence`, the previous answer's cursor, the Gateway returns a delta with only the
 * entries after it when the thread's Pi server can resume from it.
 */
final class ShowTaskAgentRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly int $groupId,
        private readonly int $sessionId,
        private readonly ?string $afterSequence = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/task-groups/{$this->groupId}/agents/{$this->sessionId}";
    }

    /** @return array<string, string> */
    protected function defaultQuery(): array
    {
        return $this->afterSequence === null ? [] : ['after_sequence' => $this->afterSequence];
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): TaskAgentSnapshotResponse
    {
        return TaskAgentSnapshotResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
