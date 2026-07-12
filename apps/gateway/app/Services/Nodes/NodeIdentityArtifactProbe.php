<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Data\Nodes\NodeIdentityArtifact;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\WireGuardPeer;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\RemoteShell\RemoteShellSuccessData;
use App\Services\RemoteShell\RunsInternalCommands;
use RuntimeException;

final readonly class NodeIdentityArtifactProbe
{
    public function __construct(
        private ?RunsInternalCommands $localExecutor = null,
    ) {}

    public function read(Node $node): NodeIdentityArtifact
    {
        $interfacePublicKey = $this->readInterfacePublicKey($node);
        $peer = WireGuardPeer::query()
            ->where('public_key', $interfacePublicKey)
            ->first();

        $registryNode = $node;

        if ($peer instanceof WireGuardPeer) {
            $activePeerNode = $peer->node()->where('status', NodeStatus::Active->value)->first();

            if ($activePeerNode instanceof Node) {
                $registryNode = $activePeerNode;
            }
        }

        return NodeIdentityArtifact::fromArray(
            $this->registryPayload($registryNode, $peer, $interfacePublicKey),
        );
    }

    private function readInterfacePublicKey(Node $node): string
    {
        $result = $this->localExecutor()->runInternal(
            node: $node,
            commandName: 'internal:wireguard-interface-public-key:read',
            transportOptions: [
                'metadata' => [
                    'ORBIT_OPERATION_ID' => 'node-identity.wireguard-public-key',
                ],
                'timeout' => 15,
                'throw' => false,
            ],
        );

        if (! $result->successful()) {
            throw new RuntimeException(
                "Failed to read node WireGuard interface public key: {$this->failureOutput($result)}",
            );
        }

        $publicKey = RemoteShellSuccessData::fromJsonEnvelope($result)['public_key'] ?? null;

        if (! is_string($publicKey) || trim($publicKey) === '') {
            $publicKey = trim($result->stdout);

            if ($publicKey === '') {
                throw new RuntimeException(
                    'Failed to read node WireGuard interface public key: response missing public key',
                );
            }
        }

        return trim($publicKey);
    }

    /**
     * @return array{
     *     name: string|null,
     *     role: string|null,
     *     local_role: string|null,
     *     status: string|null,
     *     platform: string|null,
     *     wireguard_address: string|null,
     *     registry_public_key: string|null,
     *     interface_public_key: string|null,
     * }
     */
    private function registryPayload(?Node $node, ?WireGuardPeer $peer, string $interfacePublicKey): array
    {
        return [
            'name' => $node?->name,
            'role' => $node?->displayRole(),
            'local_role' => $node?->displayRole(),
            'status' => $node?->status?->value,
            'platform' => $node?->platform,
            'wireguard_address' => $node?->wireguard_address,
            'registry_public_key' => $peer?->public_key,
            'interface_public_key' => $interfacePublicKey,
        ];
    }

    private function localExecutor(): RunsInternalCommands
    {
        return $this->localExecutor ?? app(RemoteLocalExecutor::class);
    }

    private function failureOutput(RemoteShellResult $result): string
    {
        return trim($result->output()) ?: 'unknown error';
    }
}
