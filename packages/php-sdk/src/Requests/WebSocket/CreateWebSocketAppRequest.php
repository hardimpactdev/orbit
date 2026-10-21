<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\WebSocket;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\WebSocket\WebSocketAppResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class CreateWebSocketAppRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $name,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/websocket-apps';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): WebSocketAppResponse
    {
        return WebSocketAppResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }

    /** @return array{name: string} */
    protected function defaultBody(): array
    {
        return ['name' => $this->name];
    }
}
