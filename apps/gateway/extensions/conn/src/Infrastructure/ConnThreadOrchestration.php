<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Infrastructure;

use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\AgentView\WebSocketException;
use Orbit\Extensions\Conn\Domain\T3ServerException;
use Orbit\Extensions\Conn\Models\ConnEnvironment;

/** The Conn Server's orchestration v2 RPCs used by the librarian. */
final readonly class ConnThreadOrchestration
{
    public function __construct(private WebSocketClient $socket) {}

    public function scratchWorkspaceRoot(ConnEnvironment $environment): ?string
    {
        $root = $this->call($environment, 'server.getConfig', [])['scratchWorkspaceRoot'] ?? null;

        return is_string($root) && $root !== '' ? $root : null;
    }

    public function file(ConnEnvironment $environment, string $threadId, int $projectId, string $slug): void
    {
        $receipt = $this->call($environment, 'orchestration.dispatchCommand', [
            'type' => 'thread.metadata.update',
            'commandId' => 'orbit-librarian-'.hash('sha256', $environment->environment_id.':'.$threadId),
            'threadId' => $threadId,
            'orbitProject' => ['id' => $projectId, 'slug' => $slug],
        ]);
        if (! is_int($receipt['sequence'] ?? null)) {
            throw T3ServerException::malformed($environment->url, 'thread.metadata.update');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private function call(ConnEnvironment $environment, string $method, array $payload): array
    {
        $url = parse_url($environment->url);
        $scheme = $url['scheme'] ?? null;
        $host = $url['host'] ?? null;
        if ($host === null || ! in_array($scheme, ['http', 'https'], true)) {
            throw T3ServerException::malformed($environment->url, 'WebSocket URL');
        }
        $secure = $scheme === 'https';
        $path = rtrim($url['path'] ?? '', '/').'/ws?orchestrationProtocol=2';
        try {
            $this->socket->connect(new WebSocketEndpoint($host, $url['port'] ?? ($secure ? 443 : 80), $host, $path,
                null, $environment->admin_session, secure: $secure), 2.0);
            $this->socket->send(['_tag' => 'Request', 'id' => 1, 'tag' => $method, 'payload' => (object) $payload, 'headers' => []]);
            $deadline = microtime(true) + 2.0;
            while ($this->socket->isConnected() && ($remaining = $deadline - microtime(true)) > 0) {
                foreach ($this->socket->receive($remaining) as $message) {
                    if (($message['_tag'] ?? null) !== 'Exit' || ! in_array($message['requestId'] ?? null, [1, '1'], true)) {
                        continue;
                    }
                    $exit = $message['exit'] ?? null;
                    if (is_array($exit) && ($exit['_tag'] ?? null) === 'Success' && is_array($exit['value'] ?? null)) {
                        return $exit['value'];
                    }

                    throw T3ServerException::rejected($environment->url, $method);
                }
            }
        } catch (WebSocketException) {
            throw T3ServerException::unreachable($environment->url);
        } finally {
            $this->socket->close();
        }

        throw T3ServerException::unreachable($environment->url);
    }
}
