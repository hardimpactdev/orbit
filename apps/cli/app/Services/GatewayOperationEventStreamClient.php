<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\GatewayApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Orbit\Core\Progress\ForkedFrameTicker;
use Orbit\Core\Progress\ProgressEvent;
use Orbit\Core\Progress\ProgressEventDecoder;
use Orbit\Core\Progress\ProgressEventDecodingFailed;
use Orbit\Core\Progress\ProgressEventType;
use Orbit\Core\Progress\StreamIdleReader;
use Psr\Http\Message\StreamInterface;
use Throwable;

class GatewayOperationEventStreamClient
{
    private const int ReadBytes = 8192;

    public function __construct(
        private readonly ?string $baseUrl,
        private readonly int $timeout,
        private readonly ?string $caPemPath = null,
    ) {}

    /**
     * @param  callable(ProgressEventType, array<string, mixed>, int|null): void  $onEvent
     * @return array{type: ProgressEventType, payload: array<string, mixed>}|null
     */
    public function replay(string $eventsUrl, ?int $lastSequence, callable $onEvent): ?array
    {
        if (ForkedFrameTicker::hasIdleCallback() && function_exists('curl_multi_init')) {
            return $this->replayWithCurlIdleTicks($eventsUrl, $lastSequence, $onEvent);
        }

        try {
            $response = $this->openStreamResponse($eventsUrl, $lastSequence);
        } catch (ConnectionException $exception) {
            throw $this->classifyNetworkError($exception);
        }

        if ($response->failed()) {
            throw GatewayApiException::httpError($response->status(), $response->body());
        }

        return $this->processResponseStream($response->toPsrResponse()->getBody(), $onEvent);
    }

    private function openStreamResponse(string $eventsUrl, ?int $lastSequence): Response
    {
        return $this->pendingRequest($lastSequence)->get('/'.ltrim($eventsUrl, '/'));
    }

    private function pendingRequest(?int $lastSequence): PendingRequest
    {
        return Http::baseUrl($this->normalizedBaseUrl())
            ->withHeaders($this->headers($lastSequence))
            ->timeout($this->timeout)
            ->withOptions($this->streamOptions());
    }

