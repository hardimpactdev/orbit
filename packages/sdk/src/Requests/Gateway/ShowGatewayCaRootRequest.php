<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Gateway;

use Orbit\Sdk\Laravel\GatewayRequest;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowGatewayCaRootRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/ca/root';
    }

    #[\Override]
    public function hasRequestFailed(Response $response): ?bool
    {
        return false;
    }
}
