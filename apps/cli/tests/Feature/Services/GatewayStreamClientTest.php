<?php

declare(strict_types=1);

use App\Exceptions\GatewayApiException;
use App\Exceptions\GatewayApiFailureKind;
use App\Services\GatewayStreamClient;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Orbit\Core\Progress\ProgressEventType;
use Orbit\Sdk\Laravel\Requests\GenericGatewayStreamRequest;
use Orbit\Sdk\Laravel\Testing\GatewayMockClient;
use Orbit\Sdk\Laravel\Testing\GatewayMockResponse;
use Orbit\Sdk\Laravel\Testing\GatewayPendingRequest;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Build a raw SSE stream body string from named event frames.
 *
 * @param  list<array{event: string, data: array<string, mixed>}>  $frames
 */
function buildSseStream(array $frames): string
{
    $lines = [];

    foreach ($frames as $frame) {
        $lines[] = "event: {$frame['event']}";
        $lines[] = 'data: '.json_encode($frame['data'], JSON_THROW_ON_ERROR);
        $lines[] = '';
    }

    return implode("\n", $lines);
}

function fakeGatewayStreamClient(
    string|StreamInterface $body,
    int $status = 200,
    array $headers = [],
): GatewayStreamClient {
    fakeGatewayStreamMock(
        $body instanceof StreamInterface
            ? new CliGatewayStreamMockResponse($body, $status, $headers + ['Content-Type' => 'text/event-stream'])
            : GatewayMockResponse::make($body, $status, $headers + ['Content-Type' => 'text/event-stream']),
    );

    return new GatewayStreamClient('https://gateway.test', 30);
}

function fakeGatewayStreamMock(GatewayMockResponse $response): void
{
    GatewayMockClient::destroyGlobal();

    GatewayMockClient::global([
        GenericGatewayStreamRequest::class => $response,
    ]);
}

function lastGatewayStreamPendingRequest(): GatewayPendingRequest
{
    $pendingRequest = GatewayMockClient::lastPendingRequest();

    expect($pendingRequest)->not->toBeNull();

    return $pendingRequest;
}

