<?php

declare(strict_types=1);

use App\Services\Operations\OperationStreamWebSocketConnection;
use App\Services\Operations\RawOperationStreamWebSocketTransport;

/**
 * Build an unmasked server-to-client WebSocket text frame.
 */
function operation_stream_ws_text_frame(string $payload): string
{
    $length = strlen($payload);
    $head = chr(0x81);

    if ($length <= 125) {
        $head .= chr($length);
    } elseif ($length <= 65_535) {
        $head .= chr(126).pack('n', $length);
    } else {
        $head .= chr(127).pack('J', $length);
    }

    return $head.$payload;
}

/**
 * Complete an HTTP WebSocket upgrade for a single accepted connection.
 *
 * @param  resource  $connection
 */
function operation_stream_ws_accept_handshake($connection): void
{
    $request = '';

    while (! str_contains($request, "\r\n\r\n")) {
        $chunk = fgets($connection);

        if ($chunk === false) {
            return;
        }

        $request .= $chunk;
    }

    if (preg_match('/Sec-WebSocket-Key:\s*(\S+)/i', $request, $matches) !== 1) {
        return;
    }

    $accept = base64_encode(sha1($matches[1].'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', binary: true));

    fwrite(
        $connection,
        "HTTP/1.1 101 Switching Protocols\r\n"
        ."Upgrade: websocket\r\n"
        ."Connection: Upgrade\r\n"
        ."Sec-WebSocket-Accept: {$accept}\r\n\r\n",
    );
    fflush($connection);
}

it('keeps receiving frames after an idle period longer than the connect timeout', function (): void {
    if (! function_exists('pcntl_fork') || ! function_exists('pcntl_waitpid')) {
        test()->markTestSkipped('pcntl is required for delayed websocket coverage.');
    }

    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

    if ($server === false) {
        test()->markTestSkipped('stream_socket_server is required for delayed websocket coverage.');
    }

    $address = stream_socket_get_name($server, false);
    $port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);

    $serverPid = pcntl_fork();

    if ($serverPid === -1) {
        fclose($server);

        test()->markTestSkipped('pcntl_fork is required for delayed websocket coverage.');
    }

    if ($serverPid === 0) {
        try {
            $connection = stream_socket_accept($server, 5);

            if (is_resource($connection)) {
                operation_stream_ws_accept_handshake($connection);
                usleep(1_500_000);
                $payload = json_encode([
                    'event' => 'operation_stream.frame',
                    'data' => ['type' => 'stdout', 'payload' => ['data' => "FOLLOW-HIST-1\n"]],
                ], JSON_THROW_ON_ERROR);
                fwrite($connection, operation_stream_ws_text_frame($payload));
                fflush($connection);
                usleep(200_000);
                fclose($connection);
            }
        } finally {
            fclose($server);
            terminateForkedFixtureProcess();
        }
    }

    $transport = new RawOperationStreamWebSocketTransport;
    $connection = new OperationStreamWebSocketConnection(
        scheme: 'ws',
        host: '127.0.0.1',
        port: $port,
        appKey: 'test-app-key',
        timeout: 1,
    );

    try {
        $transport->connect($connection);

        $message = $transport->receive();

        expect($message)
            ->toBeArray()
            ->and($message['event'] ?? null)
            ->toBe('operation_stream.frame')
            ->and(data_get($message, 'data.payload.data'))
            ->toBe("FOLLOW-HIST-1\n");
    } finally {
        $transport->close();
        pcntl_waitpid($serverPid, $status);
        fclose($server);
    }
});
