<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy\Build;

use App\Models\Node;

/**
 * Builds and pushes one Node's whole Caddyfile (ADR 0141). A caller commits its state change first
 * and never calls this inside a database transaction, so a concurrent build on the same Node reads
 * the change and a rollback never leaves a pushed site without a record.
 */
final readonly class NodeCaddyBuilder implements NodeCaddyBuilds
{
    public function __construct(
        private NodeCaddyfileRenderer $renderer,
        private NodeCaddyBuildLock $lock,
        private NodeCaddyTransport $transport,
    ) {}

    public function build(Node $node): NodeCaddyBuildResult
    {
        return $this->lock->run($node->id, $node->name, function () use ($node): NodeCaddyBuildResult {
            // Stored state is read only once the lock is held, so the last build reflects the last commit.
            $current = Node::query()->find($node->id);

            if (! $current instanceof Node) {
                throw new NodeCaddyBuildException($node->name, 'render', 'The Node no longer exists.');
            }

            $caddyfile = $this->renderer->render($current);

            if (! $caddyfile->buildable()) {
                throw new NodeCaddyBuildException($current->name, 'render', implode(' ', $caddyfile->problems));
            }

            $result = $this->transport->run($current, 'caddy.publish', [
                'content' => $caddyfile->content,
                'version' => $caddyfile->version,
                'listen_addresses' => $caddyfile->listenAddresses,
                'unserved' => $caddyfile->sites === [] || ! CaddySiteRoles::nodeExpectsCaddy($current->id),
            ]);

            return $result['result'] === 'unchanged'
                ? NodeCaddyBuildResult::Unchanged
                : NodeCaddyBuildResult::Published;
        });
    }

    public function checkListenAddresses(Node $node): void
    {
        $caddyfile = $this->renderer->render($node);

        if (! $caddyfile->buildable()) {
            throw new NodeCaddyBuildException($node->name, 'render', implode(' ', $caddyfile->problems));
        }

        if ($caddyfile->listenAddresses === []) {
            return;
        }

        $this->transport->run($node, 'caddy.check-addresses', ['listen_addresses' => $caddyfile->listenAddresses]);
    }
}
