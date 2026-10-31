<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Analytics;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use SensitiveParameter;

/** Removes every tracking host of an Instance. */
final class DisableInstanceAnalyticsRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(private readonly int $instanceId) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/instances/{$this->instanceId}/analytics";
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): OperationResponse
    {
        return OperationResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }
}
