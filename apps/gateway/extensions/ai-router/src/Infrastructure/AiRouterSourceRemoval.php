<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Infrastructure;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Models\Node;

/** Removes source left by the interpreted collector through its fixed native command. */
final readonly class AiRouterSourceRemoval
{
    public function __construct(private AgentCommandRunner $agents) {}

    public function remove(Node $node, bool $retired = false): void
    {
        if (! is_string($node->wireguard_ip) || filter_var($node->wireguard_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new ResourceOperationException('ai_router.node_invalid', "Node [{$node->name}] has no valid WireGuard IPv4 address.", 422);
        }
        try {
            $result = $this->agents->run($node, 'ai-router.remove-source', ['retired' => $retired]);
        } catch (AgentCommandRefused|AgentRunnerFailure $error) {
            throw new ResourceOperationException('ai_router.source_removal_failed', 'Could not remove the retired collector source.', 422, previous: $error);
        }
        if (! is_bool($result['removed'] ?? null)) {
            throw new ResourceOperationException('ai_router.source_removal_failed', 'The agent returned no collector source removal result.', 422);
        }
    }
}
