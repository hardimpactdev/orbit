<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Operations\OperationTransport;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Node;
use App\Models\Operation;

/** The existing pinned installer, including repair of a recorded failed pin. */
final readonly class ManagedAgentInstallOperationTransport implements OperationTransport
{
    public function __construct(private AgentCommandRunner $agents) {}

    public function schema(Operation $operation, array $run): array|bool
    {
        return $run['name'] === 'agent.install'
            ? ['type' => 'object', 'additionalProperties' => false] : false;
    }

    public function run(Operation $operation, array $run, #[\SensitiveParameter] array $hydratedInput): array
    {
        $name = $run['name'];
        $input = $hydratedInput;
        if ($name !== 'agent.install' || $input !== []) {
            throw new ResourceOperationException('operation.execution_forbidden', 'Only the pinned agent installer is available.', 422);
        }

        return ['installed' => true, 'changed' => $this->agents->install(Node::query()->findOrFail($operation->node_id))];
    }
}
