<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Clusters;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class SetClusterRouterRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::PUT;

    public function __construct(
        private readonly int $clusterId,
        private readonly int $nodeId,
        private readonly ?string $idempotencyKey = null,
    ) {}

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return $this->idempotencyKey === null ? [] : ['Idempotency-Key' => $this->idempotencyKey];
    }

    public function resolveEndpoint(): string
    {
        return "/api/v1/clusters/{$this->clusterId}/router/{$this->nodeId}";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): OperationResponse
    {
        return OperationResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
