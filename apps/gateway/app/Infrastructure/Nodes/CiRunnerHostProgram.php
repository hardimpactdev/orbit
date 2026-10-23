<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Shared\StoredValue;
use App\Models\Node;

/** The CI role's fixed host actions, through the agent's `ci-runners.host`. */
final readonly class CiRunnerHostProgram
{
    public function __construct(private AgentCommandRunner $agents) {}

    /**
     * The outcome: `converged`, `matches`, `drift`, `occupied`, `retained`, or `removed`. A failed step refuses.
     *
     * @param  'converge'|'inspect'|'remove'|'purge'  $action
     */
    public function run(Node $node, string $action): string
    {
        return StoredValue::string($this->agents->run($node, 'ci-runners.host', ['action' => $action])['outcome'] ?? null);
    }
}
