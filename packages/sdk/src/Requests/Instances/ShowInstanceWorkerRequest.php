<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Instances;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Responses\Instances\InstanceWorkerResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowInstanceWorkerRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        public readonly string $instance,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/instances/'.rawurlencode($this->instance).'/worker';
    }

    public function createDtoFromResponse(Response $response): InstanceWorkerResponse
    {
        return new InstanceWorkerResponse(data: $this->unwrapData($response));
    }
}
