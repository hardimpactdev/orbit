<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Nodes;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Nodes\NodeDefaultResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class DefaultNodeClearRequest extends GatewayRequest
{
    protected Method $method = Method::DELETE;

    public function resolveEndpoint(): string
    {
        return '/api/nodes/default';
    }

    public function createDtoFromResponse(Response $response): NodeDefaultResponse
    {
        $this->unwrapData($response);

        return new NodeDefaultResponse(defaultNode: null);
    }
}
