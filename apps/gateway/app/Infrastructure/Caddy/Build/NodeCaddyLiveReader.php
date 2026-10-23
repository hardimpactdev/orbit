<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy\Build;

use App\Models\Node;

/**
 * Reads a Node's live `/etc/caddy/Caddyfile` through its symlink. A Node Caddy build keeps every site in
 * that one file, so nothing it imports is read. A missing file reads as empty. It changes nothing on the Node.
 */
final readonly class NodeCaddyLiveReader
{
    public function __construct(
        private NodeCaddyTransport $transport,
    ) {}

    public function read(Node $node, float $timeout = 60.0): string
    {
        $result = $this->transport->run($node, 'caddy.read-live', timeout: $timeout);

        if (! is_string($result['content'] ?? null)) {
            throw new NodeCaddyBuildException($node->name, 'read-live', 'The agent returned no live Caddyfile.');
        }

        return $result['content'];
    }
}
