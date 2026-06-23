<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel;

use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Saloon\Http\Response;
use Throwable;

final readonly class GatewayStreamTransport
{
    public function __construct(
        private GatewayConnector $connector,
    ) {}

    /**
     * @param  callable(string, array<string, mixed>): void  $onEvent
     */
    public function events(GatewayStreamRequest $request, callable $onEvent, string $unavailableMessage, int $defaultExitCode = 1, bool $requireTerminalFrame = false): int|GatewayApiException
    {
        $response = $this->send($request, $unavailableMessage);

        if ($response instanceof GatewayApiException) {
            return $response;
        }

        try {
            return $this->consumeEvents($response->stream(), $onEvent, $defaultExitCode, $requireTerminalFrame);
        } catch (GatewayApiException $exception) {
            return $exception;
        }
    }

    /**
     * @param  callable(string): void  $onOutput
     */
    public function text(GatewayStreamRequest $request, callable $onOutput, string $unavailableMessage): int|GatewayApiException
    {
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
    private function consumeEvents(StreamInterface $body, callable $onEvent, int $defaultExitCode, bool $requireTerminalFrame): int
    {
        $buffer = '';
        $exitCode = $defaultExitCode;

        while (! $body->eof()) {
            try {
                $chunk = $body->read(1);
            } catch (RuntimeException $exception) {
                throw new GatewayApiException(
                    message: 'Gateway stream closed before a terminal frame.',
                    errorCode: 'stream_closed_before_terminal',
                    errorMeta: [],
                    previous: $exception,
                );
            }

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

    private function unavailable(string $message, ?string $reason = null, ?Throwable $previous = null): GatewayApiException
    {
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
