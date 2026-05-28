<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\GatewayApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;

final readonly class GatewayLogStreamClient
{
    private const int READ_BYTES = 8192;

    public function __construct(
        private ?string $baseUrl,
        private int $timeout,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     * @param  callable(string): void  $onOutput
     */
    public function streamText(string $path, array $query, callable $onOutput): int
    {
        $baseUrl = $this->normalizedBaseUrl();

        try {
            $response = Http::baseUrl($baseUrl)
                ->withHeaders(['Accept' => 'text/plain'])
                ->timeout($this->timeout)
                ->withOptions(['stream' => true])
                ->get('/'.ltrim($path, '/'), $query);
        } catch (ConnectionException $exception) {
            throw $this->classifyNetworkError($exception);
        }

        if ($response->failed()) {
            throw GatewayApiException::httpError($response->status(), $response->body());
        }

        $this->processResponseStream($response->toPsrResponse()->getBody(), $onOutput);

        return 0;
    }

    /**
     * @param  callable(string): void  $onOutput
     */
    private function processResponseStream(StreamInterface $stream, callable $onOutput): void
    {
        while (! $stream->eof()) {
            $chunk = $stream->read(self::READ_BYTES);

            if ($chunk === '') {
                continue;
            }

            $onOutput($chunk);
        }
    }

    private function normalizedBaseUrl(): string
    {
        $baseUrl = is_string($this->baseUrl) ? trim($this->baseUrl) : '';

        if ($baseUrl === '') {
            throw new GatewayApiException('Gateway URL is not configured.');
        }

        return rtrim($baseUrl, '/');
    }

    private function classifyNetworkError(ConnectionException $exception): GatewayApiException
    {
        $message = strtolower($exception->getMessage());

        $isWireGuardReachabilityFailure = str_contains($message, 'timed out')
            || str_contains($message, 'no route to host')
            || str_contains($message, 'network is unreachable')
            || str_contains($message, 'could not resolve host');

        if ($isWireGuardReachabilityFailure) {
            return GatewayApiException::wireguardUnreachable($exception);
        }

        return GatewayApiException::networkError($exception);
    }
}
