<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\WebSocket;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Orbit\Sdk\Support\GatewayRequestId;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class CreateWebSocketAppRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

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
        return '/api/v1/websocket-apps';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): OperationResponse
    {
        return OperationResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }

    /** @return array{name: string} */
    protected function defaultBody(): array
    {
        return ['name' => $this->name];
    }
}
