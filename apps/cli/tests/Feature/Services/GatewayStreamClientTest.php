<?php

declare(strict_types=1);

use App\Exceptions\GatewayApiException;
use App\Exceptions\GatewayApiFailureKind;
use App\Services\GatewayStreamClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response as Psr7Response;
use GuzzleHttp\Psr7\Utils;
use Orbit\Core\Progress\ProgressEventType;

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

function fakeGatewayStreamClient(string $body, int $status = 200, array $headers = []): GatewayStreamClient
{
    return new GatewayStreamClient(
        'https://gateway.test',
        30,
        httpClient: new FakeGatewayStreamHttpClient([
            new Psr7Response($status, $headers + ['Content-Type' => 'text/event-stream'], $body),
        ]),
    );
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

        expect($exitCode)->toBe(0)
            ->and($events)->toHaveCount(3)
            ->and($events[0]['type'])->toBe('tree')
            ->and($events[1]['type'])->toBe('step')
            ->and($events[2]['type'])->toBe('complete');
    });

    it('dispatches delayed SSE chunks as they arrive over the curl transport', function (): void {
        if (! function_exists('curl_multi_init')) {
            test()->markTestSkipped('curl_multi_init is required for delayed stream coverage.');
        }

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

                fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nCache-Control: no-cache\r\nConnection: close\r\n\r\n");
                fwrite($connection, "event: tree\ndata: {\"name\":\"doctor\"}\n\n");
                fflush($connection);
                usleep(600_000);
                fwrite($connection, "event: step\ndata: {\"message\":\"checking\"}\n\n");
                fflush($connection);
                usleep(600_000);
                fwrite($connection, "event: complete\ndata: {\"ok\":true}\n\n");
                fclose($connection);
            }

            fclose($server);
            terminateForkedFixtureProcess();
        }

        $events = [];

        try {
            $exitCode = (new GatewayStreamClient("http://127.0.0.1:{$port}", 30, preferCurl: true))
                ->streamEvents('/api/stream', ['scope' => 'doctor'], function (ProgressEventType $type, array $payload) use (&$events): void {
                    $events[] = [
                        'type' => $type->value,
                        'payload' => $payload,
                        'at' => hrtime(true),
                    ];
                });

            $firstGapMicroseconds = intdiv($events[1]['at'] - $events[0]['at'], 1000);
            $secondGapMicroseconds = intdiv($events[2]['at'] - $events[1]['at'], 1000);

            expect($exitCode)->toBe(0)
                ->and(array_column($events, 'type'))->toBe(['tree', 'step', 'complete'])
                ->and($firstGapMicroseconds)->toBeGreaterThan(250_000)
                ->and($secondGapMicroseconds)->toBeGreaterThan(250_000);
        } finally {
            pcntl_waitpid($serverPid, $status);
            fclose($server);
        }
    });

    it('keeps only a bounded response tail for curl HTTP errors', function (): void {
        if (! function_exists('curl_multi_init')) {
            test()->markTestSkipped('curl_multi_init is required for curl HTTP error coverage.');
        }

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

                $body = 'orbit-leading-token'.str_repeat('x', 70_000);

                fwrite($connection, "HTTP/1.1 500 Internal Server Error\r\nContent-Type: text/plain\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n");

                foreach (str_split($body, 8192) as $chunk) {
                    fwrite($connection, $chunk);
                }

                fclose($connection);
            }

            fclose($server);
            terminateForkedFixtureProcess();
        }

        $exception = null;

        try {
            (new GatewayStreamClient("http://127.0.0.1:{$port}", 30, preferCurl: true))
                ->streamEvents('/api/stream', ['scope' => 'doctor'], fn () => null);
        } catch (GatewayApiException $caught) {
            $exception = $caught;
        } finally {
            pcntl_waitpid($serverPid, $status);
            fclose($server);
        }

        expect($exception)->toBeInstanceOf(GatewayApiException::class)
            ->and($exception?->statusCode())->toBe(500)
            ->and($exception?->bodyExcerpt())->toBeString()
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

        expect($exitCode)->toBe(0)
            ->and($events)->toHaveCount(1)
            ->and($events[0])->toBe('complete');
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

        expect($exception)->toBeInstanceOf(GatewayApiException::class)
            ->and($exception?->failureKind())->toBe(GatewayApiFailureKind::StreamClosedBeforeTerminal)
            ->and($exception?->cliFailureCode())->toBe('gateway_unavailable');
    });

    it('classifies response body read failures as stream closed before terminal', function (): void {
        $readFailure = new RuntimeException('Unable to read from stream');
        $stream = FnStream::decorate(Utils::streamFor(''), [
            'eof' => fn (): bool => false,
            'read' => fn (int $length): string => throw $readFailure,
        ]);

        $httpClient = new FakeGatewayStreamHttpClient([
            new Psr7Response(200, ['Content-Type' => 'text/event-stream'], $stream),
        ]);

        $exception = null;

        try {
            (new GatewayStreamClient('https://gateway.test', 30, httpClient: $httpClient))
                ->streamEvents('/api/stream', [], fn () => null);
        } catch (Throwable $caught) {
            $exception = $caught;
        }

        expect($exception)->toBeInstanceOf(GatewayApiException::class)
            ->and($exception?->failureKind())->toBe(GatewayApiFailureKind::StreamClosedBeforeTerminal)
            ->and($exception?->getPrevious())->toBe($readFailure);
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

        expect($exception)->toBeInstanceOf(GatewayApiException::class)
            ->and($exception?->failureKind())->toBe(GatewayApiFailureKind::StreamMalformed)
            ->and($exception?->cliFailureCode())->toBe('gateway_unavailable');
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

        expect($exception)->toBeInstanceOf(GatewayApiException::class)
            ->and($exception?->failureKind())->toBe(GatewayApiFailureKind::StreamMalformed)
            ->and($exception?->cliFailureCode())->toBe('gateway_unavailable');
    });

    it('throws GatewayApiException for HTTP error responses', function (): void {
        expect(fn () => fakeGatewayStreamClient('Forbidden', 403)
            ->streamEvents('/api/stream', [], fn () => null))
            ->toThrow(GatewayApiException::class);
    });

    it('throws GatewayApiException for WireGuard unreachable connection errors', function (): void {
        $request = new Psr7Request('POST', 'https://10.6.0.1/api/stream');
        $httpClient = new FakeGatewayStreamHttpClient([
            new ConnectException('Connection timed out after 30 seconds', $request),
        ]);

        expect(fn () => (new GatewayStreamClient('https://10.6.0.1', 30, httpClient: $httpClient))
            ->streamEvents('/api/stream', [], fn () => null))
            ->toThrow(GatewayApiException::class, 'WireGuard');
    });

    it('POSTs the payload with Accept: text/event-stream header', function (): void {
        $body = buildSseStream([
            ['event' => 'complete', 'data' => ['ok' => true]],
        ]);

        $httpClient = new FakeGatewayStreamHttpClient([
            new Psr7Response(200, ['Content-Type' => 'text/event-stream'], $body),
        ]);

        (new GatewayStreamClient('https://gateway.test', 30, httpClient: $httpClient))
            ->streamEvents('/api/stream', ['key' => 'val'], fn () => null);

        $request = $httpClient->requests[0];

        expect($request['method'])->toBe('POST')
            ->and($request['uri'])->toBe('https://gateway.test/api/stream')
            ->and($request['options']['headers']['Accept'] ?? null)->toBe('text/event-stream')
            ->and($request['options']['json']['key'] ?? null)->toBe('val');
    });

    it('verifies TLS against the configured gateway CA when a PEM file exists', function (): void {
        $body = buildSseStream([['event' => 'complete', 'data' => ['ok' => true]]]);
        $pemPath = tempnam(sys_get_temp_dir(), 'orbit-ca-').'.pem';
        file_put_contents($pemPath, "-----BEGIN CERTIFICATE-----\nfake\n-----END CERTIFICATE-----\n");

        $httpClient = new FakeGatewayStreamHttpClient([
            new Psr7Response(200, ['Content-Type' => 'text/event-stream'], $body),
        ]);

        try {
            (new GatewayStreamClient('https://gateway.test', 30, $pemPath, $httpClient))
                ->streamEvents('/api/stream', [], fn () => null);
        } finally {
            @unlink($pemPath);
        }

        $options = $httpClient->requests[0]['options'];

        expect($options['verify'] ?? null)->toBe($pemPath)
            ->and($options['stream'] ?? null)->toBeTrue()
            ->and($options['read_timeout'] ?? null)->toBe(0);
    });

    it('does not apply the gateway connect timeout as a whole-stream deadline', function (): void {
        $body = buildSseStream([['event' => 'complete', 'data' => ['ok' => true]]]);

        $httpClient = new FakeGatewayStreamHttpClient([
            new Psr7Response(200, ['Content-Type' => 'text/event-stream'], $body),
        ]);

        (new GatewayStreamClient('https://gateway.test', 30, httpClient: $httpClient))
            ->streamEvents('/api/stream', [], fn () => null);

        $options = $httpClient->requests[0]['options'];

        expect($options['stream'] ?? null)->toBeTrue()
            ->and($options['connect_timeout'] ?? null)->toBe(30)
            ->and($options['timeout'] ?? null)->toBe(0);
    });

    it('disables idle read timeout so long silent streams can complete', function (): void {
        $body = buildSseStream([['event' => 'complete', 'data' => ['ok' => true]]]);

        $httpClient = new FakeGatewayStreamHttpClient([
            new Psr7Response(200, ['Content-Type' => 'text/event-stream'], $body),
        ]);

        (new GatewayStreamClient('https://gateway.test', 30, httpClient: $httpClient))
            ->streamEvents('/api/stream', [], fn () => null);

        $options = $httpClient->requests[0]['options'];

        expect($options['read_timeout'] ?? null)->toBe(0);
    });

    it('leaves the default verify behavior when no CA PEM path is configured', function (): void {
        $body = buildSseStream([['event' => 'complete', 'data' => ['ok' => true]]]);

        $httpClient = new FakeGatewayStreamHttpClient([
            new Psr7Response(200, ['Content-Type' => 'text/event-stream'], $body),
        ]);

        (new GatewayStreamClient('https://gateway.test', 30, httpClient: $httpClient))
            ->streamEvents('/api/stream', [], fn () => null);

        $verify = $httpClient->requests[0]['options']['verify'] ?? null;

        // Stream option stays, but verify is never overridden to a CA path string.
        expect($verify)->not->toBeString();
    });
});
