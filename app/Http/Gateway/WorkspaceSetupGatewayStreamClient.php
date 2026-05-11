<?php

declare(strict_types=1);

namespace App\Http\Gateway;

use App\Models\LocalGatewaySettings;
use App\Services\ActivityLogCorrelation;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class WorkspaceSetupGatewayStreamClient
{
    public function __construct(
        private readonly ?ClientInterface $client = null,
    ) {}

    /**
     * @param  callable(string, array<string, mixed>): void  $onEvent
     */
    public function run(?string $name, ?string $app, ?string $path, callable $onEvent): int|GatewayApiException
    {
        $settings = LocalGatewaySettings::current();
        $gatewayUrl = is_string($settings->gateway_url) ? rtrim($settings->gateway_url, '/') : '';

        if ($gatewayUrl === '') {
            return $this->unavailable();
        }

        try {
            $response = ($this->client ?? new Client)->request('POST', "{$gatewayUrl}/api/workspaces/setup/stream", [
                'allow_redirects' => false,
                'connect_timeout' => 10,
                'headers' => $this->headers(),
                'http_errors' => false,
                'json' => array_filter([
                    'name' => $name,
                    'app' => $app,
                    'path' => $path,
                ], static fn (mixed $value): bool => $value !== null && $value !== ''),
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

        return $this->consumeEvents($response, $onEvent);
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        $headers = [
            'Accept' => 'text/event-stream',
            'X-Orbit-Client' => 'cli',
        ];

        $correlation = app(ActivityLogCorrelation::class)->current();

        if (is_string($correlation) && $correlation !== '') {
            $headers['X-Orbit-Request-Id'] = $correlation;
        }

        return $headers;
    }

    /**
     * @param  callable(string, array<string, mixed>): void  $onEvent
     */
    private function consumeEvents(ResponseInterface $response, callable $onEvent): int
    {
        $body = $response->getBody();
        $buffer = '';
        $exitCode = 1;

        while (! $body->eof()) {
            $chunk = $body->read(1);

            if ($chunk === '') {
                usleep(50_000);

                continue;
            }

            $buffer .= str_replace("\r\n", "\n", $chunk);

            while (($position = strpos($buffer, "\n\n")) !== false) {
                $frame = substr($buffer, 0, $position);
                $buffer = substr($buffer, $position + 2);
                $event = $this->parseFrame($frame);

                if ($event === null) {
                    continue;
                }

                [$name, $payload] = $event;
                $onEvent($name, $payload);

                if (in_array($name, ['complete', 'error'], true)) {
                    return (int) ($payload['exit_code'] ?? $exitCode);
                }
            }
        }

        return $exitCode;
    }

    /**
     * @return array{string, array<string, mixed>}|null
     */
    private function parseFrame(string $frame): ?array
    {
        $event = 'message';
        $data = [];

        foreach (explode("\n", $frame) as $line) {
            if ($line === '' || str_starts_with($line, ':')) {
                continue;
            }

            if (str_starts_with($line, 'event:')) {
                $event = trim(substr($line, 6));

                continue;
            }

            if (str_starts_with($line, 'data:')) {
                $data[] = ltrim(substr($line, 5));
            }
        }

        if ($data === []) {
            return null;
        }

        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode(implode("\n", $data), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return [$event, $payload];
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
                    message: is_string($error['message'] ?? null) ? $error['message'] : 'Gateway connection is required to set up a workspace.',
                    errorCode: is_string($error['code'] ?? null) ? $error['code'] : null,
                    errorMeta: is_array($error['meta'] ?? null) ? $error['meta'] : [],
                    errorData: is_array($error['data'] ?? null) ? $error['data'] : [],
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
        $message = 'Gateway connection is required to set up a workspace.';

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
