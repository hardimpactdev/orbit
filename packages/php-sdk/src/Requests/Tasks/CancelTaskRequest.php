<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tasks;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Orbit\Sdk\Responses\Tasks\SubtaskResponse;
use Orbit\Sdk\Responses\Tasks\TaskCancellationResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class CancelTaskRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly int $groupId,
        private readonly string $idempotencyKey,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/tasks/{$this->groupId}/cancel";
    }

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return ['Idempotency-Key' => $this->idempotencyKey];
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): TaskCancellationResponse
    {
        return new TaskCancellationResponse($response->status() === 202
            ? OperationResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response))
            : SubtaskResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response)));
    }
}
