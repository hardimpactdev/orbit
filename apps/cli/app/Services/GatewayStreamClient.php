<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\GatewayApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Orbit\Core\Progress\ProgressEventDecoder;
use Orbit\Core\Progress\ProgressEventDecodingFailed;
use Orbit\Core\Progress\ProgressEventType;
use RuntimeException;

/**
 * Minimal SSE client for consuming gateway progress streams.
 *
 * Per D10: streaming commands POST with Accept: text/event-stream and read frames
 * line-by-line. Each decoded frame is dispatched to the $onEvent callback.
 */
final readonly class GatewayStreamClient
{
    public function __construct(
        private ?string $baseUrl,
        private int $timeout,
    ) {}

    /**
     * Stream progress events from the gateway. POSTs $payload to $path with
     * Accept: text/event-stream, reads SSE frames, and calls
     * $onEvent($type, $payload) for each decoded frame.
     *
     * Returns 0 on a `complete` frame, non-zero on `error`. Throws
     * GatewayApiException when the stream closes before either terminal frame.
     *
     * @param  array<string, mixed>  $payload
     * @param  callable(ProgressEventType, array<string, mixed>): void  $onEvent
     */
    public function streamEvents(string $path, array $payload, callable $onEvent): int
    {
        $baseUrl = $this->normalizedBaseUrl();
        $url = $baseUrl.'/'.ltrim($path, '/');

        try {
            $response = Http::baseUrl($baseUrl)
                ->withHeaders(['Accept' => 'text/event-stream'])
                ->asJson()
                ->timeout($this->timeout)
                ->withOptions(['stream' => true])
                ->post($url, $payload);
        } catch (ConnectionException $exception) {
            throw $this->classifyNetworkError($exception);
        }

        if ($response->failed()) {
            throw GatewayApiException::httpError($response->status(), $response->body());
        }

        return $this->processResponseBody($response->body(), $onEvent);
    }

    /**
     * Process the response body as SSE frames.
     *
     * @param  callable(ProgressEventType, array<string, mixed>): void  $onEvent
     */
    private function processResponseBody(string $body, callable $onEvent): int
    {
        $decoder = new ProgressEventDecoder;
        $frameBuffer = $body;

        while (($pos = $this->findFrameEnd($frameBuffer)) !== false) {
            $rawFrame = substr($frameBuffer, 0, $pos);
            $frameBuffer = ltrim(substr($frameBuffer, $pos), "\r\n");

            if (trim($rawFrame) === '') {
                continue;
            }

            try {
                $event = $decoder->decode($rawFrame);
            } catch (ProgressEventDecodingFailed) {
                continue;
            }

            if ($event === null) {
                continue;
            }

            $onEvent($event->type, $event->payload);

            if ($event->type === ProgressEventType::Complete) {
                return 0;
            }

            if ($event->type === ProgressEventType::Error) {
                return 1;
            }
        }

        // Handle remaining buffer (no trailing blank line).
        $rawFrame = trim($frameBuffer);

        if ($rawFrame !== '') {
            try {
                $event = $decoder->decode($rawFrame);

                if ($event !== null) {
                    $onEvent($event->type, $event->payload);

                    if ($event->type === ProgressEventType::Complete) {
                        return 0;
                    }

                    if ($event->type === ProgressEventType::Error) {
                        return 1;
                    }
                }
            } catch (ProgressEventDecodingFailed) {
                // malformed trailing frame — fall through to throw below
            }
        }

        // Stream closed before a complete/error terminal frame.
        throw GatewayApiException::streamClosedBeforeTerminal(
            new RuntimeException('SSE stream closed before a terminal frame was received.'),
        );
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

    /**
     * Find the end position of the first complete SSE frame (terminated by a blank line).
     * Returns false if no complete frame boundary is found yet.
     */
    private function findFrameEnd(string $buffer): int|false
    {
        $pos = strpos($buffer, "\n\n");

        if ($pos !== false) {
            return $pos + 2;
        }

        $pos = strpos($buffer, "\r\n\r\n");

        if ($pos !== false) {
            return $pos + 4;
        }

        return false;
    }
}
