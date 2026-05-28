<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class FetchGatewayRootCa implements FetchesGatewayRootCa
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

        $decoded = $this->decodeJsonBody($response->body());
        $rootCa = is_array($decoded)
            ? $decoded['success']['data']['root_ca'] ?? $decoded['data']['root_ca'] ?? null
            : $response->body();

        if (is_string($rootCa) && str_starts_with($rootCa, '{')) {
            $inner = json_decode($rootCa, true);
            $rootCa = is_array($inner)
                ? ($inner['data']['root_ca'] ?? $inner['success']['data']['root_ca'] ?? null)
                : null;
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
            ->withoutRedirecting()
            ->get("http://{$gatewayIp}/api/ca/root");

        if (! in_array($response->status(), [301, 302, 307, 308], true)) {
            return $response;
        }

        $location = $response->header('Location');

        if (! is_string($location) || ! $this->isSameGatewayCaLocation($location, $gatewayIp)) {
            return $response;
        }

        return Http::timeout(self::TIMEOUT)
            ->withoutVerifying()
            ->get("https://{$gatewayIp}/api/ca/root");
    }

    private function isSameGatewayCaLocation(string $location, string $gatewayIp): bool
    {
        $parts = parse_url($location);

        return ($parts['scheme'] ?? null) === 'https'
            && ($parts['host'] ?? null) === $gatewayIp
            && ($parts['path'] ?? null) === '/api/ca/root';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonBody(string $body): ?array
    {
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
