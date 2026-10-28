<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Nodes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Nodes\RemovedNodeAccessResponse;
use Orbit\Sdk\Responses\Nodes\NodeAccessMutationResponse;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class RemoveNodeAccessRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        private readonly int $consumerNodeId,
        private readonly int $servingNodeId,
        private readonly ?string $idempotencyKey = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/nodes/{$this->servingNodeId}/access/{$this->consumerNodeId}";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): NodeAccessMutationResponse
    {
        $data = $this->unwrapData($response);
        $requestId = $this->successRequestId($response);

        return new NodeAccessMutationResponse($response->status() === 202
            ? OperationResponse::fromGatewayData($data, $requestId)
            : RemovedNodeAccessResponse::fromGatewayData($data, $requestId));
    }
    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return $this->idempotencyKey === null ? [] : ['Idempotency-Key' => $this->idempotencyKey];
    }

}
