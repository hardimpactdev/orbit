<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\WebSocket;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\WebSocket\WebSocketAppResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowWebSocketAppRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $name,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/websocket-apps/'.rawurlencode($this->name);
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): WebSocketAppResponse
    {
        return WebSocketAppResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
