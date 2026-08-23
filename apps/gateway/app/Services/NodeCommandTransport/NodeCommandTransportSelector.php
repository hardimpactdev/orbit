<?php

declare(strict_types=1);

namespace App\Services\NodeCommandTransport;

use App\Enums\Nodes\NodeRoleName;
use App\Models\Node;
use RuntimeException;

final readonly class NodeCommandTransportSelector
{
    public function select(
        Node $node,
        NodeCommandEnvelope $envelope,
    ): NodeTransport {
        if (
            $node->hasActiveRole(NodeRoleName::Gateway->value)
            || ! $envelope->requiresNodeExecution
        ) {
            return NodeTransport::GatewayOnly;
        }

        if ($this->canUseAgentPush($node, $envelope)) {
            return NodeTransport::AgentPush;
        }

        throw new RuntimeException('agent-push transport is unavailable');
    }

    private function canUseAgentPush(Node $node, NodeCommandEnvelope $envelope): bool
    {
        if (! $envelope->supportsAgentPushTransport) {
            return false;
        }

        if ($node->isAgentEligible()) {
            return true;
        }

        return $node->isFleetUpdateEligible() && $this->isFleetUpdateCommand($envelope);
    }

    private function isFleetUpdateCommand(NodeCommandEnvelope $envelope): bool
    {
        if (str_starts_with($envelope->commandId, 'internal:fleet-update:')) {
            return true;
        }

        $command = $envelope->agentPushCommand;
        $argv = $command instanceof NodeAgentPushCommand ? $command->argv : [];
        $commandName = $argv[0] ?? null;

        return is_string($commandName) && str_starts_with($commandName, 'internal:fleet-update:');
    }
}
