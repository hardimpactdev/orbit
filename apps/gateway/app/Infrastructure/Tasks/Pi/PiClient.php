<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks\Pi;

use App\Domain\Tasks\AgentDriverException;
use App\Models\Node;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Calls the Pi server on a Node. Every failure becomes an AgentDriverException that names the
 * server's error code, never its credentials.
 */
final readonly class PiClient
{
    private const float CONNECT_TIMEOUT = 3.0;

    private const float TIMEOUT = 15.0;

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
        $exception->errorCode = is_string($code) ? $code : null;

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
