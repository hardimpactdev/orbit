<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Exceptions\GatewayApiException;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
class RawOperationStreamWebSocketTransport implements OperationStreamWebSocketTransport
{
    /**
     * @var resource|null
     */
    private $stream = null;

    private ?OperationStreamWebSocketConnection $connection = null;

    #[\Override]
    public function connect(OperationStreamWebSocketConnection $connection): void
    {
        $this->connection = $connection;
        $transport = $connection->scheme === 'wss' ? 'tls' : 'tcp';
        $context = $this->streamContext($connection);
        $error = '';
        $errno = 0;
        set_error_handler(static function (int $_severity, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });

        try {
            $stream = stream_socket_client(
                "{$transport}://{$connection->host}:{$connection->port}",
                $errno,
                $error,
                $connection->timeout,
                STREAM_CLIENT_CONNECT,
                $context,
            );
        } finally {
            restore_error_handler();
        }

        if (! is_resource($stream)) {
            throw GatewayApiException::networkError(new ConnectionException(
                "Unable to connect to operations websocket: {$error} ({$errno})",
            ));
        }

        stream_set_timeout($stream, $connection->timeout);
        $this->stream = $stream;
        $this->handshake();
    }

    /**
     * @param  array<string, mixed>  $message
     */
    #[\Override]
    public function send(array $message): void
    {
        $this->writeFrame(json_encode($message, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>|null
     */
    #[\Override]
    public function receive(): ?array
    {
        $payload = $this->readFrame();

        if ($payload === null) {
            return null;
        }

        $decoded = json_decode($payload, associative: true);

        if (! is_array($decoded)) {
            throw GatewayApiException::streamMalformed(
                new RuntimeException('Operations websocket returned invalid JSON.'),
            );
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    #[\Override]
    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }

        $this->stream = null;
        $this->connection = null;
    }

    /**
     * @return resource
     */
    private function stream(): mixed
    {
        if (! is_resource($this->stream)) {
            throw GatewayApiException::networkError(new ConnectionException('Operations websocket is not connected.'));
        }

        return $this->stream;
    }

    /**
     * @return resource
     */
    private function streamContext(OperationStreamWebSocketConnection $connection): mixed
    {
        $options = [];

        if ($connection->scheme === 'wss' && is_string($connection->caPemPath) && is_file($connection->caPemPath)) {
            $options['ssl'] = ['cafile' => $connection->caPemPath];
        }

        return stream_context_create($options);
    }

    private function handshake(): void
    {
        $connection = $this->connection;

        if (! $connection instanceof OperationStreamWebSocketConnection) {
            throw GatewayApiException::networkError(
                new ConnectionException('Operations websocket connection is missing.'),
            );
        }

        $key = base64_encode(random_bytes(16));
        $path = '/app/'.rawurlencode($connection->appKey).'?protocol=7&client=orbit-cli&version=1.0&flash=false';
        $request = implode("\r\n", [
            "GET {$path} HTTP/1.1",
            "Host: {$connection->host}:{$connection->port}",
            'Upgrade: websocket',
            'Connection: Upgrade',
            "Sec-WebSocket-Key: {$key}",
            'Sec-WebSocket-Version: 13',
            "\r\n",
        ]);

        fwrite($this->stream(), $request);

        $response = '';

        while (! str_contains($response, "\r\n\r\n")) {
            $chunk = fgets($this->stream());

            if ($chunk === false) {
                break;
            }

            $response .= $chunk;
        }

        if (! str_starts_with($response, 'HTTP/1.1 101') && ! str_starts_with($response, 'HTTP/1.0 101')) {
            throw GatewayApiException::networkError(new ConnectionException('Operations websocket handshake failed.'));
        }
    }

    private function writeFrame(string $payload): void
    {
        $length = strlen($payload);
        $head = chr(0x81);

        if ($length <= 125) {
            $head .= chr(0x80 | $length);
        } elseif ($length <= 65_535) {
            $head .= chr(0x80 | 126).pack('n', $length);
        } else {
            $head .= chr(0x80 | 127).pack('J', $length);
        }

        $mask = random_bytes(4);
        $masked = '';

        for ($i = 0; $i < $length; $i++) {
            $masked .= $payload[$i] ^ $mask[$i % 4];
        }

        fwrite($this->stream(), $head.$mask.$masked);
    }

    private function readFrame(): ?string
    {
        $header = $this->readBytes(2);

        if ($header === null) {
            return null;
        }

        $first = ord($header[0]);
        $second = ord($header[1]);
        $opcode = $first & 0x0f;
        $masked = ($second & 0x80) === 0x80;
        $length = $second & 0x7f;
        $lengthCode = $length;

        if ($opcode === 0x8) {
            return null;
        }

        if ($lengthCode === 126) {
            $length = unpack(
                'n',
                $this->readBytes(2) ?? throw GatewayApiException::networkError(
                    new ConnectionException('Operations websocket closed mid-frame.'),
                ),
            )[1];
        }

        if ($lengthCode === 127) {
            $parts = unpack(
                'N2',
                $this->readBytes(8) ?? throw GatewayApiException::networkError(
                    new ConnectionException('Operations websocket closed mid-frame.'),
                ),
            );
            $length = ($parts[1] << 32) + $parts[2];
        }

        $mask = $masked
            ? $this->readBytes(4) ?? throw GatewayApiException::networkError(
                new ConnectionException('Operations websocket closed mid-frame.'),
            )
            : null;
        $payload = $this->readBytes($length) ?? throw GatewayApiException::networkError(
            new ConnectionException('Operations websocket closed mid-frame.'),
        );

        if ($mask === null) {
            return $payload;
        }

        $unmasked = '';

        for ($i = 0; $i < $length; $i++) {
            $unmasked .= $payload[$i] ^ $mask[$i % 4];
        }

        return $unmasked;
    }

    /**
     * Read exactly $length bytes, continuing through blocking read timeouts.
     *
     * Returns null only when the peer closes before any byte arrives (clean
     * EOF before a frame). A close after any byte is a mid-frame error. Idle
     * fread empty/false with stream meta timed_out retries on the next blocking
     * timeout rather than spinning.
     */
    private function readBytes(int $length): ?string
    {
        $buffer = '';

        while (strlen($buffer) < $length) {
            $stream = $this->stream();
            $chunk = fread($stream, $length - strlen($buffer));
            $meta = stream_get_meta_data($stream);

            if ($chunk === false || $chunk === '') {
                if ($meta['timed_out'] === true) {
                    continue;
                }

                if ($buffer === '') {
                    return null;
                }

                throw GatewayApiException::networkError(
                    new ConnectionException('Operations websocket closed mid-frame.'),
                );
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }
}
