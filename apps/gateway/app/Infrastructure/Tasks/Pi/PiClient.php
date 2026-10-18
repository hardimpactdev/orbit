<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks\Pi;

use App\Domain\Tasks\AgentDriverException;
use App\Models\Node;
use App\Support\ValidatedData;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Calls the Pi server on a Node. Every failure becomes an AgentDriverException that names the
 * server's error code, never its credentials.
 */
final readonly class PiClient
{
    private const float CONNECT_TIMEOUT = 3.0;

    private const float TIMEOUT = 15.0;

    private const float STREAM_SECONDS = 20.0;

    private const float STREAM_READ_TIMEOUT = 5.0;

    /**
     * The largest session body the Gateway accepts. Real Pi transcripts reach about 9 MiB, and decoding one takes about
     * twice its size. A larger body stops the transfer and fails, so it never fills a PHP worker's memory.
     */
    public const int SESSION_MAX_BYTES = 16 * 1024 * 1024;

    /** A viewer's read waits at most this long, the stream's read timeout, so it holds a PHP worker briefly. */
    public const float READ_TIMEOUT = self::STREAM_READ_TIMEOUT;

    public function __construct(private PiConnection $connection) {}

    /** @param array{id: string, cwd: string, model: string, thinkingLevel: string, appendSystemPrompt: string|null} $session */
    public function create(Node $node, array $session): void
    {
        $this->ensure($this->call(fn (): Response => $this->request($node)->post($this->url($node, 'sessions'), $session)), $node);
    }

    /** Starts a turn. Returns true when the server had already accepted this key. */
    public function send(Node $node, string $sessionId, string $key, string $text): bool
    {
        $response = $this->ensure($this->call(fn (): Response => $this->request($node)->post(
            $this->url($node, 'sessions', $sessionId, 'messages'),
            ['key' => $key, 'text' => $text],
        )), $node);

        return $response->json('duplicate') === true;
    }

    public function interrupt(Node $node, string $sessionId): void
    {
        $this->ensure($this->call(fn (): Response => $this->request($node)->post($this->url($node, 'sessions', $sessionId, 'interrupt'))), $node);
    }

    /** @return array<string, mixed> */
    public function snapshot(Node $node, string $sessionId): array
    {
        $payload = $this->session($node, $sessionId, null, self::TIMEOUT);
        if (($payload['kind'] ?? null) !== 'snapshot') {
            throw new AgentDriverException('The Pi server returned an invalid snapshot.');
        }

        return $payload;
    }

    /**
     * Reads a session for a viewer within {@see READ_TIMEOUT}. With a cursor from the server's current run, the server
     * answers a `delta`: the events a stream would replay after the cursor, and the current state. Otherwise it answers
     * a `snapshot`, as a server built before deltas always does.
     *
     * @param  array{run: string, sequence: int}|null  $after
     * @return array<string, mixed>
     */
    public function read(Node $node, string $sessionId, ?array $after): array
    {
        $payload = $this->session($node, $sessionId, $after, self::READ_TIMEOUT);
        if (! in_array($payload['kind'] ?? null, ['snapshot', 'delta'], true)) {
            throw new AgentDriverException('The Pi server returned an invalid snapshot.');
        }

        return $payload;
    }

    /**
     * Streams one bounded connection. Without a cursor it starts with a snapshot. With a cursor
     * from the server's current run it starts with a `resumed` event and the events after the
     * cursor; the server sends a snapshot instead when it cannot resume. Then entry and state
     * events follow. Yields a heartbeat whenever no event arrives within the read timeout.
     *
     * @param  array{run: string, sequence: int}|null  $after
     * @return iterable<array<string, mixed>>
     */
    public function stream(Node $node, string $sessionId, ?array $after = null): iterable
    {
        // HTTP/1.0 keeps the response unchunked. PHP's dechunk filter holds small NDJSON lines
        // until 8 KiB arrive, which would stall every event behind the next heartbeats.
        $response = $this->ensure($this->call(fn (): Response => $this->request($node)
            ->timeout(0)
            ->withOptions(['stream' => true, 'read_timeout' => self::STREAM_READ_TIMEOUT, 'version' => '1.0'])
            ->accept('application/x-ndjson')
            ->get($this->url($node, 'sessions', $sessionId, 'stream'), $after === null ? [] : ['run' => $after['run'], 'after' => $after['sequence']])), $node);
        $body = $response->toPsrResponse()->getBody();

        try {
            yield from new PiEventLines(self::STREAM_SECONDS)->read($body);
        } finally {
            $body->close();
        }
    }

    /**
     * Gets `GET /sessions/{id}`, bounded by {@see SESSION_MAX_BYTES}. The transfer stops once the body passes the limit,
     * and the body is decoded only when it fits.
     *
     * @param  array{run: string, sequence: int}|null  $after
     * @return array<string, mixed>
     */
    private function session(Node $node, string $sessionId, ?array $after, float $timeout): array
    {
        $response = $this->call(fn (): Response => $this->request($node)
            ->timeout($timeout)
            // Decoding stays off, so the progress counts are the body's own bytes.
            ->withOptions(['decode_content' => false, 'progress' => static function (int $total, int $received): void {
                if (max($total, $received) > self::SESSION_MAX_BYTES) {
                    throw self::tooLarge();
                }
            }])
            ->get($this->url($node, 'sessions', $sessionId), $after === null ? [] : ['run' => $after['run'], 'after' => $after['sequence']]));
        if (($response->toPsrResponse()->getBody()->getSize() ?? 0) > self::SESSION_MAX_BYTES) {
            throw self::tooLarge();
        }
        try {
            return ValidatedData::object($this->ensure($response, $node)->json());
        } catch (InvalidArgumentException) {
            throw new AgentDriverException('The Pi server returned an invalid snapshot.');
        }
    }

    private static function tooLarge(): AgentDriverException
    {
        return new AgentDriverException('The Pi server session is larger than '.intdiv(self::SESSION_MAX_BYTES, 1024 * 1024).' MiB.');
    }

    /** @param callable(): Response $send */
    private function call(callable $send): Response
    {
        try {
            return $send();
        } catch (ConnectionException $exception) {
            // A transfer that a callback stopped carries that callback's reason.
            for ($cause = $exception->getPrevious(); $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof AgentDriverException) {
                    throw $cause;
                }
            }

            throw new AgentDriverException('The Pi server is unavailable.');
        } catch (RequestException $exception) {
            // An error status whose body transfer stopped, such as at the size limit or the timeout.
            throw new AgentDriverException('The Pi server failed with HTTP '.$exception->response->status().'.');
        }
    }

    private function ensure(Response $response, Node $node): Response
    {
        if ($response->successful()) {
            return $response;
        }
        $code = $response->json('error.code');
        $message = $response->json('error.message');

        $exception = new AgentDriverException(is_string($code) && is_string($message)
            ? 'The Pi server refused the request ('.$code.'): '.$message
            : 'The Pi server failed with HTTP '.$response->status().'.');
        $exception->turnRejected = $response->clientError() && is_string($code);

        throw $exception;
    }

    private function request(Node $node): PendingRequest
    {
        return Http::connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::TIMEOUT)
            ->withoutRedirecting()
            ->acceptJson()
            ->asJson()
            ->withToken($this->connection->token($node));
    }

    private function url(Node $node, string ...$segments): string
    {
        return $this->connection->baseUrl($node).'/'.implode('/', array_map(rawurlencode(...), $segments));
    }
}
