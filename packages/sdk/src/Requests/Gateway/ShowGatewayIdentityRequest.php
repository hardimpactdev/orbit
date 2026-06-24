<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Gateway;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Responses\Gateway\GatewayIdentityResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowGatewayIdentityRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/me';
    }

    #[\Override]
    public function hasRequestFailed(Response $response): ?bool
    {
        return false;
    }

    public function createDtoFromResponse(Response $response): GatewayIdentityResponse
    {
        $data = $this->unwrapData($response);
        $self = is_array($data['self'] ?? null) ? $this->stringKeyedArray($data['self']) : $data['node'] ?? null;
        $gateway = is_array($data['gateway'] ?? null) ? $this->stringKeyedArray($data['gateway']) : null;

        return new GatewayIdentityResponse(
            self: is_array($self) ? $this->stringKeyedArray($self) : null,
            gateway: $gateway,
        );
    }
}
