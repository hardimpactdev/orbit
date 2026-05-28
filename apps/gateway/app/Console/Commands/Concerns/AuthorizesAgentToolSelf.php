<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Models\Node;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Tools\AgentToolAuthorizer;
use App\Services\Tools\ToolRegistryFailure;

trait AuthorizesAgentToolSelf
{
    /**
     * Check agent self authorization for a tool action in CLI commands.
     */
    private function authorizeAgentToolSelfAction(?string $targetNodeName, string $tool, string $action): ?ToolRegistryFailure
    {
        if (! $this->isGatewayCaller()) {
            return null;
        }

        $localNode = $this->resolveLocalNodeForAgentSelf();

        if (! $localNode instanceof Node) {
            return null;
        }

        $authorizer = app(AgentToolAuthorizer::class);

        if (! $authorizer->isAgentSelf($localNode, $targetNodeName)) {
            return null;
        }

        $result = $authorizer->authorizeAgentSelfAction($localNode, $tool, $action);

        if (! $result['authorized']) {
            return ToolRegistryFailure::authorization($result['reason'] ?? 'Agent self is not authorized to perform this action.');
        }

        return null;
    }

    private function resolveLocalNodeForAgentSelf(): ?Node
    {
        $gateway = app(NodeRoleAssignments::class)
            ->activeGatewayNodeQuery()
            ->orderBy('name')
            ->first();

        return $gateway instanceof Node ? $gateway : null;
    }

    abstract private function isGatewayCaller(): bool;
}
