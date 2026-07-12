<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Models\Node;
use App\Services\RemoteShell\RemoteLocalExecutor;
use Throwable;

final readonly class OperatorNodeManager
{
    public function __construct(
        private RemoteLocalExecutor $localExecutor,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function manage(Node $node, string $user, string $platform): array
    {
        if (! $node->isActive() || ! $node->isOperator()) {
            throw new OperatorNodeManagementException(
                'node.not_operator',
                'Only active roleless nodes can opt into managed Agent execution.',
            );
        }

        $this->wireguardAddress($node);
        $original = $node->only(['user', 'platform', 'managed']);

        $node->forceFill([
            'user' => $user,
            'platform' => $platform,
            'managed' => true,
        ])->save();

        try {
            /** @var Node $managedNode */
            $managedNode = $node->fresh();
            $result = $this->localExecutor->runInternal(
                node: $managedNode,
                commandName: 'internal:agent-runtime:probe',
                transportOptions: [
                    'metadata' => ['ORBIT_OPERATION_ID' => 'node.manage.agent-probe'],
                    'throw' => false,
                ],
            );
        } catch (Throwable $exception) {
            $node->forceFill($original)->save();

            throw new OperatorNodeManagementException('node.agent_unreachable', $exception->getMessage());
        }

        if (! $result->successful()) {
            $node->forceFill($original)->save();

            throw new OperatorNodeManagementException(
                'node.agent_unreachable',
                'Gateway Agent-push reachability check failed.',
            );
        }

        return [
            'node' => $node->name,
            'user' => $node->user,
            'platform' => $node->platform,
            'managed' => true,
            'agent_verified' => true,
        ];
    }

    private function wireguardAddress(Node $node): string
    {
        $address = is_string($node->wireguard_address) ? trim($node->wireguard_address) : '';

        if ($address === '') {
            throw new OperatorNodeManagementException(
                'node.wireguard_address_missing',
                'Node has no WireGuard address for Agent push.',
            );
        }

        return $address;
    }
}