describe('GatewayStreamClient', function (): void {
    it('calls $onEvent for each decoded frame and returns 0 on complete', function (): void {
        $body = buildSseStream([
            ['event' => 'tree', 'data' => ['name' => 'workspace-setup']],
            ['event' => 'step', 'data' => ['message' => 'cloning repository']],
            ['event' => 'complete', 'data' => ['ok' => true]],
        ]);

        $events = [];
        $exitCode = fakeGatewayStreamClient($body)
            ->streamEvents('/api/stream', [], function (ProgressEventType $type, array $payload) use (&$events): void {
                $events[] = ['type' => $type->value, 'payload' => $payload];
            });

        expect($exitCode)
            ->toBe(0)
            ->and($events)
            ->toHaveCount(3)
            ->and($events[0]['type'])
            ->toBe('tree')
            ->and($events[1]['type'])
            ->toBe('step')
            ->and($events[2]['type'])
            ->toBe('complete');
    });

    it('dispatches delayed SSE chunks as they arrive over the SDK stream transport', function (): void {
        GatewayMockClient::destroyGlobal();
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($server === false) {
            test()->markTestSkipped('stream_socket_server is required for delayed stream coverage.');
        }

        $address = stream_socket_get_name($server, false);
        $port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);

        $serverPid = pcntl_fork();

        if ($serverPid === -1) {
            fclose($server);

            test()->markTestSkipped('pcntl_fork is required for delayed stream coverage.');
        }

        if ($serverPid === 0) {
            $connection = stream_socket_accept($server, 5);

            if (is_resource($connection)) {
                while (! feof($connection)) {
                    $chunk = fread($connection, 8192);

                    if ($chunk === false || $chunk === '' || str_contains($chunk, "\r\n\r\n")) {
                        break;
                    }
                }

                fwrite(
                    $connection,
                    "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nCache-Control: no-cache\r\nConnection: close\r\n\r\n",
                );
                fwrite($connection, "event: tree\ndata: {\"name\":\"doctor\"}\n\n");
                fflush($connection);
                usleep(150_000);
                fwrite($connection, "event: step\ndata: {\"message\":\"checking\"}\n\n");
                fflush($connection);
                usleep(300_000);
                fwrite($connection, "event: complete\ndata: {\"ok\":true}\n\n");
                fclose($connection);
            }

            fclose($server);
            terminateForkedFixtureProcess();
        }

        $events = [];

        try {
            $exitCode = new GatewayStreamClient("http://127.0.0.1:{$port}", 30, preferCurl: true)
                ->streamEvents('/api/stream', ['scope' => 'doctor'], function (
                    ProgressEventType $type,
                    array $payload,
                ) use (&$events): void {
                    $events[] = [
                        'type' => $type->value,
                        'payload' => $payload,
                        'at' => hrtime(true),
                    ];
                });

            $secondGapMicroseconds = intdiv($events[2]['at'] - $events[1]['at'], 1000);

            expect($exitCode)
                ->toBe(0)
                ->and(array_column($events, 'type'))
                ->toBe(['tree', 'step', 'complete'])
                ->and($secondGapMicroseconds)
                ->toBeGreaterThan(150_000);
        } finally {
            pcntl_waitpid($serverPid, $status);
            fclose($server);
        }
    })->group('slow');

    it('keeps only a bounded response tail for stream HTTP errors', function (): void {
        GatewayMockClient::destroyGlobal();
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($server === false) {
            test()->markTestSkipped('stream_socket_server is required for curl HTTP error coverage.');
        }

        $address = stream_socket_get_name($server, false);
        $port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);

        $serverPid = pcntl_fork();

        if ($serverPid === -1) {
            fclose($server);

            test()->markTestSkipped('pcntl_fork is required for curl HTTP error coverage.');
        }

        if ($serverPid === 0) {
            $connection = stream_socket_accept($server, 5);

            if (is_resource($connection)) {
                while (! feof($connection)) {
                    $chunk = fread($connection, 8192);

                    if ($chunk === false || $chunk === '' || str_contains($chunk, "\r\n\r\n")) {
                        break;
                    }
                }

                $body = 'orbit-leading-token'.str_repeat('x', 65_600);

                fwrite(
                    $connection,
                    "HTTP/1.1 500 Internal Server Error\r\nContent-Type: text/plain\r\nContent-Length: "
                    .strlen($body)
                    ."\r\nConnection: close\r\n\r\n",
                );
                fwrite($connection, $body);

                fclose($connection);
            }

            fclose($server);
            terminateForkedFixtureProcess();
        }

        $exception = null;

        try {
            new GatewayStreamClient("http://127.0.0.1:{$port}", 30, preferCurl: true)
                ->streamEvents('/api/stream', ['scope' => 'doctor'], fn () => null, idleIntervalMicroseconds: 1);
        } catch (GatewayApiException $caught) {
            $exception = $caught;
        } finally {
            pcntl_waitpid($serverPid, $status);
            fclose($server);
        }

        expect($exception)
            ->toBeInstanceOf(GatewayApiException::class)
            ->and($exception?->statusCode())
            ->toBe(500)
            ->and($exception?->bodyExcerpt())
            ->toBeString()
            ->not->toContain('orbit-leading-token');
    });

    it('returns non-zero exit code on error frame', function (): void {
        $body = buildSseStream([
            ['event' => 'step', 'data' => ['message' => 'started']],
            ['event' => 'error', 'data' => ['message' => 'provision failed']],
        ]);

        $exitCode = fakeGatewayStreamClient($body)
            ->streamEvents('/api/stream', [], fn () => null);

        expect($exitCode)->toBe(1);
    });

    it('skips SSE comment keepalive lines', function (): void {
        $body = ": heartbeat\n\nevent: complete\ndata: {}\n\n";

        $events = [];
        $exitCode = fakeGatewayStreamClient($body)
            ->streamEvents('/api/stream', [], function (ProgressEventType $type, array $payload) use (&$events): void {
                $events[] = $type->value;
            });

        expect($exitCode)
            ->toBe(0)
            ->and($events)
            ->toHaveCount(1)
            ->and($events[0])
            ->toBe('complete');
    });

    it('throws when stream closes before a terminal frame', function (): void {
        $body = buildSseStream([
            ['event' => 'step', 'data' => ['message' => 'still running']],
        ]);

        $exception = null;

        try {
            fakeGatewayStreamClient($body)
                ->streamEvents('/api/stream', [], fn () => null);
        } catch (GatewayApiException $caught) {
            $exception = $caught;
        }

        expect($exception)
            ->toBeInstanceOf(GatewayApiException::class)
            ->and($exception?->failureKind())
            ->toBe(GatewayApiFailureKind::StreamClosedBeforeTerminal)
            ->and($exception?->cliFailureCode())
            ->toBe('gateway_unavailable');
    });

    it('classifies response body read failures as stream closed before terminal', function (): void {
        $readFailure = new RuntimeException('Unable to read from stream');
        $stream = FnStream::decorate(Utils::streamFor(''), [
            'eof' => fn (): bool => false,
            'read' => fn (int $length): string => throw $readFailure,
        ]);

        $exception = null;

        try {
            fakeGatewayStreamClient($stream)
                ->streamEvents('/api/stream', [], fn () => null);
        } catch (Throwable $caught) {
            $exception = $caught;
        }

        expect($exception)
            ->toBeInstanceOf(GatewayApiException::class)
            ->and($exception?->failureKind())
            ->toBe(GatewayApiFailureKind::StreamClosedBeforeTerminal)
            ->and($exception?->getPrevious())
            ->toBe($readFailure);
    });

    it('throws when an SSE frame is malformed', function (): void {
        $body = "event: step\ndata: not-json\n\nevent: complete\ndata: {\"ok\":true}\n\n";

        $exception = null;

        try {
            fakeGatewayStreamClient($body)
                ->streamEvents('/api/stream', [], fn () => null);
        } catch (GatewayApiException $caught) {
            $exception = $caught;
        }

        expect($exception)
            ->toBeInstanceOf(GatewayApiException::class)
            ->and($exception?->failureKind())
            ->toBe(GatewayApiFailureKind::StreamMalformed)
            ->and($exception?->cliFailureCode())
            ->toBe('gateway_unavailable');
    });

    it('treats a stream of only malformed frames as gateway_unavailable', function (): void {
        $body = "event: step\ndata: not-json\n\nnot-an-sse-frame\n\n";

        $exception = null;

        try {
            fakeGatewayStreamClient($body)
                ->streamEvents('/api/stream', [], fn () => null);
        } catch (GatewayApiException $caught) {
            $exception = $caught;
        }

        expect($exception)
            ->toBeInstanceOf(GatewayApiException::class)
            ->and($exception?->failureKind())
            ->toBe(GatewayApiFailureKind::StreamMalformed)
            ->and($exception?->cliFailureCode())
            ->toBe('gateway_unavailable');
    });

    it('throws GatewayApiException for HTTP error responses', function (): void {
        expect(fn () => fakeGatewayStreamClient('Forbidden', 403)
            ->streamEvents('/api/stream', [], fn () => null))
            ->toThrow(GatewayApiException::class);
    });

    it('throws GatewayApiException for WireGuard unreachable connection errors', function (): void {
        GatewayMockClient::destroyGlobal();
        GatewayMockClient::global([
            GenericGatewayStreamRequest::class => function (): never {
                throw new RuntimeException('Connection timed out after 30 seconds');
            },
        ]);

        expect(fn () => new GatewayStreamClient('https://10.6.0.1', 30)
            ->streamEvents('/api/stream', [], fn () => null))
            ->toThrow(GatewayApiException::class, 'WireGuard');
    });

    it('POSTs the payload with Accept: text/event-stream header', function (): void {
        $body = buildSseStream([
            ['event' => 'complete', 'data' => ['ok' => true]],
        ]);

        fakeGatewayStreamClient($body)
            ->streamEvents('/api/stream', ['key' => 'val'], fn () => null);

        $pendingRequest = lastGatewayStreamPendingRequest();

        expect($pendingRequest->method())
            ->toBe('POST')
            ->and($pendingRequest->url())
            ->toBe('https://gateway.test/api/stream')
            ->and($pendingRequest->header('Accept'))
            ->toBe('text/event-stream')
            ->and($pendingRequest->body())
            ->toBe(['key' => 'val']);
    });

    it('verifies TLS against the configured gateway CA when a PEM file exists', function (): void {
        $body = buildSseStream([['event' => 'complete', 'data' => ['ok' => true]]]);
        $pemPath = tempnam(sys_get_temp_dir(), 'orbit-ca-').'.pem';
        file_put_contents($pemPath, "-----BEGIN CERTIFICATE-----\nfake\n-----END CERTIFICATE-----\n");

        try {
            fakeGatewayStreamClient($body);

            new GatewayStreamClient('https://gateway.test', 30, $pemPath)
                ->streamEvents('/api/stream', [], fn () => null);
        } finally {
            @unlink($pemPath);
        }

        $config = lastGatewayStreamPendingRequest()->config();

        expect($config['verify'] ?? null)
            ->toBe($pemPath)
            ->and($config['stream'] ?? null)
            ->toBeTrue()
            ->and($config['read_timeout'] ?? null)
            ->toBe(0);
    });

    it('does not apply the gateway connect timeout as a whole-stream deadline', function (): void {
        $body = buildSseStream([['event' => 'complete', 'data' => ['ok' => true]]]);

        fakeGatewayStreamClient($body)
            ->streamEvents('/api/stream', [], fn () => null);

        $config = lastGatewayStreamPendingRequest()->config();

        expect($config['stream'] ?? null)
            ->toBeTrue()
            ->and($config['connect_timeout'] ?? null)
            ->toBe(10)
            ->and($config['timeout'] ?? null)
            ->toBe(0);
    });

    it('disables idle read timeout so long silent streams can complete', function (): void {
        $body = buildSseStream([['event' => 'complete', 'data' => ['ok' => true]]]);

        fakeGatewayStreamClient($body)
            ->streamEvents('/api/stream', [], fn () => null);

        $config = lastGatewayStreamPendingRequest()->config();

        expect($config['read_timeout'] ?? null)->toBe(0);
    });

    it('leaves the default verify behavior when no CA PEM path is configured', function (): void {
        $body = buildSseStream([['event' => 'complete', 'data' => ['ok' => true]]]);

        fakeGatewayStreamClient($body)
            ->streamEvents('/api/stream', [], fn () => null);

        $verify = lastGatewayStreamPendingRequest()->configValue('verify');

        // Stream option stays, but verify is never overridden to a CA path string.
        expect($verify)->not->toBeString();
    });
});

final class CliGatewayStreamMockResponse extends GatewayMockResponse
{
    /**
     * @param  array<string, string|int|float|bool>  $headers
     */
    public function __construct(
        private readonly StreamInterface $stream,
        private readonly int $responseStatus = 200,
        private readonly array $responseHeaders = [],
    ) {
        parent::__construct('', $responseStatus, $responseHeaders);
    }

    public function createPsrResponse(
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
    ): ResponseInterface {
        $response = $responseFactory->createResponse($this->responseStatus);

        foreach ($this->responseHeaders as $name => $value) {
            $response = $response->withHeader($name, (string) $value);
        }

        return $response->withBody($this->stream);
    }
}