    /**
     * @param  callable(ProgressEventType, array<string, mixed>, int|null): void  $onEvent
     * @return array{type: ProgressEventType, payload: array<string, mixed>}|null
     */
    private function replayWithCurlIdleTicks(string $eventsUrl, ?int $lastSequence, callable $onEvent): ?array
    {
        $curl = curl_init($this->absoluteUrl($eventsUrl));

        if ($curl === false) {
            throw $this->classifyNetworkError(new ConnectionException('Unable to initialize operation event stream.'));
        }

        $multi = curl_multi_init();

        $decoder = new ProgressEventDecoder;
        $frameBuffer = '';
        $bodyBuffer = '';
        $terminal = null;
        $failure = null;

        curl_setopt_array($curl, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => $this->curlHeaders($lastSequence),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_HEADER => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (
                $decoder,
                &$frameBuffer,
                &$bodyBuffer,
                &$terminal,
                &$failure,
                $onEvent,
            ): int {
                $bodyBuffer .= $chunk;

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
                    throw $this->classifyNetworkError(new ConnectionException(curl_multi_strerror($multiStatus)));
                }

                if ($failure instanceof Throwable || $terminal !== null) {
                    break;
                }

                if ($running > 0) {
                    ForkedFrameTicker::invokeIdleCallback();

                    if (curl_multi_select($multi, 0.0) === -1) {
                        usleep(250);
                    }

                    usleep(ForkedFrameTicker::idleIntervalMicroseconds());
                }
            } while ($running > 0);

            if ($failure instanceof Throwable) {
                throw $failure;
            }

            if ($terminal !== null) {
                return $terminal;
            }

            $curlErrorNumber = curl_errno($curl);

            if ($curlErrorNumber !== 0) {
                throw $this->classifyNetworkError(new ConnectionException(curl_error($curl)));
            }

            $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

            if ($statusCode >= 400) {
                throw GatewayApiException::httpError($statusCode, $bodyBuffer);
            }

            $rawFrame = trim($frameBuffer);

            if ($rawFrame === '') {
                return null;
            }

            $event = $this->decodeFrame($decoder, $rawFrame);

            if ($event === null) {
                return null;
            }

            return $this->dispatchEvent($event, $this->eventId($rawFrame), $onEvent);
        } finally {
            curl_multi_remove_handle($multi, $curl);
            curl_multi_close($multi);
        }
    }

    private function absoluteUrl(string $eventsUrl): string
    {
        return $this->normalizedBaseUrl().'/'.ltrim($eventsUrl, '/');
    }

    /**
     * @return list<string>
     */
    private function curlHeaders(?int $lastSequence): array
    {
        $headers = [];

        foreach ($this->headers($lastSequence) as $name => $value) {
            $headers[] = "{$name}: {$value}";
        }

        return $headers;
    }

    /**
     * @param  callable(ProgressEventType, array<string, mixed>, int|null): void  $onEvent
     * @return array{type: ProgressEventType, payload: array<string, mixed>}|null
     */
    private function processResponseStream(StreamInterface $stream, callable $onEvent): ?array
    {
        $decoder = new ProgressEventDecoder;
        $frameBuffer = '';
        $reader = new StreamIdleReader(ForkedFrameTicker::idleIntervalMicroseconds());

        while (! $stream->eof()) {
            $chunk = $reader->read($stream, self::ReadBytes);

            if ($chunk === '') {
                continue;
            }

            $frameBuffer .= $chunk;
            $terminal = $this->processCompleteFrames($decoder, $frameBuffer, $onEvent);

            if ($terminal !== null) {
                return $terminal;
            }
        }

        $rawFrame = trim($frameBuffer);

        if ($rawFrame === '') {
            return null;
        }

        $event = $this->decodeFrame($decoder, $rawFrame);

        if ($event === null) {
            return null;
        }

        return $this->dispatchEvent($event, $this->eventId($rawFrame), $onEvent);
    }

    /**
     * @param  callable(ProgressEventType, array<string, mixed>, int|null): void  $onEvent
     * @return array{type: ProgressEventType, payload: array<string, mixed>}|null
     */
    private function processCompleteFrames(
        ProgressEventDecoder $decoder,
        string &$frameBuffer,
        callable $onEvent,
    ): ?array {
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

            $terminal = $this->dispatchEvent($event, $this->eventId($rawFrame), $onEvent);

            if ($terminal !== null) {
                return $terminal;
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
     * @param  callable(ProgressEventType, array<string, mixed>, int|null): void  $onEvent
     * @return array{type: ProgressEventType, payload: array<string, mixed>}|null
     */
    private function dispatchEvent(ProgressEvent $event, ?int $eventId, callable $onEvent): ?array
    {
        $onEvent($event->type, $event->payload, $eventId);

        if ($event->type === ProgressEventType::Complete || $event->type === ProgressEventType::Error) {
            return [
                'type' => $event->type,
                'payload' => $event->payload,
            ];
        }

        return null;
    }

    private function eventId(string $rawFrame): ?int
    {
        foreach (explode("\n", $rawFrame) as $line) {
            $line = rtrim($line, "\r");

            if (! str_starts_with($line, 'id:')) {
                continue;
            }

            $value = trim(substr($line, 3));

            if ($value === '' || ! ctype_digit($value)) {
                return null;
            }

            $id = (int) $value;

            return $id > 0 ? $id : null;
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
     * @return array<string, string>
     */
    private function headers(?int $lastSequence): array
    {
        $headers = ['Accept' => 'text/event-stream'];

        if ($lastSequence !== null && $lastSequence > 0) {
            $headers['Last-Event-ID'] = (string) $lastSequence;
        }

        return $headers;
    }

    /**
     * @return array<string, mixed>
     */
    private function streamOptions(): array
    {
        $options = [
            'stream' => true,
            'read_timeout' => 0,
        ];

        if (is_string($this->caPemPath) && $this->caPemPath !== '' && is_file($this->caPemPath)) {
            $options['verify'] = $this->caPemPath;
        }

        return $options;
    }

    private function classifyNetworkError(ConnectionException $exception): GatewayApiException
    {
        $message = strtolower($exception->getMessage());

        $isWireGuardReachabilityFailure =
            str_contains($message, 'timed out')
            || str_contains($message, 'no route to host')
            || str_contains($message, 'network is unreachable')
            || str_contains($message, 'could not resolve host');

        if ($isWireGuardReachabilityFailure) {
            return GatewayApiException::wireguardUnreachable($exception);
        }

        return GatewayApiException::networkError($exception);
    }

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
