<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Operations\OperationEffectTransport;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/** The existing pinned installer, including repair of a recorded failed pin. */
final readonly class ManagedAgentInstallOperationTransport implements OperationEffectTransport
{
    public function __construct(private NodeOperationAgentInstaller $agents) {}

    public function schema(Operation $operation, array $run): array|bool
    {
        return $run['name'] === 'agent.install'
            ? ['type' => 'object', 'additionalProperties' => false, 'required' => ['pin'],
                'properties' => ['pin' => ['type' => 'string', 'pattern' => '^[0-9a-f]{64}$']]] : false;
    }

    public function run(Operation $operation, array $run, #[\SensitiveParameter] array $hydratedInput): array
    {
        $name = $run['name'];
        $input = $hydratedInput;
        if ($name !== 'agent.install' || array_keys($input) !== ['pin'] || ! is_string($input['pin'])) {
            throw new ResourceOperationException('operation.execution_forbidden', 'Only the pinned agent installer is available.', 422);
        }

        return ['installed' => true, 'changed' => $this->agents->installEffect(Node::query()->findOrFail($operation->node_id), $input['pin'], $run)];
    }

    public function effectful(Operation $operation, array $run): bool
    {
        return $run['name'] === 'agent.install';
    }

    public function receipt(Operation $operation, array $run, #[\SensitiveParameter] array $input): array
    {
        $receipt = $this->agents->effectReceipt(Node::query()->findOrFail($operation->node_id),
            ValidatedData::string(ValidatedData::object($operation->input['_footprint'] ?? null)['agent_pin'] ?? null), $run['name'], $run['effect_key']);
        if ($receipt['state'] === 'succeeded') {
            if (! is_bool($receipt['result']['replaced'] ?? null)) {
                throw new ResourceOperationException('operation.receipt_unproven', 'The installer receipt has no original replacement result.', 409);
            }
            $receipt['result'] = ['installed' => true, 'changed' => ($receipt['result']['replaced'] ?? null) === true];
        }

        return $receipt;
    }
}
