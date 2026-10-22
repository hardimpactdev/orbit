<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\WebSocket;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\WebSocket\WebSocketAppAttachmentResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class AddInstanceWebSocketAppRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::PUT;

    public function __construct(
        private readonly int|string $instance,
        private readonly string $name,
        private readonly ?bool $public = null,
    ) {}

    /** @return array<string, bool> */
    protected function defaultBody(): array
    {
        return $this->public === null ? [] : ['public' => $this->public];
    }

    public function resolveEndpoint(): string
    {
        return '/api/v1/instances/'.rawurlencode((string) $this->instance)
            .'/websocket-apps/'.rawurlencode($this->name);
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): WebSocketAppAttachmentResponse
    {
        return WebSocketAppAttachmentResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }
}
