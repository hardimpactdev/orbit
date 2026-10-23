<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy\Build;

use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Models\Node;

/** Maps the agent's typed Caddy results into the build's public failures. */
final readonly class NodeCaddyTransport
{
    public function __construct(private AgentCommandRunner $agents) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function run(Node $node, string $command, array $input = [], ?float $timeout = null): array
    {
        try {
            return $this->agents->run($node, $command, $input, $timeout);
        } catch (AgentCommandRefused $exception) {
            throw new NodeCaddyBuildException(
                $node->name,
                is_string($exception->details['stage'] ?? null) ? $exception->details['stage'] : 'connect',
                is_string($exception->details['message'] ?? null) ? $exception->details['message'] : $exception->getMessage(),
            );
        } catch (AgentRunnerFailure $exception) {
            throw new NodeCaddyBuildException($node->name, 'connect', $exception->getMessage());
        }
    }
}
