<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Conn;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Conn\ConnEnvironmentRestartsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

/** One container Instance's T3 server: a restart for the machine name when it is idle. A repeated call reads the current state again. */
final class RestartConnEnvironmentRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(private readonly int $id) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/conn/instances/{$this->id}/t3-restart";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): ConnEnvironmentRestartsResponse
    {
        return ConnEnvironmentRestartsResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
