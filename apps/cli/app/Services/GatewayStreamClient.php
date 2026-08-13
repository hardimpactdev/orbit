<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\GatewayApiException;
use App\Exceptions\GatewayApiFailureKind;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Orbit\Core\Progress\ForkedFrameTicker;
use Orbit\Core\Progress\ProgressEvent;
use Orbit\Core\Progress\ProgressEventType;
use Orbit\Sdk\Laravel\GatewayConnector;
use Orbit\Sdk\Laravel\GatewayStreamTransport;
use Orbit\Sdk\Laravel\Requests\GenericGatewayStreamRequest;
use Orbit\Sdk\Laravel\Testing\GatewayMockClient;
use Throwable;
use ValueError;

/**
 * Minimal SSE client for consuming gateway progress streams.
 *
 * Per D10: streaming commands POST with Accept: text/event-stream and read frames
 * line-by-line. Each decoded frame is dispatched to the $onEvent callback.
 */
final readonly class GatewayStreamClient implements GatewayProgressStreamClient
{
    public function __construct(
        private ?string $baseUrl,
        private int $timeout,
        private ?string $caPemPath = null,
        private ?GatewayStreamTransport $transport = null,
        private bool $preferCurl = false,
    ) {}

    /**
     * Stream progress events from the gateway. Sends $payload to $path with
     * Accept: text/event-stream using the specified HTTP method, reads SSE
     * frames, and calls $onEvent($type, $payload) for each decoded frame.
     *
     * Returns the terminal frame's exit code. Throws
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
        if ($this->normalizedBaseUrl() === '') {
            throw new GatewayApiException('Gateway URL is not configured.');
        }

        if ($this->shouldUseTestingHttpFallback()) {
            return $this->streamEventsWithTestingHttp($path, $payload, $onEvent, $method);
        }

        $result = $this->streamTransport()->events(
            request: new GenericGatewayStreamRequest($path, $payload, $method),
            onEvent: function (string $event, array $data) use ($onEvent): void {
                try {
                    $onEvent(ProgressEventType::from($event), $data);
                } catch (ValueError $exception) {
                    throw GatewayApiException::streamMalformed($exception);
                }
            },
            unavailableMessage: 'Gateway connection is required to stream command progress.',
            requireTerminalFrame: true,
            onIdle: $onIdle
            ?? (ForkedFrameTicker::hasIdleCallback() ? ForkedFrameTicker::invokeIdleCallback(...) : null),
            idleIntervalMicroseconds: $idleIntervalMicroseconds,
        );

        if ($result instanceof \Orbit\Sdk\Laravel\GatewayApiException) {
            throw $this->mapSdkException($result);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  callable(ProgressEventType, array<string, mixed>): void  $onEvent
     */
    private function streamEventsWithTestingHttp(string $path, array $payload, callable $onEvent, string $method): int
    {
        try {
            $pending = Http::baseUrl($this->normalizedBaseUrl())
                ->withHeaders(['Accept' => 'text/event-stream'])
                ->asJson()
                ->connectTimeout($this->timeout)
                ->timeout(0);

            $response = match (strtolower($method)) {
                'delete' => $pending->delete('/'.ltrim($path, '/'), $payload),
                default => $pending->post('/'.ltrim($path, '/'), $payload),
            };
        } catch (ConnectionException $exception) {
            throw $this->classifyNetworkError($exception);
        }

        if ($response->failed()) {
            throw GatewayApiException::httpError($response->status(), $response->body());
        }

        return $this->dispatchTestingFrames($response->body(), $onEvent);
    }

    /**
     * @param  callable(ProgressEventType, array<string, mixed>): void  $onEvent
     */
    private function dispatchTestingFrames(string $body, callable $onEvent): int
    {
        foreach (explode("\n\n", str_replace("\r\n", "\n", $body)) as $frame) {
            if (trim($frame) === '') {
                continue;
            }

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
                continue;
            }

            try {
                /** @var array<string, mixed> $payload */
                $payload = json_decode(implode("\n", $data), true, 512, JSON_THROW_ON_ERROR);
                $type = ProgressEventType::from($event);
            } catch (Throwable $exception) {
                throw GatewayApiException::streamMalformed($exception);
            }

            $onEvent($type, $payload);

            if ($type === ProgressEventType::Complete) {
                return new ProgressEvent($type, $payload)->terminalExitCode();
            }

            if ($type === ProgressEventType::Error) {
                return new ProgressEvent($type, $payload)->terminalExitCode();
            }
        }

        throw GatewayApiException::streamClosedBeforeTerminal(
            new \RuntimeException('SSE stream closed before a terminal frame was received.'),
        );
    }

    private function streamTransport(): GatewayStreamTransport
    {
        if ($this->transport instanceof GatewayStreamTransport) {
            return $this->transport;
        }

        return new GatewayStreamTransport(
            connector: new GatewayConnector(
                baseUrl: $this->normalizedBaseUrl(),
                caPemPath: $this->verifiedCaPemPath(),
                timeout: $this->timeout,
            ),
            preferCurl: $this->preferCurl,
        );
    }

    private function shouldUseTestingHttpFallback(): bool
    {
        return app()->runningUnitTests() && ! $this->preferCurl && ! GatewayMockClient::hasGlobal();
    }

    private function normalizedBaseUrl(): string
    {
        $baseUrl = is_string($this->baseUrl) ? trim($this->baseUrl) : '';

        return rtrim($baseUrl, '/');
    }

    private function verifiedCaPemPath(): ?string
    {
        if (! is_string($this->caPemPath) || $this->caPemPath === '') {
            return null;
        }

        return is_file($this->caPemPath) ? $this->caPemPath : null;
    }

    private function mapSdkException(\Orbit\Sdk\Laravel\GatewayApiException $exception): GatewayApiException
    {
        return match ($exception->errorCode()) {
            'http_error' => GatewayApiException::httpError(
                (int) ($exception->errorData()['status'] ?? 0),
                (string) ($exception->errorData()['body'] ?? $exception->getMessage()),
            ),
            'stream_closed_before_terminal' => GatewayApiException::streamClosedBeforeTerminal(
                $exception->getPrevious() ?? $exception,
            ),
            'stream_malformed' => GatewayApiException::streamMalformed(
                $exception->getPrevious() ?? $exception,
            ),
            null, 'gateway_unavailable' => $this->classifyNetworkError($exception),
            default => new GatewayApiException(
                'Gateway request failed',
                GatewayApiFailureKind::Http,
                body: json_encode([
                    'error' => [
                        'code' => $exception->errorCode(),
                        'message' => $exception->getMessage(),
                        'meta' => $exception->errorMeta(),
                        'data' => $exception->errorData(),
                    ],
                ], JSON_THROW_ON_ERROR),
                previous: $exception,
            ),
        };
    }

    private function classifyNetworkError(Throwable $exception): GatewayApiException
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
}
