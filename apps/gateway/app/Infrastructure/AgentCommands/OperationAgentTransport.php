<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentCommands;

use App\Domain\Operations\OperationEffectTransport;
use App\Domain\Operations\OperationSecretOutputTransport;
use App\Domain\Operations\OperationTransportInput;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;

final readonly class OperationAgentTransport implements OperationEffectTransport, OperationSecretOutputTransport, OperationTransportInput
{
    public function __construct(private AgentCommandRunner $runner, private AgentContract $contract, private AgentEffectReceipts $receipts) {}

    public function schema(Operation $operation, array $run): array|bool
    {
        return AgentSchemas::inputSchema($run['name']);
    }

    public function wireInput(Operation $operation, array $run, #[\SensitiveParameter] array $input): mixed
    {
        return AgentSchemas::wireInput($input);
    }

    public function run(Operation $operation, array $run, #[\SensitiveParameter] array $input): array
    {
        return $this->runner->run((Node::query()->find($operation->node_id) ?? throw new ResourceOperationException('operation.target_missing', 'The admitted target Node no longer exists.', 409)), $run['name'], $this->effectful($operation, $run) ? AgentEffectReceipts::input($run, $input) : $input);
    }

    public function effectful(Operation $operation, array $run): bool
    {
        $command = $this->contract->command($run['name']);

        return $command->mutates;
    }

    public function receipt(Operation $operation, array $run, #[\SensitiveParameter] array $input): array
    {
        $node = (Node::query()->find($operation->node_id) ?? throw new ResourceOperationException('operation.target_missing', 'The admitted target Node no longer exists.', 409));
        $command = $this->contract->command($run['name']);
        $owner = $command->privilege($node->platform) === 'account' ? $node->user : null;

        return $this->receipts->read($node, $run['name'], $run['effect_key'], $owner);
    }
}
