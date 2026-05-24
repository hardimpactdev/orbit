<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\GatewayApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final readonly class GatewayApiClient
{
    public function __construct(
        private ?string $baseUrl,
        private ?string $identity,
        private int $timeout,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->decode(
            $this->request(fn () => $this->pendingRequest()->get($this->path($path), $query)),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload = []): array
    {
        return $this->decode(
            $this->request(fn () => $this->pendingRequest()->post($this->path($path), $payload)),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  callable(string, array<string, mixed>): void  $onEvent
     */
    public function streamEvents(string $path, array $payload, callable $onEvent): int
    {
        throw new GatewayApiException('Gateway streaming requests are not implemented yet.');
    }

    private function pendingRequest(): PendingRequest
    {
        $baseUrl = $this->normalizedBaseUrl();
        $request = Http::baseUrl($baseUrl)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout);

        $identity = $this->normalizedIdentity();

        if ($identity === null) {
            return $request;
        }

        return $request->withToken($identity);
    }

    private function normalizedBaseUrl(): string
    {
        $baseUrl = is_string($this->baseUrl) ? trim($this->baseUrl) : '';

        if ($baseUrl === '') {
            throw new GatewayApiException('Gateway URL is not configured.');
        }

        return rtrim($baseUrl, '/');
    }

    private function normalizedIdentity(): ?string
    {
        $identity = is_string($this->identity) ? trim($this->identity) : '';

        return $identity === '' ? null : $identity;
    }

    private function path(string $path): string
    {
        return '/'.ltrim($path, '/');
    }

    /**
     * @param  callable(): Response  $callback
     */
    private function request(callable $callback): Response
    {
        try {
            $response = $callback();
        } catch (ConnectionException $exception) {
            throw GatewayApiException::networkError($exception);
        }

        if ($response->failed()) {
            throw GatewayApiException::httpError($response->status(), $response->body());
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw new GatewayApiException('Gateway response is not valid JSON.');
        }

        return $decoded;
    }
}
