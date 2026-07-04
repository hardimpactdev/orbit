<?php

declare(strict_types=1);

namespace App\Services\NodeCommandTransport;

use App\Models\Node;

final readonly class NodeCommandTransportSelector
{
    public function select(
        Node $node,
        NodeCommandEnvelope $envelope,
        NodeTransportPreference $preference = NodeTransportPreference::Auto,
    ): NodeTransport {
        if (! $envelope->requiresNodeExecution) {
            return NodeTransport::GatewayOnly;
        }

        if ($preference === NodeTransportPreference::TransitionalSshFallback) {
            return NodeTransport::TransitionalSshFallback;
        }

        return $this->canUseAgentPush($node, $envelope)
            ? NodeTransport::AgentPush
            : NodeTransport::TransitionalSshFallback;
    }

    private function canUseAgentPush(Node $node, NodeCommandEnvelope $envelope): bool
    {
        return $node->isActive() && $node->orbit_agent_capable && $envelope->supportsAgentPushTransport;
    }
}
