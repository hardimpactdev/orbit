<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final readonly class FetchGatewayRootCa
{
    private const int TIMEOUT = 10;

    public function handle(string $gatewayIp): RootCaFetchResult
    {
        $response = $this->fetchRootCa($gatewayIp);

        if (! $response->successful()) {
            throw new RuntimeException(
                "Failed to fetch CA from gateway at http://{$gatewayIp}/api/ca/root: HTTP {$response->status()}",
            );
        }

        $rootCa = $response->json('success.data.root_ca')
            ?? $response->json('data.root_ca')
            ?? $response->body();

        if (! is_string($rootCa) || $rootCa === '' || str_starts_with($rootCa, '{')) {
            if (is_string($rootCa) && str_starts_with($rootCa, '{')) {
                $decoded = json_decode($rootCa, true);
                $rootCa = $decoded['data']['root_ca']
                    ?? $decoded['success']['data']['root_ca']
                    ?? null;
            }
        }

        if (! is_string($rootCa) || $rootCa === '') {
            throw new RuntimeException("Gateway at {$gatewayIp} returned an invalid or empty CA.");
        }

        if (! str_contains($rootCa, '-----BEGIN CERTIFICATE-----') || ! str_contains($rootCa, '-----END CERTIFICATE-----')) {
            throw new RuntimeException("Gateway at {$gatewayIp} returned non-PEM content.");
        }

        $sha256 = hash('sha256', $rootCa);
        $sourceUrl = "https://{$gatewayIp}/api/ca/root";

        return new RootCaFetchResult(
            pem: $rootCa,
            sha256: $sha256,
            sourceUrl: $sourceUrl,
        );
    }

    private function fetchRootCa(string $gatewayIp): Response
    {
        $response = Http::timeout(self::TIMEOUT)
            ->withOptions(['allow_redirects' => false])
            ->acceptJson()
            ->get("http://{$gatewayIp}/api/ca/root");

        if (! in_array($response->status(), [301, 302, 307, 308], true)) {
            return $response;
        }

        $location = $response->header('Location');

        if (! is_string($location) || ! $this->isSameGatewayCaLocation($location, $gatewayIp)) {
            return $response;
        }

        return Http::timeout(self::TIMEOUT)
            ->withOptions(['allow_redirects' => false])
            ->withoutVerifying()
            ->acceptJson()
            ->get($location);
    }

    private function isSameGatewayCaLocation(string $location, string $gatewayIp): bool
    {
        $parts = parse_url($location);

        return ($parts['scheme'] ?? null) === 'https'
            && ($parts['host'] ?? null) === $gatewayIp
            && ($parts['path'] ?? null) === '/api/ca/root';
    }
}
