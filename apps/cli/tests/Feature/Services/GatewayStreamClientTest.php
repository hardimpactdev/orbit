<?php

declare(strict_types=1);

use App\Exceptions\GatewayApiException;
use App\Exceptions\GatewayApiFailureKind;
use App\Services\GatewayStreamClient;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Response as Psr7Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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

describe('GatewayStreamClient', function (): void {
    it('calls $onEvent for each decoded frame and returns 0 on complete', function (): void {
        $body = buildSseStream([
            ['event' => 'tree', 'data' => ['name' => 'workspace-setup']],
            ['event' => 'step', 'data' => ['message' => 'cloning repository']],
            ['event' => 'complete', 'data' => ['ok' => true]],
        ]);

        Http::fake([
            'https://gateway.test/api/stream*' => Http::response($body, 200, [
                'Content-Type' => 'text/event-stream',
            ]),
        ]);

        $events = [];
        $exitCode = (new GatewayStreamClient('https://gateway.test', 30))
            ->streamEvents('/api/stream', [], function (ProgressEventType $type, array $payload) use (&$events): void {
                $events[] = ['type' => $type->value, 'payload' => $payload];
            });

        expect($exitCode)->toBe(0)
            ->and($events)->toHaveCount(3)
            ->and($events[0]['type'])->toBe('tree')
            ->and($events[1]['type'])->toBe('step')
            ->and($events[2]['type'])->toBe('complete');
    });

    it('returns non-zero exit code on error frame', function (): void {
        $body = buildSseStream([
            ['event' => 'step', 'data' => ['message' => 'started']],
            ['event' => 'error', 'data' => ['message' => 'provision failed']],
        ]);

        Http::fake([
            'https://gateway.test/api/stream*' => Http::response($body, 200, [
                'Content-Type' => 'text/event-stream',
            ]),
        ]);

        $exitCode = (new GatewayStreamClient('https://gateway.test', 30))
            ->streamEvents('/api/stream', [], fn () => null);

        expect($exitCode)->toBe(1);
    });

    it('skips SSE comment keepalive lines', function (): void {
        $body = ": heartbeat\n\nevent: complete\ndata: {}\n\n";

        Http::fake([
            'https://gateway.test/api/stream*' => Http::response($body, 200, [
                'Content-Type' => 'text/event-stream',
            ]),
        ]);

        $events = [];
        $exitCode = (new GatewayStreamClient('https://gateway.test', 30))
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

        Http::fake([
            'https://gateway.test/api/stream*' => Http::response($body, 200, [
                'Content-Type' => 'text/event-stream',
            ]),
        ]);

        $exception = null;

        try {
            (new GatewayStreamClient('https://gateway.test', 30))
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

        Http::fake([
            'https://gateway.test/api/stream*' => Create::promiseFor(new Psr7Response(200, [
                'Content-Type' => 'text/event-stream',
            ], $stream)),
        ]);

        $exception = null;

        try {
            (new GatewayStreamClient('https://gateway.test', 30))
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

        Http::fake([
            'https://gateway.test/api/stream*' => Http::response($body, 200, [
                'Content-Type' => 'text/event-stream',
            ]),
        ]);

        $exception = null;

        try {
            (new GatewayStreamClient('https://gateway.test', 30))
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

        Http::fake([
            'https://gateway.test/api/stream*' => Http::response($body, 200, [
                'Content-Type' => 'text/event-stream',
            ]),
        ]);

        $exception = null;

        try {
            (new GatewayStreamClient('https://gateway.test', 30))
                ->streamEvents('/api/stream', [], fn () => null);
        } catch (GatewayApiException $caught) {
            $exception = $caught;
        }

        expect($exception)->toBeInstanceOf(GatewayApiException::class)
            ->and($exception?->failureKind())->toBe(GatewayApiFailureKind::StreamMalformed)
            ->and($exception?->cliFailureCode())->toBe('gateway_unavailable');
    });

    it('throws GatewayApiException for HTTP error responses', function (): void {
        Http::fake([
            'https://gateway.test/api/stream*' => Http::response('Forbidden', 403),
        ]);

        expect(fn () => (new GatewayStreamClient('https://gateway.test', 30))
            ->streamEvents('/api/stream', [], fn () => null))
            ->toThrow(GatewayApiException::class);
    });

    it('throws GatewayApiException for WireGuard unreachable connection errors', function (): void {
        Http::fake(function () {
            throw new ConnectionException('Connection timed out after 30 seconds');
        });

        expect(fn () => (new GatewayStreamClient('https://10.6.0.1', 30))
            ->streamEvents('/api/stream', [], fn () => null))
            ->toThrow(GatewayApiException::class, 'WireGuard');
    });

    it('POSTs the payload with Accept: text/event-stream header', function (): void {
        $body = buildSseStream([
            ['event' => 'complete', 'data' => ['ok' => true]],
        ]);

        Http::fake([
            'https://gateway.test/api/stream*' => Http::response($body, 200, [
                'Content-Type' => 'text/event-stream',
            ]),
        ]);

        (new GatewayStreamClient('https://gateway.test', 30))
            ->streamEvents('/api/stream', ['key' => 'val'], fn () => null);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->hasHeader('Accept', 'text/event-stream')
            && isset($request->data()['key']));
    });

    it('verifies TLS against the configured gateway CA when a PEM file exists', function (): void {
        $body = buildSseStream([['event' => 'complete', 'data' => ['ok' => true]]]);
        $pemPath = tempnam(sys_get_temp_dir(), 'orbit-ca-').'.pem';
        file_put_contents($pemPath, "-----BEGIN CERTIFICATE-----\nfake\n-----END CERTIFICATE-----\n");

        $options = [];

        Http::fake(function (Request $request, array $opts) use (&$options, $body) {
            $options = $opts;

            return Http::response($body, 200, ['Content-Type' => 'text/event-stream']);
        });

        try {
            (new GatewayStreamClient('https://gateway.test', 30, $pemPath))
                ->streamEvents('/api/stream', [], fn () => null);
        } finally {
            @unlink($pemPath);
        }

        expect($options['verify'] ?? null)->toBe($pemPath)
            ->and($options['stream'] ?? null)->toBeTrue();
    });

    it('does not apply the gateway connect timeout as a whole-stream deadline', function (): void {
        $body = buildSseStream([['event' => 'complete', 'data' => ['ok' => true]]]);

        $options = [];

        Http::fake(function (Request $request, array $opts) use (&$options, $body) {
            $options = $opts;

            return Http::response($body, 200, ['Content-Type' => 'text/event-stream']);
        });

        (new GatewayStreamClient('https://gateway.test', 30))
            ->streamEvents('/api/stream', [], fn () => null);

        expect($options['stream'] ?? null)->toBeTrue()
            ->and($options['connect_timeout'] ?? null)->toBe(30)
            ->and($options['timeout'] ?? null)->toBe(0);
    });

    it('leaves the default verify behavior when no CA PEM path is configured', function (): void {
        $body = buildSseStream([['event' => 'complete', 'data' => ['ok' => true]]]);

        $verify = null;

        Http::fake(function (Request $request, array $opts) use (&$verify, $body) {
            $verify = $opts['verify'] ?? null;

            return Http::response($body, 200, ['Content-Type' => 'text/event-stream']);
        });

        (new GatewayStreamClient('https://gateway.test', 30))
            ->streamEvents('/api/stream', [], fn () => null);

        // Stream option stays, but verify is never overridden to a CA path string.
        expect($verify)->not->toBeString();
    });
});
