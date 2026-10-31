<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Caddy\Build\CaddySiteRoles;
use App\Infrastructure\Caddy\Build\NodeCaddyfile;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Models\Node;

/** The complete Node publication input, including role-dependent serving state. */
final readonly class CaddyPublicationPlan
{
    /** Public current-state data only; the native caller holds the Node publication lock.
     * @return array<string, mixed>
     */
    public static function forNode(Node $node): array
    {
        $identity = ['node_id' => $node->id, 'user' => $node->user, 'agent_sha256' => app(AgentDelivery::class)->pin($node)];
        $render = app(NodeCaddyfileRenderer::class)->render($node);

        return [...$identity, ...($render->buildable()
            ? ['caddy' => self::input($render, $node->id)]
            : ['error_code' => 'caddy.render_failed', 'stage' => 'render'])];
    }

    /** @return array{content: string, version: string, listen_addresses: list<string>, unserved: bool} */
    public static function input(NodeCaddyfile $render, int $nodeId): array
    {
        return ['content' => $render->content, 'version' => $render->version, 'listen_addresses' => $render->listenAddresses,
            'unserved' => $render->sites === [] || ! CaddySiteRoles::nodeExpectsCaddy($nodeId)];
    }
}
