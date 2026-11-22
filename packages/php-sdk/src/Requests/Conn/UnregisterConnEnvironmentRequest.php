<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Conn;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Conn\UnregisteredConnEnvironmentResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

/** Remove a host Conn T3 server from the Gateway registry. Idempotent; host servers only. */
final class UnregisterConnEnvironmentRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(private readonly string $environmentId) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/conn/environments/'.rawurlencode($this->environmentId);
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): UnregisteredConnEnvironmentResponse
    {
        return UnregisteredConnEnvironmentResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
