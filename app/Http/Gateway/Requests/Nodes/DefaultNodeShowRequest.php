<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Nodes;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Nodes\NodeDefaultResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class DefaultNodeShowRequest extends GatewayRequest
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/nodes/default';
    }

    public function createDtoFromResponse(Response $response): NodeDefaultResponse
    {
        $data = $this->unwrapData($response);
        $name = $data['default_node'] ?? null;

        return new NodeDefaultResponse(
            defaultNode: is_string($name) && $name !== '' ? $name : null,
        );
    }
}
