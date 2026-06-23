<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Testing;

use Closure;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\PendingRequest;

final class GatewayMockClient
{
    /**
     * @param  array<int|string, GatewayMockResponse|callable(GatewayPendingRequest): GatewayMockResponse>  $responses
     */
    public static function global(array $responses): void
    {
        MockClient::global(self::mapResponses($responses));
    }

    public static function destroyGlobal(): void
    {
        MockClient::destroyGlobal();
    }

    public static function hasGlobal(): bool
    {
        return MockClient::getGlobal() !== null;
    }

    public static function lastPendingRequest(): ?GatewayPendingRequest
    {
        $pendingRequest = MockClient::getGlobal()?->getLastPendingRequest();

        return $pendingRequest instanceof PendingRequest
            ? new GatewayPendingRequest($pendingRequest)
            : null;
    }

    /**
     * @param  array<int|string, GatewayMockResponse|callable(GatewayPendingRequest): GatewayMockResponse>  $responses
     * @return array<int|string, GatewayMockResponse|Closure(PendingRequest): GatewayMockResponse>
     */
    private static function mapResponses(array $responses): array
    {
        $mapped = [];

        foreach ($responses as $key => $response) {
            $mapped[$key] = is_callable($response)
                ? static fn (PendingRequest $pendingRequest): GatewayMockResponse => $response(new GatewayPendingRequest($pendingRequest))
                : $response;
        }

        return $mapped;
    }
}
