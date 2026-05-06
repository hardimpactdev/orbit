<?php

declare(strict_types=1);

namespace App\Http\Gateway;

use App\Contracts\ToolLogGatewayStream;
use App\Models\LocalGatewaySettings;
use App\Services\ActivityLogCorrelation;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final readonly class ToolLogGatewayStreamClient implements ToolLogGatewayStream
{
    /**
     * @param  callable(string): void  $onOutput
     */
    public function follow(string $tool, ?string $node, ?string $app, int $lines, callable $onOutput): int|GatewayApiException
    {
        $settings = LocalGatewaySettings::current();
        $gatewayUrl = is_string($settings->gateway_url) ? rtrim($settings->gateway_url, '/') : '';

        if ($gatewayUrl === '') {
            return $this->unavailable();
        }

        try {
            $response = (new Client)->request('GET', "{$gatewayUrl}/api/tools/".rawurlencode($tool).'/logs/stream', [
                'allow_redirects' => false,
                'connect_timeout' => 10,
                'headers' => $this->headers(),
                'http_errors' => false,
                'query' => array_filter([
                    'app' => $app,
                    'node' => $node,
                    'lines' => $lines,
                ], fn (mixed $value): bool => $value !== null),
                'stream' => true,
                'timeout' => 0,
                'verify' => $settings->ca_pem_path ?: true,
            ]);
        } catch (Throwable $e) {
            return $this->unavailable(trim(get_debug_type($e).' '.$e->getMessage()));
        }

        if ($response->getStatusCode() >= 400) {
            return $this->exceptionFromResponse($response);
        }

        $body = $response->getBody();

        while (! $body->eof()) {
            $chunk = $body->read(8192);

            if ($chunk === '') {
                usleep(50_000);

                continue;
            }

            $onOutput($chunk);
        }

        return 0;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        $headers = [
            'Accept' => 'text/plain',
            'X-Orbit-Client' => 'cli',
        ];

        $correlation = app(ActivityLogCorrelation::class)->current();

        if (is_string($correlation) && $correlation !== '') {
            $headers['X-Orbit-Request-Id'] = $correlation;
        }

        return $headers;
    }

    private function exceptionFromResponse(ResponseInterface $response): GatewayApiException
    {
        $body = (string) $response->getBody();

        if ($body !== '') {
            try {
                /** @var array<string, mixed> $decoded */
                $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $decoded = [];
            }

            $error = $decoded['error'] ?? null;

            if (is_array($error)) {
                return new GatewayApiException(
                    message: is_string($error['message'] ?? null) ? $error['message'] : 'Gateway connection is required to read tool logs.',
                    errorCode: is_string($error['code'] ?? null) ? $error['code'] : null,
                    errorMeta: is_array($error['meta'] ?? null) ? $error['meta'] : [],
                    errorData: is_array($error['data'] ?? null) ? $error['data'] : [],
                );
            }

            $message = trim(strip_tags($body));

            if ($message !== '') {
                return new GatewayApiException(
                    message: mb_substr(preg_replace('/\s+/', ' ', $message) ?? $message, 0, 500),
                    errorCode: null,
                    errorMeta: [],
                );
            }
        }

        return new GatewayApiException(
            message: "Gateway request failed with HTTP status {$response->getStatusCode()}",
            errorCode: null,
            errorMeta: [],
        );
    }

    private function unavailable(?string $reason = null): GatewayApiException
    {
        $message = 'Gateway connection is required to read tool logs.';

        if (is_string($reason) && trim($reason) !== '') {
            $message .= ' '.trim($reason);
        }

        return new GatewayApiException(
            message: $message,
            errorCode: 'gateway_unavailable',
            errorMeta: [],
        );
    }
}
