<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use JsonException;

final class SoloUpstreamRequestCommand extends InternalExecutorCommand
{
    private const array Methods = ['DELETE', 'GET', 'PATCH', 'POST', 'PUT'];

    #[\Override]
    protected $signature = 'internal:solo-upstream-request {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Proxy a local Solo HTTP request through the target node HTTP client';

    public function handle(): int
    {
        if (! $this->verifyOperationToken('internal:solo-upstream-request')) {
            return self::FAILURE;
        }

        try {
            $payload = $this->readPayload();
            $method = $this->method($payload['method'] ?? null);
            $url = $this->url($payload['url'] ?? null);
            $headers = $this->headers($payload['headers'] ?? []);
            $body = $this->body($payload['body'] ?? []);
        } catch (InvalidArgumentException|JsonException) {
            return $this->renderFailure('validation_failed', 'Solo upstream request payload is invalid.', []);
        }

        try {
            $pending = Http::timeout(5)
                ->connectTimeout(2)
                ->withHeaders($headers);

            $response = $body === []
                ? $pending->send($method, $url)
                : $pending->withBody(json_encode($body, JSON_THROW_ON_ERROR), 'application/json')->send($method, $url);
        } catch (ConnectionException) {
            return $this->renderFailure('solo_upstream_unavailable', 'Solo upstream HTTP request failed.', []);
        }

        return $this->emitInternalSuccess([
            'status' => $response->status(),
            'body_base64' => base64_encode($response->body()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function readPayload(): array
    {
        $stdin = $this->stdin();

        if ($stdin === '') {
            throw new InvalidArgumentException('Solo upstream payload is required.');
        }

        /** @var mixed $payload */
        $payload = json_decode($stdin, associative: true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($payload)) {
            throw new InvalidArgumentException('Solo upstream payload must be an object.');
        }

        foreach (array_keys($payload) as $key) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Solo upstream payload keys must be strings.');
            }
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    private function method(mixed $value): string
    {
        $method = is_string($value) ? strtoupper($value) : '';

        if (in_array($method, self::Methods, true)) {
            return $method;
        }

        throw new InvalidArgumentException('Solo upstream method is invalid.');
    }

    private function url(mixed $value): string
    {
        if (
            is_string($value)
            && ! str_contains($value, "\0")
            && preg_match('#\Ahttps?://#', $value) === 1
        ) {
            return $value;
        }

        throw new InvalidArgumentException('Solo upstream URL is invalid.');
    }

    /**
     * @return array<string, string>
     */
    private function headers(mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('Solo upstream headers are invalid.');
        }

        $headers = [];

        foreach (array_keys($value) as $key) {
            if (! is_string($key) || str_contains($key, "\0")) {
                throw new InvalidArgumentException('Solo upstream headers are invalid.');
            }

            $headers[$key] = $this->headerValue($value[$key] ?? null);
        }

        return $headers;
    }

    private function headerValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        throw new InvalidArgumentException('Solo upstream headers are invalid.');
    }

    /**
     * @return array<string, mixed>
     */
    private function body(mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('Solo upstream body is invalid.');
        }

        foreach (array_keys($value) as $key) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Solo upstream body keys must be strings.');
            }
        }

        /** @var array<string, mixed> $value */
        return $value;
    }
}
