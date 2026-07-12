<?php

declare(strict_types=1);

namespace App\Services\NodeCommandTransport;

use App\Enums\Nodes\NodeRoleName;
use App\Models\Node;
use RuntimeException;

final readonly class NodeCommandTransportSelector
{
    // @orbit-ssh-lane transitional-ssh
    public function select(
        Node $node,
        NodeCommandEnvelope $envelope,
        NodeTransportPreference $preference = NodeTransportPreference::Auto,
    ): NodeTransport {
        if (
            $node->hasActiveRole(NodeRoleName::Gateway->value)
            || ! $envelope->requiresNodeExecution
        ) {
            return NodeTransport::GatewayOnly;
        }

        if ($preference === NodeTransportPreference::TransitionalSshFallback) {
            return NodeTransport::TransitionalSshFallback;
        }

        if ($this->canUseAgentPush($node, $envelope)) {
            return NodeTransport::AgentPush;
        }

        throw new RuntimeException('agent-push transport is unavailable');
    }

    private function canUseAgentPush(Node $node, NodeCommandEnvelope $envelope): bool
    {
        return $node->isAgentEligible() && $envelope->supportsAgentPushTransport;
    }
}
