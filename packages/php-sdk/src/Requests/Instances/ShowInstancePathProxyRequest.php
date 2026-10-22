<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Instances;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Instances\InstancePathProxyResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowInstancePathProxyRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly int|string $instance,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/instances/'.rawurlencode((string) $this->instance).'/path-proxy';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): InstancePathProxyResponse
    {
        return InstancePathProxyResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
