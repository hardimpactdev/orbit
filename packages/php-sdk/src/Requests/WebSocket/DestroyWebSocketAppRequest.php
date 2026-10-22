<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\WebSocket;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\WebSocket\WebSocketChangeResponse;
use Orbit\Sdk\Support\GatewayRequestId;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class DestroyWebSocketAppRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::DELETE;

    private readonly string $operationKey;

    public function __construct(
        private readonly string $name,
        ?string $operationKey = null,
    ) {
        $this->operationKey = $operationKey ?? GatewayRequestId::generate();
    }

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return ['Idempotency-Key' => $this->operationKey];
    }

    public function resolveEndpoint(): string
    {
        return '/api/v1/websocket-apps/'.rawurlencode($this->name);
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): WebSocketChangeResponse
    {
        return WebSocketChangeResponse::fromGatewayData($this->unwrapData($response), $response->json('operation'), $this->successRequestId($response));
    }
}
