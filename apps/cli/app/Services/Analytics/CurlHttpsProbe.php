<?php

declare(strict_types=1);

namespace App\Services\Analytics;

/** @mago-expect lint:cyclomatic-complexity */
final readonly class CurlHttpsProbe implements HttpsProbe
{
    private const int CONNECT_TIMEOUT_SECONDS = 3;

    private const int TIMEOUT_SECONDS = 10;

    public function get(string $url, array $addresses): array
    {
        $resolve = $this->resolveEntry($url, $addresses);

        if ($resolve === null) {
            return $this->failure('HTTPS probes require a public hostname and approved public DNS addresses.');
        }

        $handle = curl_init($url);

        if ($handle === false) {
            return $this->failure('Could not initialize cURL.');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPGET => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$resolve],
            CURLOPT_USERAGENT => 'Orbit instance:analytics verify',
        ]);

        $response = curl_exec($handle);

        if ($response === false) {
            return $this->failure(curl_error($handle));
        }

        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        return [
            'completed' => true,
            'http_status' => is_int($status) && $status > 0 ? $status : null,
            'tls_verified' => true,
            'error' => null,
        ];
    }

    /** @param list<string> $addresses */
    private function resolveEntry(string $url, array $addresses): ?string
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) ? $parts['scheme'] ?? null : null;
        $host = is_array($parts) ? $parts['host'] ?? null : null;
        $port = is_array($parts) ? $parts['port'] ?? 443 : null;

        if ($scheme !== 'https' || ! is_string($host) || $port !== 443) {
            return null;
        }

        $publicAddresses = [];

        foreach ($addresses as $address) {
            if (! $this->isPublicIp($address)) {
                continue;
            }

            $publicAddresses[] = str_contains($address, ':') ? "[{$address}]" : $address;
        }

        if ($publicAddresses === []) {
            return null;
        }

        return "{$host}:443:".implode(',', array_values(array_unique($publicAddresses)));
    }

    private function isPublicIp(string $value): bool
    {
        return GlobalUnicastIpAddress::isGlobalUnicast($value);
    }

    /** @return array{completed: false, http_status: null, tls_verified: false, error: string} */
    private function failure(string $message): array
    {
        return [
            'completed' => false,
            'http_status' => null,
            'tls_verified' => false,
            'error' => $message === '' ? 'HTTPS request failed.' : $message,
        ];
    }
}
