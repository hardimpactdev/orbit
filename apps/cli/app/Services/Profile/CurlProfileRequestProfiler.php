<?php

declare(strict_types=1);

namespace App\Services\Profile;

final class CurlProfileRequestProfiler implements ProfileRequestProfiler
{
    private const int TOTAL_TIMEOUT_SECONDS = 3;

    private const int CONNECT_TIMEOUT_SECONDS = 2;

    /**
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    public function profile(string $url, array $headers = []): array
    {
        $handle = curl_init($url);

        if ($handle === false) {
            return $this->failedProfile($url, 'Could not initialize cURL.');
        }

        $responseHeaders = [];

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPGET => true,
            CURLOPT_TIMEOUT => self::TOTAL_TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => $this->formatHeaders($headers),
            CURLOPT_HEADERFUNCTION => function ($handle, string $header) use (&$responseHeaders): int {
                $parts = explode(':', $header, 2);

                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($header);
            },
        ]);

        $response = curl_exec($handle);
        $errorMessage = $response === false ? curl_error($handle) : null;
        $info = curl_getinfo($handle);

        if (! is_array($info)) {
            $info = [];
        }

        $completed = $response !== false;
        $request = [
            'method' => 'GET',
            'url' => $url,
            'uri' => $this->requestUri($url),
            'status' => $completed ? $this->httpStatus($info) : null,
            'bytes' => is_string($response) ? strlen($response) : 0,
            'completed' => $completed,
        ];

        $effectiveUrl = $this->effectiveUrl($info);

        if ($effectiveUrl !== null && $effectiveUrl !== $url) {
            $request['effective_url'] = $effectiveUrl;
        }

        return [
            'request' => $request,
            'timings' => [
                'dns_ms' => $this->toMilliseconds($this->floatInfo($info, 'namelookup_time')),
                'connect_ms' => $this->toMilliseconds($this->floatInfo($info, 'connect_time')),
                'tls_ms' => max(
                    0.0,
                    $this->toMilliseconds($this->floatInfo($info, 'appconnect_time') - $this->floatInfo($info, 'connect_time')),
                ),
                'ttfb_ms' => $this->toMilliseconds($this->floatInfo($info, 'starttransfer_time')),
                'download_ms' => max(
                    0.0,
                    $this->toMilliseconds($this->floatInfo($info, 'total_time') - $this->floatInfo($info, 'starttransfer_time')),
                ),
                'total_ms' => $this->toMilliseconds($this->floatInfo($info, 'total_time')),
            ],
            'error' => $errorMessage !== null ? ['message' => $errorMessage] : null,
            'response_headers' => $responseHeaders,
        ];
    }

    /**
     * @return array{
     *     request: array{method: string, url: string, uri: string, status: null, bytes: int, completed: false},
     *     timings: array{dns_ms: float, connect_ms: float, tls_ms: float, ttfb_ms: float, download_ms: float, total_ms: float},
     *     error: array{message: string},
     *     response_headers: array<string, string>
     * }
     */
    private function failedProfile(string $url, string $message): array
    {
        return [
            'request' => [
                'method' => 'GET',
                'url' => $url,
                'uri' => $this->requestUri($url),
                'status' => null,
                'bytes' => 0,
                'completed' => false,
            ],
            'timings' => [
                'dns_ms' => 0.0,
                'connect_ms' => 0.0,
                'tls_ms' => 0.0,
                'ttfb_ms' => 0.0,
                'download_ms' => 0.0,
                'total_ms' => 0.0,
            ],
            'error' => ['message' => $message],
            'response_headers' => [],
        ];
    }

    /**
     * @param  array<string, string>  $headers
     * @return list<string>
     */
    private function formatHeaders(array $headers): array
    {
        $formatted = [];

        foreach ($headers as $name => $value) {
            $formatted[] = "{$name}: {$value}";
        }

        return $formatted;
    }

    /**
     * @param  array<string, mixed>  $info
     */
    private function httpStatus(array $info): ?int
    {
        $status = (int) $this->floatInfo($info, 'http_code');

        return $status > 0 ? $status : null;
    }

    /**
     * @param  array<string, mixed>  $info
     */
    private function effectiveUrl(array $info): ?string
    {
        $effectiveUrl = $info['url'] ?? null;

        return is_string($effectiveUrl) && $effectiveUrl !== '' ? $effectiveUrl : null;
    }

    private function requestUri(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        $query = parse_url($url, PHP_URL_QUERY);

        return is_string($query) && $query !== '' ? "{$path}?{$query}" : $path;
    }

    /**
     * @param  array<string, mixed>  $info
     */
    private function floatInfo(array $info, string $key): float
    {
        $value = $info[$key] ?? 0.0;

        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function toMilliseconds(float $seconds): float
    {
        return round(max(0.0, $seconds) * 1000, 2);
    }
}
