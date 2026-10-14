<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Data\Nodes\NodeData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\NodeProvisioningLock;
use App\Domain\Nodes\NodeProvisioningLockException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\GatewayPeerProjectionManager;
use App\Infrastructure\WireGuard\VpnConfigurationRepository;
use App\Models\Node;

final readonly class SetNodeWireGuardKeyAction
{
    public function __construct(
        private NodeProvisioningLock $provisioningLock,
        private VpnConfigurationRepository $configuration,
        private GatewayPeerProjectionManager $gatewayPeers,
        private RecordEventBroadcaster $broadcaster,
    ) {}

    public function execute(Node $node, string $publicKey): Node
    {
        try {
            return $this->provisioningLock->run($node->name, function () use ($node, $publicKey): Node {
                $node->refresh();

                if ($node->roles()->where('role', RoleName::Gateway)->exists()) {
                    throw new ResourceOperationException('node.wireguard_key_gateway', 'Gateway WireGuard keys cannot be changed by this command.', 409);
                }

                if (is_string($node->wireguard_ip) && $node->wireguard_ip !== ''
                    && is_string($node->ssh_host_fingerprint) && $node->ssh_host_fingerprint !== '') {
                    throw new ResourceOperationException('node.wireguard_key_managed', 'Managed Node WireGuard keys cannot be changed by this command.', 409);
                }

                if (! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
                    throw new ResourceOperationException('node.wireguard_ip_required', 'The Node needs an existing WireGuard address.');
                }

                $this->configuration->assignPeerKey($node, $publicKey);
                $result = $node->refresh()->load('roles');
                $this->broadcaster->broadcast(RecordEventType::NodeUpdated, $result->id, NodeData::fromModel($result)->toArray());
                $this->gatewayPeers->converge($result);

                return $result;
            });
        } catch (NodeProvisioningException $exception) {
            // Projection output can contain hub key material. Keep only the safe failure contract.
            throw new ResourceOperationException($exception->errorCode, 'Could not converge the gateway WireGuard service.', details: ['step' => $exception->step]);
        } catch (NodeProvisioningLockException $exception) {
            throw $exception->toBusyException();
        }
    }
}
