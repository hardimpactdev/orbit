<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel;

use Orbit\Sdk\Laravel\Requests\GenericGatewayStreamRequest;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Saloon\Http\Response;
use Throwable;

final readonly class GatewayStreamTransport
{
    private const int DEFAULT_IDLE_INTERVAL_MICROSECONDS = 300_000;

    private const int ERROR_BODY_TAIL_BYTES = 65536;

    public function __construct(
        private GatewayConnector $connector,
        private bool $preferCurl = false,
    ) {}

    /**
     * @param  callable(string, array<string, mixed>): void  $onEvent
     */
    public function events(
        GatewayStreamRequest $request,
        callable $onEvent,
        string $unavailableMessage,
        int $defaultExitCode = 1,
        bool $requireTerminalFrame = false,
        ?callable $onIdle = null,
        int $idleIntervalMicroseconds = self::DEFAULT_IDLE_INTERVAL_MICROSECONDS,
    ): int|GatewayApiException {
        if (
            $this->preferCurl
            && $request instanceof GenericGatewayStreamRequest
            && function_exists('curl_init')
            && function_exists('curl_multi_init')
        ) {
            try {
                return $this->eventsWithCurl(
                    $request,
                    $onEvent,
                    $defaultExitCode,
                    $requireTerminalFrame,
                    $onIdle,
                    $idleIntervalMicroseconds,
                );
            } catch (GatewayApiException $exception) {
                return $exception;
            }
        }

        $response = $this->send($request, $unavailableMessage);

        if ($response instanceof GatewayApiException) {
            return $response;
        }

        try {
            return $this->consumeEvents(
                $response->stream(),
                $onEvent,
                $defaultExitCode,
                $requireTerminalFrame,
                $onIdle,
                $idleIntervalMicroseconds,
            );
        } catch (GatewayApiException $exception) {
            return $exception;
        }
    }

    /**
     * @param  callable(string): void  $onOutput
     */
    public function text(
        GatewayStreamRequest $request,
        callable $onOutput,
        string $unavailableMessage,
    ): int|GatewayApiException {
        $response = $this->send($request, $unavailableMessage);

        if ($response instanceof GatewayApiException) {
            return $response;
        }

        $body = $response->stream();

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

    private function send(GatewayStreamRequest $request, string $unavailableMessage): Response|GatewayApiException
    {
        $gatewayUrl = $this->connector->resolveBaseUrl();

        if (trim($gatewayUrl) === '') {
            return $this->unavailable($unavailableMessage);
        }

        try {
            return $this->connector->send($request);
        } catch (GatewayApiException $exception) {
            return $exception;
        } catch (Throwable $e) {
            return $this->unavailable($unavailableMessage, trim(get_debug_type($e).' '.$e->getMessage()), $e);
        }
    }

    /**
     * @param  callable(string, array<string, mixed>): void  $onEvent
     */
    private function consumeEvents(
        StreamInterface $body,
        callable $onEvent,
        int $defaultExitCode,
        bool $requireTerminalFrame,
        ?callable $onIdle,
        int $idleIntervalMicroseconds,
    ): int {
        $buffer = '';
        $exitCode = $defaultExitCode;

        while (! $body->eof()) {
            try {
                $chunk = $this->readStream($body, 1, $onIdle, $idleIntervalMicroseconds);
            } catch (RuntimeException $exception) {
                throw new GatewayApiException(
                    message: 'Gateway stream closed before a terminal frame.',
                    errorCode: 'stream_closed_before_terminal',
                    errorMeta: [],
                    previous: $exception,
                );
            }

            if ($chunk === '') {
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

                $terminalExitCode = $this->dispatchEvent($event, $onEvent, $exitCode);

                if ($terminalExitCode !== null) {
                    return $terminalExitCode;
                }
            }
        }

        if (trim($buffer) !== '') {
            $event = $this->parseFrame($buffer);

            if ($event !== null) {
                $terminalExitCode = $this->dispatchEvent($event, $onEvent, $exitCode);

                if ($terminalExitCode !== null) {
                    return $terminalExitCode;
                }
            }
        }

        if ($requireTerminalFrame) {
            throw new GatewayApiException(
                message: 'Gateway stream closed before a terminal frame.',
                errorCode: 'stream_closed_before_terminal',
                errorMeta: [],
            );
        }

        return $exitCode;
    }

    /**
     * @param  callable(string, array<string, mixed>): void  $onEvent
     */
    private function eventsWithCurl(
        GenericGatewayStreamRequest $request,
        callable $onEvent,
        int $defaultExitCode,
        bool $requireTerminalFrame,
        ?callable $onIdle,
        int $idleIntervalMicroseconds,
    ): int {
        $curl = curl_init($this->absoluteUrl($request));

        if ($curl === false) {
            throw $this->unavailable('Unable to initialize gateway progress stream.');
        }

        $multi = curl_multi_init();

        $frameBuffer = '';
        $bodyTail = '';
        $terminal = null;
        $failure = null;
        $statusCode = 0;
        $body = json_encode($request->payload(), JSON_THROW_ON_ERROR);

        curl_setopt_array($curl, [
            CURLOPT_CONNECTTIMEOUT => $this->connector->timeout(),
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
                ...$this->curlNodeTransportHeaders(),
            ],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (
                &$frameBuffer,
                &$bodyTail,
                &$terminal,
                &$failure,
                &$statusCode,
                $onEvent,
                $defaultExitCode,
            ): int {
                $bodyTail = $this->appendBodyTail($bodyTail, $chunk);

                if ($statusCode >= 400 || $terminal !== null) {
                    return strlen($chunk);
                }

                try {
                    $frameBuffer .= $chunk;
                    $terminal = $this->processCompleteFrames($frameBuffer, $onEvent, $defaultExitCode);
                } catch (Throwable $exception) {
                    $failure = $exception;

                    return 0;
                }

                return strlen($chunk);
            },
        ]);

        if (strtolower($request->methodName()) === 'delete') {
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
        } else {
            curl_setopt($curl, CURLOPT_POST, true);
        }

        $caPemPath = $this->connector->caPemPath();

        if (is_string($caPemPath) && $caPemPath !== '' && is_file($caPemPath)) {
            curl_setopt($curl, CURLOPT_CAINFO, $caPemPath);
        }

        curl_multi_add_handle($multi, $curl);

        try {
            $running = null;

            do {
                do {
                    $multiStatus = curl_multi_exec($multi, $running);
                } while ($multiStatus === CURLM_CALL_MULTI_PERFORM);

                if ($multiStatus !== CURLM_OK) {
                    throw $this->unavailable(curl_multi_strerror($multiStatus) ?? 'Unknown curl multi error');
                }

                if ($failure instanceof Throwable || $terminal !== null) {
                    break;
                }

                if ($running > 0) {
                    if ($onIdle !== null) {
                        $onIdle();
                    }

                    if (curl_multi_select($multi, 0.0) === -1) {
                        usleep(250);
                    }

                    usleep(max(0, $idleIntervalMicroseconds));
                }
            } while ($running > 0);

            if ($failure instanceof Throwable) {
                throw $failure;
            }

            $curlErrorNumber = curl_errno($curl);

            if ($curlErrorNumber !== 0) {
                throw $this->unavailable(curl_error($curl));
            }

            $resolvedStatusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

            if ($resolvedStatusCode > 0) {
                $statusCode = $resolvedStatusCode;
            }

            if ($statusCode >= 400) {
                throw new GatewayApiException(
                    message: "Gateway stream returned HTTP {$statusCode}.",
                    errorCode: 'http_error',
                    errorMeta: [],
                    errorData: [
                        'status' => $statusCode,
                        'body' => $bodyTail,
                    ],
                );
            }

            if ($terminal !== null) {
                return $terminal;
            }

            $rawFrame = trim($frameBuffer);

            if ($rawFrame !== '') {
                $event = $this->parseFrame($rawFrame);

                if ($event !== null) {
                    $exitCode = $this->dispatchEvent($event, $onEvent, $defaultExitCode);

                    if ($exitCode !== null) {
                        return $exitCode;
                    }
                }
            }

            if ($requireTerminalFrame) {
                throw new GatewayApiException(
                    message: 'Gateway stream closed before a terminal frame.',
                    errorCode: 'stream_closed_before_terminal',
                    errorMeta: [],
                );
            }

            return $defaultExitCode;
        } finally {
            curl_multi_remove_handle($multi, $curl);
            curl_multi_close($multi);
        }
    }

    /**
     * @return list<string>
     */
    private function curlNodeTransportHeaders(): array
    {
        return array_map(
            static fn (string $key, string $value): string => "{$key}: {$value}",
            array_keys($this->connector->nodeTransportHeaders()),
            $this->connector->nodeTransportHeaders(),
        );
    }

    /**
     * @param  callable(string, array<string, mixed>): void  $onEvent
     */
    private function processCompleteFrames(string &$frameBuffer, callable $onEvent, int $defaultExitCode): ?int
    {
        while (($position = $this->findFrameEnd($frameBuffer)) !== false) {
            $rawFrame = substr($frameBuffer, 0, $position);
            $frameBuffer = ltrim(substr($frameBuffer, $position), "\r\n");

            if (trim($rawFrame) === '') {
                continue;
            }

            $event = $this->parseFrame($rawFrame);

            if ($event === null) {
                continue;
            }

            $exitCode = $this->dispatchEvent($event, $onEvent, $defaultExitCode);

            if ($exitCode !== null) {
                return $exitCode;
            }
        }

        return null;
    }

    /**
     * @param  array{string, array<string, mixed>}  $event
     * @param  callable(string, array<string, mixed>): void  $onEvent
     */
    private function dispatchEvent(array $event, callable $onEvent, int $defaultExitCode): ?int
    {
        [$name, $payload] = $event;
        $onEvent($name, $payload);

        if ($name === 'complete') {
            return (int) ($payload['exit_code'] ?? 0);
        }

        if ($name === 'error') {
            return (int) ($payload['exit_code'] ?? $defaultExitCode);
        }

        return null;
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
        } catch (\JsonException $exception) {
            throw new GatewayApiException(
                message: 'Gateway stream returned a malformed frame.',
                errorCode: 'stream_malformed',
                errorMeta: [],
                previous: $exception,
            );
        }

        return [$event, $payload];
    }

    private function readStream(
        StreamInterface $body,
        int $maxBytes,
        ?callable $onIdle,
        int $idleIntervalMicroseconds,
    ): string {
        if ($onIdle === null) {
            return $body->read($maxBytes);
        }

        $resource = $body->getMetadata('stream');

        if (! is_resource($resource)) {
            return $this->readStreamWithIdlePolling($body, $maxBytes, $onIdle, $idleIntervalMicroseconds);
        }

        while (! $body->eof()) {
            $read = [$resource];
            $write = null;
            $except = null;
            $seconds = intdiv($idleIntervalMicroseconds, 1_000_000);
            $microseconds = $idleIntervalMicroseconds % 1_000_000;

            set_error_handler(static fn (): bool => true);

            try {
                $ready = stream_select($read, $write, $except, $seconds, $microseconds);
            } catch (\ValueError|\TypeError) {
                return $this->readStreamWithIdlePolling($body, $maxBytes, $onIdle, $idleIntervalMicroseconds);
            } finally {
                restore_error_handler();
            }

            if ($ready === false || $ready === 0) {
                $onIdle();

                continue;
            }

            $selectedResource = $read[0] ?? null;

            if (! is_resource($selectedResource)) {
                $onIdle();

                continue;
            }

            $chunk = $this->readReadyStreamChunk($selectedResource, $maxBytes);

            if ($chunk !== '') {
                return $chunk;
            }

            $onIdle();
        }

        return '';
    }

    private function readReadyStreamChunk(mixed $resource, int $maxBytes): string
    {
        if (! is_resource($resource) || get_resource_type($resource) !== 'stream') {
            return '';
        }

        $metadata = stream_get_meta_data($resource) + ['blocked' => false];
        $restoreBlocking = $metadata['blocked'] === true;

        stream_set_blocking($resource, false);

        try {
            $chunk = fread($resource, max(1, $maxBytes));

            return $chunk === false ? '' : $chunk;
        } finally {
            if ($restoreBlocking && is_resource($resource)) {
                stream_set_blocking($resource, true);
            }
        }
    }

    private function readStreamWithIdlePolling(
        StreamInterface $body,
        int $maxBytes,
        callable $onIdle,
        int $idleIntervalMicroseconds,
    ): string {
        while (! $body->eof()) {
            $chunk = $body->read($maxBytes);

            if ($chunk !== '') {
                return $chunk;
            }

            $onIdle();
            usleep(max(0, $idleIntervalMicroseconds));
        }

        return '';
    }

    private function absoluteUrl(GenericGatewayStreamRequest $request): string
    {
        return rtrim($this->connector->resolveBaseUrl(), '/').'/'.ltrim($request->resolveEndpoint(), '/');
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

    private function findFrameEnd(string $buffer): int|false
    {
        $position = strpos($buffer, "\n\n");

        if ($position !== false) {
            return $position + 2;
        }

        $position = strpos($buffer, "\r\n\r\n");

        if ($position !== false) {
            return $position + 4;
        }

        return false;
    }

    private function unavailable(
        string $message,
        ?string $reason = null,
        ?Throwable $previous = null,
    ): GatewayApiException {
        if (is_string($reason) && trim($reason) !== '') {
            $message .= ' '.trim($reason);
        }

        return new GatewayApiException(
            message: $message,
            errorCode: 'gateway_unavailable',
            errorMeta: [],
            previous: $previous,
        );
    }
}
