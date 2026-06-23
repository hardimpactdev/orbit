<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\GatewayApiException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use Orbit\Core\Progress\ForkedFrameTicker;
use Orbit\Core\Progress\ProgressEvent;
use Orbit\Core\Progress\ProgressEventDecoder;
use Orbit\Core\Progress\ProgressEventDecodingFailed;
use Orbit\Core\Progress\ProgressEventType;
use Orbit\Core\Progress\StreamIdleReader;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

/**
 * Minimal SSE client for consuming gateway progress streams.
 *
 * Per D10: streaming commands POST with Accept: text/event-stream and read frames
 * line-by-line. Each decoded frame is dispatched to the $onEvent callback.
 */
final readonly class GatewayStreamClient
{
    private const int READ_BYTES = 1;

    private const int ERROR_BODY_TAIL_BYTES = 65536;

    public function __construct(
        private ?string $baseUrl,
        private int $timeout,
        private ?string $caPemPath = null,
        ClientInterface|bool|null $httpClient = null,
        bool $preferCurl = false,
    ) {
        if (is_bool($httpClient)) {
            $preferCurl = $httpClient;
            $httpClient = null;
        }

        $this->preferCurl = $preferCurl;
        $this->httpClient = $httpClient;
    }

    private ?ClientInterface $httpClient;

    private bool $preferCurl;

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
     * @param  callable(): void|null  $onIdle
     */
    public function streamEvents(
        string $path,
        array $payload,
        callable $onEvent,
        string $method = 'post',
        ?callable $onIdle = null,
        int $idleIntervalMicroseconds = ForkedFrameTicker::DEFAULT_INTERVAL_MICROSECONDS,
    ): int {
        if ($this->preferCurl && function_exists('curl_init') && function_exists('curl_multi_init')) {
            return ForkedFrameTicker::withoutForking(
                fn (): int => $this->streamEventsWithCurl($path, $payload, $onEvent, $method, $onIdle, $idleIntervalMicroseconds),
            );
        }

        return $this->streamEventsWithHttp($path, $payload, $onEvent, $method, $onIdle, $idleIntervalMicroseconds);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  callable(ProgressEventType, array<string, mixed>): void  $onEvent
     */
    private function streamEventsWithHttp(
        string $path,
        array $payload,
        callable $onEvent,
        string $method,
        ?callable $onIdle,
        int $idleIntervalMicroseconds,
    ): int {
        $baseUrl = $this->normalizedBaseUrl();
        $normalizedPath = '/'.ltrim($path, '/');

        try {
            $response = $this->client()->request(strtoupper($method), $baseUrl.$normalizedPath, [
                'headers' => ['Accept' => 'text/event-stream'],
                'json' => $payload,
                ...$this->streamOptions(),
            ]);
        } catch (ConnectException $exception) {
            throw $this->classifyNetworkError($exception);
        } catch (GuzzleException $exception) {
            throw GatewayApiException::networkError($exception);
        }

        if ($response->getStatusCode() >= 400) {
            throw GatewayApiException::httpError($response->getStatusCode(), (string) $response->getBody());
        }

        return $this->processResponseStream($response->getBody(), $onEvent, $onIdle, $idleIntervalMicroseconds);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  callable(ProgressEventType, array<string, mixed>): void  $onEvent
     */
    private function streamEventsWithCurl(
        string $path,
        array $payload,
        callable $onEvent,
        string $method,
        ?callable $onIdle,
        int $idleIntervalMicroseconds,
    ): int {
        $curl = curl_init($this->absoluteUrl($path));

        if ($curl === false) {
            throw $this->classifyNetworkError(new RuntimeException('Unable to initialize gateway progress stream.'));
        }

        $multi = curl_multi_init();

        $decoder = new ProgressEventDecoder;
        $frameBuffer = '';
        $bodyTail = '';
        $terminal = null;
        $failure = null;
        $statusCode = 0;
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        curl_setopt_array($curl, [
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_HEADER => false,
            CURLOPT_HEADERFUNCTION => function ($curl, string $header) use (&$statusCode): int {
                if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', trim($header), $matches) === 1) {
                    $statusCode = (int) $matches[1];
                }

                return strlen($header);
            },
            CURLOPT_HTTPHEADER => [
                'Accept: text/event-stream',
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (
                $decoder,
                &$frameBuffer,
                &$bodyTail,
                &$terminal,
                &$failure,
                &$statusCode,
                $onEvent,
            ): int {
                $bodyTail = $this->appendBodyTail($bodyTail, $chunk);

                if ($statusCode >= 400) {
                    return strlen($chunk);
                }

                if ($terminal !== null) {
                    return strlen($chunk);
                }

                try {
                    $frameBuffer .= $chunk;
                    $terminal = $this->processCompleteFrames($decoder, $frameBuffer, $onEvent);
                } catch (Throwable $exception) {
                    $failure = $exception;

                    return 0;
                }

                return strlen($chunk);
            },
        ]);

        if (strtolower($method) === 'delete') {
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
        } else {
            curl_setopt($curl, CURLOPT_POST, true);
        }

        if (is_string($this->caPemPath) && $this->caPemPath !== '' && is_file($this->caPemPath)) {
            curl_setopt($curl, CURLOPT_CAINFO, $this->caPemPath);
        }

        curl_multi_add_handle($multi, $curl);

        try {
            $running = null;

            do {
                do {
                    $multiStatus = curl_multi_exec($multi, $running);
                } while ($multiStatus === CURLM_CALL_MULTI_PERFORM);

                if ($multiStatus !== CURLM_OK) {
                    throw $this->classifyNetworkError(new RuntimeException(curl_multi_strerror($multiStatus)));
                }

                if ($failure instanceof Throwable || $terminal !== null) {
                    break;
                }

                if ($running > 0) {
                    $this->invokeIdle($onIdle);

                    if (curl_multi_select($multi, 0.0) === -1) {
                        usleep(250);
                    }

                    usleep($idleIntervalMicroseconds);
                }
            } while ($running > 0);

            if ($failure instanceof Throwable) {
                throw $failure;
            }

            $curlErrorNumber = curl_errno($curl);

            if ($curlErrorNumber !== 0) {
                throw $this->classifyNetworkError(new RuntimeException(curl_error($curl)));
            }

            $resolvedStatusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

            if ($resolvedStatusCode > 0) {
                $statusCode = $resolvedStatusCode;
            }

            if ($statusCode >= 400) {
                throw GatewayApiException::httpError($statusCode, $bodyTail);
            }

            if ($terminal !== null) {
                return $terminal;
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
        } finally {
            curl_multi_remove_handle($multi, $curl);
            curl_multi_close($multi);
        }
    }

    /**
     * Process the response body as SSE frames without buffering the full stream.
     *
     * @param  callable(ProgressEventType, array<string, mixed>): void  $onEvent
     * @param  callable(): void|null  $onIdle
     */
    private function processResponseStream(
        StreamInterface $stream,
        callable $onEvent,
        ?callable $onIdle,
        int $idleIntervalMicroseconds,
    ): int {
        $decoder = new ProgressEventDecoder;
        $frameBuffer = '';
        $reader = new StreamIdleReader($idleIntervalMicroseconds, $onIdle);

        while (! $stream->eof()) {
            try {
                $chunk = $reader->read($stream, self::READ_BYTES);
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

    private function invokeIdle(?callable $onIdle): void
    {
        if ($onIdle !== null) {
            $onIdle();

            return;
        }

        ForkedFrameTicker::invokeIdleCallback();
    }

    private function normalizedBaseUrl(): string
    {
        $baseUrl = is_string($this->baseUrl) ? trim($this->baseUrl) : '';

        if ($baseUrl === '') {
            throw new GatewayApiException('Gateway URL is not configured.');
        }

        return rtrim($baseUrl, '/');
    }

    private function appendBodyTail(string $tail, string $chunk): string
    {
        if (strlen($chunk) >= self::ERROR_BODY_TAIL_BYTES) {
            return substr($chunk, -self::ERROR_BODY_TAIL_BYTES);
        }

        $tail .= $chunk;

        if (strlen($tail) <= self::ERROR_BODY_TAIL_BYTES) {
            return $tail;
        }

        return substr($tail, -self::ERROR_BODY_TAIL_BYTES);
    }

    private function absoluteUrl(string $path): string
    {
        return $this->normalizedBaseUrl().'/'.ltrim($path, '/');
    }

    /**
     * Build the HTTP client options for the streaming request. The stream option is always set;
     * read_timeout is disabled so long idle periods between SSE frames do not trip PHP's default
     * socket read timeout. When a gateway CA PEM exists on disk, verify is added so the
     * gateway's private CA is trusted (mirroring VerifyGatewayIdentity). Without a CA path,
     * default verification is kept.
     *
     * @return array<string, mixed>
     */
    private function streamOptions(): array
    {
        $options = [
            'stream' => true,
            'http_errors' => false,
            'connect_timeout' => $this->timeout,
            'timeout' => 0,
            'read_timeout' => 0,
        ];

        if (is_string($this->caPemPath) && $this->caPemPath !== '' && is_file($this->caPemPath)) {
            $options['verify'] = $this->caPemPath;
        }

        return $options;
    }

    private function client(): ClientInterface
    {
        return $this->httpClient ?? new Client;
    }

    private function classifyNetworkError(Throwable $exception): GatewayApiException
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
