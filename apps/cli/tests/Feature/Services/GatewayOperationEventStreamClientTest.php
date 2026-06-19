<?php

declare(strict_types=1);

use App\Services\GatewayOperationEventStreamClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orbit\Core\Progress\ProgressEventType;

it('gets operation events with last event id and exposes decoded event ids', function (): void {
    $body = "id: 42\n"
        ."event: step\n"
        ."data: {\"message\":\"runner started\"}\n\n"
        ."id: 43\n"
        ."event: complete\n"
        ."data: {\"exit_code\":0}\n\n";
    $events = [];

    Http::fake([
        'https://gateway.test/api/operations/run-1/events' => Http::response($body, 200, [
            'Content-Type' => 'text/event-stream',
        ]),
    ]);

    $terminal = (new GatewayOperationEventStreamClient('https://gateway.test', 30))
        ->replay('/api/operations/run-1/events', 41, function (ProgressEventType $type, array $payload, ?int $eventId) use (&$events): void {
            $events[] = [
                'id' => $eventId,
                'type' => $type->value,
                'payload' => $payload,
            ];
        });

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://gateway.test/api/operations/run-1/events'
        && $request->hasHeader('Accept', 'text/event-stream')
        && $request->hasHeader('Last-Event-ID', '41'));

    expect($terminal)->toBe([
        'type' => ProgressEventType::Complete,
        'payload' => ['exit_code' => 0],
    ])
        ->and($events)->toBe([
            ['id' => 42, 'type' => 'step', 'payload' => ['message' => 'runner started']],
            ['id' => 43, 'type' => 'complete', 'payload' => ['exit_code' => 0]],
        ]);
});

it('disables idle read timeout so long silent operation replays can complete', function (): void {
    $body = "id: 1\nevent: complete\ndata: {\"exit_code\":0}\n\n";
    $options = [];

    Http::fake(function (Request $request, array $opts) use (&$options, $body) {
        $options = $opts;

        return Http::response($body, 200, ['Content-Type' => 'text/event-stream']);
    });

    (new GatewayOperationEventStreamClient('https://gateway.test', 30))
        ->replay('/api/operations/run-1/events', null, fn () => null);

    expect($options['stream'] ?? null)->toBeTrue()
        ->and($options['read_timeout'] ?? null)->toBe(0);
});

it('verifies TLS against the configured gateway CA when a PEM file exists', function (): void {
    $body = "id: 1\nevent: complete\ndata: {\"exit_code\":0}\n\n";
    $pemPath = tempnam(sys_get_temp_dir(), 'orbit-ca-').'.pem';
    file_put_contents($pemPath, "-----BEGIN CERTIFICATE-----\nfake\n-----END CERTIFICATE-----\n");
    $options = [];

    Http::fake(function (Request $request, array $opts) use (&$options, $body) {
        $options = $opts;

        return Http::response($body, 200, ['Content-Type' => 'text/event-stream']);
    });

    try {
        (new GatewayOperationEventStreamClient('https://gateway.test', 30, $pemPath))
            ->replay('/api/operations/run-1/events', null, fn () => null);
    } finally {
        @unlink($pemPath);
    }

    expect($options['verify'] ?? null)->toBe($pemPath)
        ->and($options['stream'] ?? null)->toBeTrue()
        ->and($options['read_timeout'] ?? null)->toBe(0);
});

it('returns null when an operation replay closes without a terminal event', function (): void {
    Http::fake([
        'https://gateway.test/api/operations/run-1/events' => Http::response("id: 44\nevent: step\ndata: {\"message\":\"still running\"}\n\n", 200, [
            'Content-Type' => 'text/event-stream',
        ]),
    ]);

    $terminal = (new GatewayOperationEventStreamClient('https://gateway.test', 30))
        ->replay('/api/operations/run-1/events', null, fn () => null);

    expect($terminal)->toBeNull();
});
