<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Metrics;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;
use SensitiveParameter;

final class EnableMetricsRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly int $nodeId,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/metrics';
    }

    /** @return array{node_id: int} */
    protected function defaultBody(): array
    {
        return ['node_id' => $this->nodeId];
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): OperationResponse
    {
        return OperationResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }
}
