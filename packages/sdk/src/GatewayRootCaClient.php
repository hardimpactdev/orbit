<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel;

use Orbit\Sdk\Laravel\Requests\Gateway\ShowGatewayCaRootRequest;

final readonly class GatewayRootCaClient
{
    public function __construct(
        private int $timeout = 10,
    ) {}

    public function fetch(string $gatewayIp): GatewayResponse
    {
        $response = $this->send("http://{$gatewayIp}");

        if (! in_array($response->status(), [301, 302, 307, 308], true)) {
            return $response;
        }

        $location = $response->header('Location');

        if (! is_string($location) || ! $this->isSameGatewayCaLocation($location, $gatewayIp)) {
            return $response;
        }

        return $this->send("https://{$gatewayIp}");
    }

    private function send(string $baseUrl): GatewayResponse
    {
        return GatewayResponse::fromSaloonResponse(
            new GatewayConnector(
                baseUrl: $baseUrl,
                caPemPath: false,
                timeout: $this->timeout,
            )->send(new ShowGatewayCaRootRequest),
        );
    }

    private function isSameGatewayCaLocation(string $location, string $gatewayIp): bool
    {
        $parts = parse_url($location);

        return ($parts['scheme'] ?? null) === 'https'
            && ($parts['host'] ?? null) === $gatewayIp
            && ($parts['path'] ?? null) === '/api/ca/root';
    }
}
