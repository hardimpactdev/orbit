<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\WebSocket;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Orbit\Sdk\Support\GatewayRequestId;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class RotateWebSocketAppRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::POST;

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
        return '/api/v1/websocket-apps/'.rawurlencode($this->name).'/rotate';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): OperationResponse
    {
        return OperationResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
