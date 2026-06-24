<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Nodes;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Responses\Nodes\NodeShowResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowNodeRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        public readonly string $name,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/nodes/{$this->name}";
    }

    public function createDtoFromResponse(Response $response): NodeShowResponse
    {
        $data = $this->unwrapData($response);
        $node = $data['node'] ?? [];

        return new NodeShowResponse(
            node: $this->stringKeyedArray($node),
        );
    }
}
