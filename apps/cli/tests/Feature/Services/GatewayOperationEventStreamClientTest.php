<?php

declare(strict_types=1);

use App\Services\GatewayOperationEventStreamClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orbit\Core\Progress\ForkedFrameTicker;
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

it('keeps idle callbacks on cadence while opening a slow operation event stream without fork signals', function (): void {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

    if ($server === false) {
        test()->markTestSkipped('stream_socket_server is required for delayed operation stream coverage.');
    }

    $address = stream_socket_get_name($server, false);
    $port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);

    $serverPid = pcntl_fork();

    if ($serverPid === -1) {
        fclose($server);

        test()->markTestSkipped('pcntl_fork is required for delayed operation stream coverage.');
    }

    if ($serverPid === 0) {
        $connection = stream_socket_accept($server);

        if (is_resource($connection)) {
            while (! feof($connection)) {
                $chunk = fread($connection, 8192);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                if (str_contains($chunk, "\r\n\r\n")) {
                    break;
                }
            }

            usleep(1_200_000);
            $body = "id: 1\nevent: complete\ndata: {\"exit_code\":0}\n\n";
            fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n{$body}");
            fclose($connection);
        }

        fclose($server);
        exit(0);
    }

    $ticks = [];
    $cleanupIdleCallback = registerGatewayOperationEventStreamIdleCallbackWithoutFork(100_000, function () use (&$ticks): void {
        $ticks[] = hrtime(true);
    });

    try {
        $terminal = (new GatewayOperationEventStreamClient("http://127.0.0.1:{$port}", 30))
            ->replay('/api/operations/run-1/events', null, fn () => null);

        expect($terminal)->toBe([
            'type' => ProgressEventType::Complete,
            'payload' => ['exit_code' => 0],
        ])
            ->and(count($ticks))->toBeGreaterThanOrEqual(5);

        $maxGapMicroseconds = max(array_map(
            static fn (array $pair): int => intdiv($pair[1] - $pair[0], 1000),
            array_map(null, array_slice($ticks, 0, -1), array_slice($ticks, 1)),
        ));

        expect($maxGapMicroseconds)->toBeLessThan(300_000);
    } finally {
        $cleanupIdleCallback();
        pcntl_waitpid($serverPid, $status);
        fclose($server);
    }
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

/**
 * @return Closure(): void
 */
function registerGatewayOperationEventStreamIdleCallbackWithoutFork(int $intervalUs, callable $callback): Closure
{
    $reflection = new ReflectionClass(ForkedFrameTicker::class);
    $idleCallback = $reflection->getProperty('idleCallback');
    $idleIntervalMicroseconds = $reflection->getProperty('idleIntervalMicroseconds');
    $lastInvokedAt = $reflection->getProperty('lastInvokedAt');

    $previousIdleCallback = $idleCallback->getValue(null);
    $previousIdleIntervalMicroseconds = $idleIntervalMicroseconds->getValue(null);
    $previousLastInvokedAt = $lastInvokedAt->getValue(null);

    $idleCallback->setValue(null, $callback);
    $idleIntervalMicroseconds->setValue(null, $intervalUs);
    $lastInvokedAt->setValue(null, 0.0);

    return function () use (
        $idleCallback,
        $idleIntervalMicroseconds,
        $lastInvokedAt,
        $previousIdleCallback,
        $previousIdleIntervalMicroseconds,
        $previousLastInvokedAt,
    ): void {
        $idleCallback->setValue(null, $previousIdleCallback);
        $idleIntervalMicroseconds->setValue(null, $previousIdleIntervalMicroseconds);
        $lastInvokedAt->setValue(null, $previousLastInvokedAt);
    };
}
