<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentCommands;

use App\Domain\Operations\OperationTransport;
use App\Models\Node;
use App\Models\Operation;

final readonly class OperationAgentTransport implements OperationTransport
{
    public function __construct(private AgentCommandRunner $runner) {}

    public function schema(Operation $operation, array $run): array|bool
    {
        return AgentSchemas::inputSchema($run['name']);
    }

    public function run(Operation $operation, array $run, #[\SensitiveParameter] array $input): array
    {
        return $this->runner->run(Node::query()->findOrFail($operation->node_id), $run['name'], $input);
    }
}
