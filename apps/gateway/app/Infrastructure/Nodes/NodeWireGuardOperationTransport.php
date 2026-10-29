<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationEffectTransport;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\OperationAgentTransport;
use App\Infrastructure\Files\ProtectedFileWriter;
use App\Infrastructure\WireGuard\WireGuardServerConfigRenderer;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/** One fixed hub command, with a fresh peer render held under the existing fleet publication lock. */
final readonly class NodeWireGuardOperationTransport implements OperationEffectTransport
{
    public function __construct(private OperationAgentTransport $agent, private NodeWireGuardProjectionLock $lock,
        private WireGuardServerConfigRenderer $renderer, private ProtectedFileWriter $files, private string $orbitHome) {}

    public function schema(Operation $operation, array $run): array|bool
    {
        return $run['name'] !== 'wireguard.project-hub' ? false : [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['configuration', 'excluded_node_id'],
            'properties' => ['excluded_node_id' => ['type' => ['integer', 'null']],
                'configuration' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['hub_id', 'address', 'port', 'private_key'],
                    'properties' => ['hub_id' => ['type' => 'integer'], 'address' => ['type' => 'string'], 'port' => ['type' => 'integer'], 'private_key' => ['type' => 'string']]]],
        ];
    }

    public function effectful(Operation $operation, array $run): bool
    {
        return $run['name'] === 'wireguard.project-hub';
    }

    public function receipt(Operation $operation, array $run, #[\SensitiveParameter] array $input): array
    {
        return $this->agent->receipt($operation, $run, []);
    }

    public function run(Operation $operation, array $run, #[\SensitiveParameter] array $input): array
    {
        return $this->lock->run(function () use ($operation, $run, $input): array {
            $configuration = ValidatedData::object($input['configuration'] ?? null);
            $hub = Node::query()->findOrFail($operation->node_id);
            $excluded = $input['excluded_node_id'] ?? null;
            if ($run['name'] !== 'wireguard.project-hub' || ($configuration['hub_id'] ?? null) !== $hub->id
                || ! $hub->roles()->where('role', RoleName::Vpn)->exists()
                || ($excluded !== null && $excluded !== ($operation->input['context_node_id'] ?? null))) {
                throw new ResourceOperationException('operation.execution_forbidden', 'The hub projection differs from admitted intent.', 422);
            }
            $peers = Node::query()->whereNotNull('wireguard_public_key');
            if ($excluded !== null) {
                $peers->whereKeyNot($excluded);
            }
            $config = $this->renderer->renderForHub($hub, ValidatedData::string($configuration['address']),
                ValidatedData::integer($configuration['port']), ValidatedData::string($configuration['private_key']), $peers->get());
            $this->files->put(rtrim($this->orbitHome, '/').'/generated/wireguard/orbit.conf', $config);
            $result = $this->agent->run($operation, $run, ['config' => $config]);

            return isset($result['error_code']) ? $result : ['projected' => true];
        });
    }
}
