<?php

declare(strict_types=1);

use App\Services\NodeCommandTransport\NodeAgentPushStreamReader;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use Psr\Http\Message\StreamInterface;
use Tests\TestCase;

uses(TestCase::class);

it('forwards stdout and stderr payloads from framed process stream responses', function (): void {
    $body =
        process_stream_test_frame(1, "hello\n")
        .process_stream_test_frame(2, 'warn')
        .process_stream_test_frame(4, json_encode([
            'exit_code' => 2,
            'signal' => null,
            'duration_ms' => 17,
        ], JSON_THROW_ON_ERROR));
    $output = [];

    new NodeAgentPushStreamReader()->read(
        process_stream_test_response($body),
        static function (string $chunk) use (&$output): void {
            $output[] = $chunk;
        },
    );

    expect($output)->toBe(["hello\n", 'warn']);
});

it('buffers partial frame headers and payloads across chunked reads', function (): void {
    $stdoutFrame = process_stream_test_frame(1, 'partial-payload');
    $exitFrame = process_stream_test_frame(4, json_encode([
        'exit_code' => 0,
        'signal' => null,
        'duration_ms' => 0,
    ], JSON_THROW_ON_ERROR));
    $chunks = [
        substr($stdoutFrame, 0, 3),
        substr($stdoutFrame, 3, 4),
        substr($stdoutFrame, 7),
        $exitFrame,
    ];
    $output = [];

    new NodeAgentPushStreamReader()->read(
        process_stream_test_response(process_stream_test_chunked_body($chunks)),
        static function (string $chunk) use (&$output): void {
            $output[] = $chunk;
        },
    );

    expect($output)->toBe(['partial-payload']);
});

it('rejects v1 streams that end with leftover partial frame bytes', function (): void {
    $body = process_stream_test_frame(1, 'hello')
    .substr(
        process_stream_test_frame(4, json_encode([
            'exit_code' => 0,
            'signal' => null,
            'duration_ms' => 0,
        ], JSON_THROW_ON_ERROR)),
        0,
        4,
    );

    expect(fn () => new NodeAgentPushStreamReader()->read(
        process_stream_test_response($body),
        static function (string $chunk): void {},
    ))
        ->toThrow(RuntimeException::class, 'Orbit process stream ended with an incomplete frame.');
});

it('rejects v1 streams that end without an exit frame', function (): void {
    $body = process_stream_test_frame(1, 'hello');

    expect(fn () => new NodeAgentPushStreamReader()->read(
        process_stream_test_response($body),
        static function (string $chunk): void {},
    ))
        ->toThrow(RuntimeException::class, 'Orbit process stream ended without an exit frame.');
});

it('does not forward agent_error frame payloads to onOutput', function (): void {
    $body =
        process_stream_test_frame(1, 'visible')
        .process_stream_test_frame(3, json_encode([
            'code' => 'process_wait_failed',
            'message' => 'failed to wait for allowlisted binary',
            'retryable' => false,
        ], JSON_THROW_ON_ERROR))
        .process_stream_test_frame(4, json_encode([
            'exit_code' => null,
            'signal' => null,
            'duration_ms' => 5,
        ], JSON_THROW_ON_ERROR));
    $output = [];

    new NodeAgentPushStreamReader()->read(
        process_stream_test_response($body),
        static function (string $chunk) use (&$output): void {
            $output[] = $chunk;
        },
    );

    expect($output)->toBe(['visible']);
});

it('falls back to legacy raw passthrough for non v1 content types', function (): void {
    $body = "line one\nline two\n";
    $output = [];

    new NodeAgentPushStreamReader()->read(
        process_stream_test_response($body, 'text/plain; charset=UTF-8'),
        static function (string $chunk) use (&$output): void {
            $output[] = $chunk;
        },
    );

    expect($output)->toBe([$body]);
});

function process_stream_test_frame(int $type, string $payload): string
{
    return pack('CCnN', $type, 0, 0, strlen($payload)).$payload;
}

function process_stream_test_response(
    string|StreamInterface $body,
    string $contentType = 'application/vnd.orbit.process-stream.v1',
): Response {
    return new Response(new PsrResponse(200, ['Content-Type' => [$contentType]], $body));
}

/** @param  list<string>  $chunks */
function process_stream_test_chunked_body(array $chunks): StreamInterface
{
    $chunkIndex = 0;

    return new PumpStream(static function () use ($chunks, &$chunkIndex): string|false {
        if (! array_key_exists($chunkIndex, $chunks)) {
            return false;
        }

        $chunk = $chunks[$chunkIndex];
        $chunkIndex++;

        return $chunk;
    });
}
