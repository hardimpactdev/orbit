<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Dashboard;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Responses\Dashboard\RuntimeInventoryResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowRuntimeInventoryRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/dashboard/runtime-inventory';
    }

    public function createDtoFromResponse(Response $response): RuntimeInventoryResponse
    {
        $data = $this->unwrapData($response);

        return new RuntimeInventoryResponse(
            nodes: $this->listOfStringKeyedArrays($data['nodes'] ?? []),
            apps: $this->listOfStringKeyedArrays($data['apps'] ?? []),
            processes: $this->listOfStringKeyedArrays($data['processes'] ?? []),
            tools: $this->listOfStringKeyedArrays($data['tools'] ?? []),
        );
    }
}
