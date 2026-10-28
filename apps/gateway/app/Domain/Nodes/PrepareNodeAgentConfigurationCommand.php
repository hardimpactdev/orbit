<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use Illuminate\Support\Arr;

/** Binds agent configuration to the Gateway's proven installer receipt. Performs no Node work. */
final readonly class PrepareNodeAgentConfigurationCommand implements OperationGatewayCommand
{
    public function __construct(private OperationStore $operations) {}

    /** @return array<string, mixed> */
    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        $receipt = Arr::get($operation->state ?? [], 'executor_runs.footprint-agent-install');
        if ($operation->kind !== 'node.converge' || $input !== [] || ! is_array($receipt)
            || ($receipt['transport'] ?? null) !== 'node-agent' || ($receipt['name'] ?? null) !== 'agent.install'
            || ! is_array($receipt['result'] ?? null) || isset($receipt['result']['error_code'])
            || ($receipt['result']['installed'] ?? null) !== true || ! is_bool($receipt['result']['changed'] ?? null)
            || ! is_array(Arr::get($operation->input, '_footprint.payloads.agent'))) {
            throw new ResourceOperationException('operation.completion_unproven', 'A successful pinned agent installer receipt is required.', 409);
        }
        $this->operations->change($operation, ['state' => [...($operation->state ?? []), 'node_agent_configuration_changed' => $receipt['result']['changed']]]);

        return ['prepared' => true];
    }
}
