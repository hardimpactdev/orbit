<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Metrics;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use SensitiveParameter;

final class EnableMetricsExporterRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::PUT;

    public function __construct(
        private readonly int $nodeId,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/metrics/exporters/'.$this->nodeId;
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): OperationResponse
    {
        return OperationResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }
}
