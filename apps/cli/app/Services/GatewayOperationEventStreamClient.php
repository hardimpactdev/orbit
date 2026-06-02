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
    public function replay(string $eventsUrl, ?int $lastEventId, callable $onEvent): ?array
    {
        try {
            $pending = Http::baseUrl($this->normalizedBaseUrl())
                ->withHeaders($this->headers($lastEventId))
                ->timeout($this->timeout)
                ->withOptions($this->streamOptions());

            $response = $pending->get('/'.ltrim($eventsUrl, '/'));
        } catch (ConnectionException $exception) {
            throw $this->classifyNetworkError($exception);
        }

        if ($response->failed()) {
            throw GatewayApiException::httpError($response->status(), $response->body());
        }

        return $this->processResponseStream($response->toPsrResponse()->getBody(), $onEvent);
    }

    /**
     * @param  callable(ProgressEventType, array<string, mixed>, int|null): void  $onEvent
     * @return array{type: ProgressEventType, payload: array<string, mixed>}|null
     */
    private function processResponseStream(StreamInterface $stream, callable $onEvent): ?array
    {
        $decoder = new ProgressEventDecoder;
        $frameBuffer = '';

        while (! $stream->eof()) {
            $chunk = $stream->read(self::ReadBytes);

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
    private function processCompleteFrames(ProgressEventDecoder $decoder, string &$frameBuffer, callable $onEvent): ?array
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
    private function headers(?int $lastEventId): array
    {
        $headers = ['Accept' => 'text/event-stream'];

        if ($lastEventId !== null && $lastEventId > 0) {
            $headers['Last-Event-ID'] = (string) $lastEventId;
        }

        return $headers;
    }

    /**
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
