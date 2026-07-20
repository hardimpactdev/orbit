<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Instances;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Responses\Instances\InstanceWorkerResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class EnableInstanceWorkerRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        public readonly string $instance,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/instances/'.rawurlencode($this->instance).'/worker/enable';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [];
    }

    public function createDtoFromResponse(Response $response): InstanceWorkerResponse
    {
        return new InstanceWorkerResponse(data: $this->unwrapData($response));
    }
}
