<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\WebSocket;

use Orbit\Sdk\Support\GatewayRequestId;

final readonly class WebSocketAppsResponse
{
    public string $requestId;

    /** @param list<WebSocketAppResponse> $apps */
    public function __construct(
        public array $apps,
        string $requestId,
    ) {
        $this->requestId = GatewayRequestId::fromTransport($requestId) ?? '';
    }

    /** @return array{apps: list<array<string, mixed>>, request_id: string} */
    public function toArray(): array
    {
        return [
            'apps' => array_map(
                static function (WebSocketAppResponse $app): array {
                    $data = $app->toArray();
                    unset($data['request_id']);

                    return $data;
                },
                $this->apps,
            ),
            'request_id' => $this->requestId,
        ];
    }
}
