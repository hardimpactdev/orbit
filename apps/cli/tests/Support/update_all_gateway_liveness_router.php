<?php

declare(strict_types=1);

if ($argc < 2) {
    fwrite(STDERR, "usage: update_all_gateway_liveness_router.php <port>\n");
    exit(1);
}

$port = (int) $argv[1];
$silentDelayMicroseconds = (int) (getenv('ORBIT_UPDATE_ALL_LIVENESS_SILENT_US') ?: '6000000');
$startDelayMicroseconds = (int) (getenv('ORBIT_UPDATE_ALL_LIVENESS_START_DELAY_US') ?: '0');
$server = stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);

if ($server === false) {
    fwrite(STDERR, "Unable to start liveness fixture server: {$errstr}\n");
    exit(1);
}

$eventsSent = false;

while (! $eventsSent && ($connection = @stream_socket_accept($server, 30)) !== false) {
    stream_set_timeout($connection, 5);
    stream_set_write_buffer($connection, 0);

    $request = '';

    while (! str_contains($request, "\r\n\r\n") && ! feof($connection)) {
        $chunk = fread($connection, 8192);

        if ($chunk === false || $chunk === '') {
            break;
        }

        $request .= $chunk;
    }

    [$method, $target] = requestLine($request);
    $path = parse_url($target, PHP_URL_PATH) ?: '/';

    if ($method === 'POST' && $path === '/api/update/all/start') {
        if ($startDelayMicroseconds > 0) {
            usleep($startDelayMicroseconds);
        }

        writeJson($connection, [
            'success' => [
                'data' => [
                    'operation_run' => [
                        'id' => 'run-1',
                        'type' => 'update:all',
                        'status' => 'queued',
                    ],
                    'update_plan' => [
                        'target_version' => '9.9.9',
                        'gateway_image' => 'ghcr.io/hardimpactdev/orbit-gateway:9.9.9@sha256:'.str_repeat('a', 64),
                        'manifest_source' => 'github-release',
                        'manifest_version' => '9.9.9',
                    ],
                    'events_url' => '/api/operations/run-1/events',
                ],
                'meta' => [],
            ],
        ]);
        fclose($connection);

        continue;
    }

    if ($method === 'GET' && $path === '/api/operations/run-1/events') {
        fwrite($connection, "HTTP/1.1 200 OK\r\n");
        fwrite($connection, "Content-Type: text/event-stream\r\n");
        fwrite($connection, "Cache-Control: no-cache\r\n");
        fwrite($connection, "Connection: close\r\n\r\n");
        fflush($connection);

        emitSse($connection, 1, 'step', [
            'key' => 'check-updates',
            'status' => 'done',
            'message' => 'Done: latest version is 9.9.9',
        ]);
        usleep(100_000);
        emitSse($connection, 2, 'step', [
            'key' => 'check-fleet-versions',
            'status' => 'done',
            'message' => 'Done: 1 outdated node found',
            'update_targets' => ['gateway', 'local'],
        ]);
        usleep(100_000);
        emitSse($connection, 3, 'step', [
            'key' => 'gateway',
            'status' => 'running',
            'message' => 'Replacing cli binary',
        ]);

        if ($silentDelayMicroseconds > 0) {
            usleep($silentDelayMicroseconds);
        }

        emitSse($connection, 4, 'step', [
            'key' => 'gateway',
            'status' => 'done',
            'message' => 'Gateway services updated',
        ]);
        emitSse($connection, 5, 'complete', [
            'status' => 'succeeded',
            'target_version' => '9.9.9',
            'skipped' => true,
        ]);

        fclose($connection);
        $eventsSent = true;

        continue;
    }

    fwrite($connection, "HTTP/1.1 404 Not Found\r\nContent-Length: 9\r\nConnection: close\r\n\r\nnot found");
    fclose($connection);
}

fclose($server);

/**
 * @return array{0: string, 1: string}
 */
function requestLine(string $request): array
{
    $line = strtok($request, "\r\n") ?: '';
    $parts = explode(' ', $line, 3);

    return [$parts[0] ?? '', $parts[1] ?? '/'];
}

/**
 * @param  array<string, mixed>  $payload
 */
function writeJson($connection, array $payload): void
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    fwrite($connection, "HTTP/1.1 200 OK\r\n");
    fwrite($connection, "Content-Type: application/json\r\n");
    fwrite($connection, 'Content-Length: '.strlen($body)."\r\n");
    fwrite($connection, "Connection: close\r\n\r\n");
    fwrite($connection, $body);
    fflush($connection);
}

/**
 * @param  array<string, mixed>  $payload
 */
function emitSse($connection, int $id, string $event, array $payload): void
{
    fwrite($connection, "id: {$id}\n");
    fwrite($connection, "event: {$event}\n");
    fwrite($connection, 'data: '.json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n\n");
    fflush($connection);
}
