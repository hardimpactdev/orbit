<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Conn;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Conn\ConnPushCredentialsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use SensitiveParameter;

/** Reads whether Conn APNs push credentials are stored. Never returns the private key. */
final class ShowConnPushCredentialsRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/v1/conn/push-credentials';
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): ConnPushCredentialsResponse
    {
        return ConnPushCredentialsResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }
}
