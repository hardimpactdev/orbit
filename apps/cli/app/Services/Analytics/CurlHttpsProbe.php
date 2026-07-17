<?php

declare(strict_types=1);

namespace App\Services\Analytics;

final readonly class CurlHttpsProbe implements HttpsProbe
{
    private const int CONNECT_TIMEOUT_SECONDS = 3;

    private const int TIMEOUT_SECONDS = 10;

    public function get(string $url): array
    {
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
            CURLOPT_USERAGENT => 'Orbit app:analytics verify',
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
