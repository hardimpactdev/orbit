<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\GatewayApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Orbit\Core\Progress\ProgressEvent;
use Orbit\Core\Progress\ProgressEventDecoder;
use Orbit\Core\Progress\ProgressEventDecodingFailed;
use Orbit\Core\Progress\ProgressEventType;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Minimal SSE client for consuming gateway progress streams.
 *
 * Per D10: streaming commands POST with Accept: text/event-stream and read frames
 * line-by-line. Each decoded frame is dispatched to the $onEvent callback.
 */
final readonly class GatewayStreamClient
{
    private const int READ_BYTES = 8192;

    public function __construct(
        private ?string $baseUrl,
        private int $timeout,
        private ?string $caPemPath = null,
    ) {}

    /**
     * Stream progress events from the gateway. Sends $payload to $path with
     * Accept: text/event-stream using the specified HTTP method, reads SSE
     * frames, and calls $onEvent($type, $payload) for each decoded frame.
     *
     * Returns 0 on a `complete` frame, non-zero on `error`. Throws
     * GatewayApiException when the stream closes before either terminal frame.
     *
     * @param  array<string, mixed>  $payload
     * @param  callable(ProgressEventType, array<string, mixed>): void  $onEvent
     */
    public function streamEvents(string $path, array $payload, callable $onEvent, string $method = 'post'): int
    {
        $baseUrl = $this->normalizedBaseUrl();
        try {
            $pending = Http::baseUrl($baseUrl)
                ->withHeaders(['Accept' => 'text/event-stream'])
                ->asJson()
                ->connectTimeout($this->timeout)
                ->timeout(0)
                ->withOptions($this->streamOptions());

            $normalizedPath = '/'.ltrim($path, '/');

            $response = match (strtolower($method)) {
                'delete' => $pending->delete($normalizedPath, $payload),
                default => $pending->post($normalizedPath, $payload),
            };
        } catch (ConnectionException $exception) {
            throw $this->classifyNetworkError($exception);
        }

        if ($response->failed()) {
            throw GatewayApiException::httpError($response->status(), $response->body());
        }

        return $this->processResponseStream($response->toPsrResponse()->getBody(), $onEvent);
    }

    /**
     * Process the response body as SSE frames without buffering the full stream.
     *
     * @param  callable(ProgressEventType, array<string, mixed>): void  $onEvent
     */
    private function processResponseStream(StreamInterface $stream, callable $onEvent): int
    {
        $decoder = new ProgressEventDecoder;
        $frameBuffer = '';

        while (! $stream->eof()) {
            try {
                $chunk = $stream->read(self::READ_BYTES);
            } catch (RuntimeException $exception) {
                throw GatewayApiException::streamClosedBeforeTerminal($exception);
            }

            if ($chunk === '') {
                continue;
            }

            $frameBuffer .= $chunk;
            $exitCode = $this->processCompleteFrames($decoder, $frameBuffer, $onEvent);

            if ($exitCode !== null) {
                return $exitCode;
            }
        }

        $rawFrame = trim($frameBuffer);

        if ($rawFrame !== '') {
            $event = $this->decodeFrame($decoder, $rawFrame);

            if ($event !== null) {
                $exitCode = $this->dispatchEvent($event, $onEvent);

                if ($exitCode !== null) {
                    return $exitCode;
                }
            }
        }

        throw GatewayApiException::streamClosedBeforeTerminal(
            new RuntimeException('SSE stream closed before a terminal frame was received.'),
        );
    }

    /**
     * @param  callable(ProgressEventType, array<string, mixed>): void  $onEvent
     */
    private function processCompleteFrames(ProgressEventDecoder $decoder, string &$frameBuffer, callable $onEvent): ?int
    {
        while (($pos = $this->findFrameEnd($frameBuffer)) !== false) {
            $rawFrame = substr($frameBuffer, 0, $pos);
            $frameBuffer = ltrim(substr($frameBuffer, $pos), "\r\n");

            if (trim($rawFrame) === '') {
                continue;
            }

            $event = $this->decodeFrame($decoder, $rawFrame);

            if ($event === null) {
                continue;
            }

            $exitCode = $this->dispatchEvent($event, $onEvent);

            if ($exitCode !== null) {
                return $exitCode;
            }
        }

        return null;
    }

    private function decodeFrame(ProgressEventDecoder $decoder, string $rawFrame): ?ProgressEvent
    {
        try {
            return $decoder->decode($rawFrame);
        } catch (ProgressEventDecodingFailed $exception) {
            throw GatewayApiException::streamMalformed($exception);
        }
    }

    /**
     * @param  callable(ProgressEventType, array<string, mixed>): void  $onEvent
     */
    private function dispatchEvent(ProgressEvent $event, callable $onEvent): ?int
    {
        $onEvent($event->type, $event->payload);

        if ($event->type === ProgressEventType::Complete) {
            return 0;
        }

        if ($event->type === ProgressEventType::Error) {
            return 1;
        }

        return null;
    }

    private function normalizedBaseUrl(): string
    {
        $baseUrl = is_string($this->baseUrl) ? trim($this->baseUrl) : '';

        if ($baseUrl === '') {
            throw new GatewayApiException('Gateway URL is not configured.');
        }

        return rtrim($baseUrl, '/');
    }

    /**
     * Build the HTTP client options for the streaming request. The stream option is always set;
     * when a gateway CA PEM exists on disk, verify is added so the gateway's private CA is
     * trusted (mirroring VerifyGatewayIdentity). Without a CA path, default verification is kept.
     *
     * @return array<string, mixed>
     */
    private function streamOptions(): array
    {
        $options = ['stream' => true];

        if (is_string($this->caPemPath) && $this->caPemPath !== '' && is_file($this->caPemPath)) {
            $options['verify'] = $this->caPemPath;
        }

        return $options;
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
