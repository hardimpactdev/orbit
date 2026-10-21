<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\WebSocket;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\WebSocket\WebSocketChangeResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowWebSocketOperationRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $name,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/websocket-operations/'.rawurlencode($this->name);
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): WebSocketChangeResponse
    {
        return WebSocketChangeResponse::fromGatewayData($this->unwrapData($response), $response->json('operation'), $this->successRequestId($response));
    }
}
