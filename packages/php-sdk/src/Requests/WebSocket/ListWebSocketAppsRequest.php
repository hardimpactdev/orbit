<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\WebSocket;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\WebSocket\WebSocketAppResponse;
use Orbit\Sdk\Responses\WebSocket\WebSocketAppsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListWebSocketAppsRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/v1/websocket-apps';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): WebSocketAppsResponse
    {
        $requestId = $this->successRequestId($response);
        $apps = [];

        foreach ($this->unwrapDataList($response) as $app) {
            $apps[] = WebSocketAppResponse::fromGatewayData($app, $requestId);
        }

        return new WebSocketAppsResponse($apps, $requestId);
    }
}
