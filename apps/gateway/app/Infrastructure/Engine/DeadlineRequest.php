<?php

declare(strict_types=1);

namespace App\Infrastructure\Engine;

use Basis\Nats\NKeys\SecretKey;
use JsonException;
use SensitiveParameter;

/**
 * One core NATS request on a connection of its own, every wait of which ends by one absolute deadline (`hrtime` seconds): the
 * connect, the server's greeting, the authenticated handshake (CONNECT, then PING answered by PONG), the request and its reply
 * (docs/reference/executor.md#signed-reads). The PHP client gives each of these waits its full timeout, so a slow peer could hold
 * a read past its budget.
 *
 * The answer tells a request that never left from one whose reply is missing. [NotSent] is thrown before the request was
 * written, including when nobody subscribes to its subject (`503` no responders); null is returned once it was written and no
 * reply came by the deadline, also when the connection closed first.
 */
final class DeadlineRequest
{
    /** @var resource|null */
    private $socket = null;

    private string $buffer = '';

    private function __construct(private readonly float $deadline) {}

    /**
     * The reply's body, or null when the request went out and no reply came by `$deadline`.
     *
     * @throws NotSent when the request was not written.
     */
    public static function send(string $host, int $port, #[SensitiveParameter] string $seed, string $subject, string $payload, float $deadline): ?string
    {
        $request = new self($deadline);
        try {
            $request->handshake($host, $port, $seed);

            return $request->exchange($subject, $payload);
        } finally {
            if (is_resource($request->socket)) {
                fclose($request->socket);
            }
        }
    }

    private function left(): float
    {
        return $this->deadline - hrtime(true) / 1e9;
    }

    private function handshake(string $host, int $port, #[SensitiveParameter] string $seed): void
    {
        if ($this->left() < 0.001) {
            throw new NotSent('engine.deadline_passed');
        }
        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $error, max(0.001, $this->left()));
        if ($socket === false) {
            throw new NotSent('engine.connect_failed');
        }
        $this->socket = $socket;
        stream_set_blocking($socket, false);
        $info = $this->line() ?? throw new NotSent('engine.greeting_missing');
        if (! str_starts_with($info, 'INFO ')) {
            throw new NotSent('engine.greeting_invalid');
        }
        try {
            $server = json_decode(substr($info, 5), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new NotSent('engine.greeting_invalid');
        }
        $key = SecretKey::fromSeed($seed);
        $connect = ['verbose' => false, 'pedantic' => false, 'lang' => 'php', 'version' => 'orbit', 'protocol' => 1, 'headers' => true,
            'no_responders' => true];
        if (is_array($server) && is_string($server['nonce'] ?? null)) {
            $connect['nkey'] = $key->getPublicKey();
            $secret = $key->value;
            if ($secret === '') {
                throw new NotSent('engine.key_invalid');
            }
            $connect['sig'] = base64_encode(sodium_crypto_sign_detached($server['nonce'], $secret));
        }
        $this->write('CONNECT '.json_encode($connect, JSON_THROW_ON_ERROR)."\r\nPING\r\n") ?: throw new NotSent('engine.handshake_unsent');
        while (($line = $this->line()) !== 'PONG') {
            if ($line === null || str_starts_with($line, '-ERR')) {
                throw new NotSent($line === null ? 'engine.handshake_missing' : 'engine.handshake_refused');
            }
        }
    }

    private function exchange(string $subject, string $payload): ?string
    {
        $inbox = '_INBOX.'.bin2hex(random_bytes(12));
        if (! $this->write("SUB {$inbox} 1\r\n")) {
            throw new NotSent('engine.request_unsent');
        }
        // From here the request may have reached the server: a missing reply is a lost reply, not a failure to send.
        if (! $this->write("PUB {$subject} {$inbox} ".strlen($payload)."\r\n{$payload}\r\n")) {
            return null;
        }
        while (($line = $this->line()) !== null) {
            // `MSG <subject> <sid> [reply] <bytes>` or `HMSG <subject> <sid> [reply] <header bytes> <bytes>`, on our one subscription.
            $fields = explode(' ', $line);
            $headed = $fields[0] === 'HMSG';
            $sizes = $headed ? array_slice($fields, -2) : array_slice($fields, -1);
            if (! in_array($fields[0], ['MSG', 'HMSG'], true) || ($fields[2] ?? null) !== '1' || ! in_array(count($fields), $headed ? [5, 6] : [4, 5], true)
                || array_filter($sizes, static fn (string $size): bool => ! ctype_digit($size)) !== []) {
                continue;
            }
            $body = $this->bytes((int) end($sizes));
            if ($body === null) {
                return null;
            }
            if (! $headed) {
                return $body;
            }
            $headers = substr($body, 0, (int) $sizes[0]);
            if (preg_match('/\ANATS\/1\.0 503\b/', $headers) === 1) {
                throw new NotSent('engine.no_responders');
            }

            return substr($body, (int) $sizes[0]);
        }

        return null;
    }

    /**
     * One protocol line without its CRLF; the server's PING is answered on the way. Null at the deadline or a closed connection.
     *
     * @phpstan-impure
     */
    private function line(): ?string
    {
        while (true) {
            $end = strpos($this->buffer, "\r\n");
            if ($end !== false) {
                $line = substr($this->buffer, 0, $end);
                $this->buffer = substr($this->buffer, $end + 2);
                if ($line === 'PING') {
                    $this->write("PONG\r\n");

                    continue;
                }

                return $line;
            }
            if (! $this->fill()) {
                return null;
            }
        }
    }

    /**
     * A message's `$length` bytes and their CRLF; null at the deadline or a closed connection.
     *
     * @phpstan-impure
     */
    private function bytes(int $length): ?string
    {
        while (strlen($this->buffer) < $length + 2) {
            if (! $this->fill()) {
                return null;
            }
        }
        $body = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length + 2);

        return $body;
    }

    private function fill(): bool
    {
        $left = $this->left();
        if ($left <= 0 || ! is_resource($this->socket)) {
            return false;
        }
        $read = [$this->socket];
        $none = null;
        $ready = @stream_select($read, $none, $none, (int) floor($left), (int) (fmod($left, 1.0) * 1e6));
        if ($ready !== 1) {
            return false;
        }
        $chunk = fread($this->socket, 65536);
        if ($chunk === false || ($chunk === '' && feof($this->socket))) {
            return false;
        }
        $this->buffer .= $chunk;

        return true;
    }

    /** Writes all of `$data` by the deadline. */
    private function write(string $data): bool
    {
        while ($data !== '') {
            $left = $this->left();
            if ($left <= 0 || ! is_resource($this->socket)) {
                return false;
            }
            $none = null;
            $write = [$this->socket];
            if (@stream_select($none, $write, $none, (int) floor($left), (int) (fmod($left, 1.0) * 1e6)) !== 1) {
                return false;
            }
            $written = @fwrite($this->socket, $data);
            if ($written === false || $written === 0) {
                return false;
            }
            $data = substr($data, $written);
        }

        return true;
    }
}
