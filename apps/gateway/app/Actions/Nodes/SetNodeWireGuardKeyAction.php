<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Data\Nodes\NodeData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Nodes\NodeProvisioningLock;
use App\Domain\Nodes\NodeProvisioningLockException;
use App\Domain\Nodes\NodeWireGuardKeyPolicy;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\AwaitOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\WireGuard\VpnConfigurationRepository;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

final readonly class SetNodeWireGuardKeyAction
{
    public function __construct(private NodeProvisioningLock $provisioningLock, private VpnConfigurationRepository $configuration,
        private NodeWireGuardKeyPolicy $policy, private StartOperationAction $operations, private RecordEventBroadcaster $broadcaster) {}

    public function start(Node $node, string $publicKey, ?string $requestId = null): Operation
    {
        try {
            return $this->provisioningLock->run($node->name, function () use ($node, $publicKey, $requestId): Operation {
                $node->refresh();
                $this->policy->assertMutable($node);
                $hub = Node::query()->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Vpn))->first();
                if (! $hub instanceof Node) {
                    throw new ResourceOperationException('vpn.configuration_invalid', 'The VPN role has no configured server node.', 422);
                }

                $operation = null;
                $this->configuration->assignPeerKey($node, $publicKey, function () use ($node, $publicKey, $hub, $requestId, &$operation): void {
                    DB::transaction(function () use ($node, $publicKey, $hub, $requestId, &$operation): void {
                        $operation = $this->operations->execute('node.wireguard_key', 'node', $node->id, $hub->id,
                            ['context_node_id' => $node->id, 'excluded_node_id' => null, 'public_key' => $publicKey], 'operator', $requestId);
                        $node->update(['wireguard_public_key' => $publicKey]);
                        $this->broadcaster->broadcast(RecordEventType::NodeUpdated, $node->id, NodeData::fromModel($node->refresh()->load('roles'))->toArray());
                    });
                });
                if (! $operation instanceof Operation) {
                    throw new \LogicException('WireGuard key admission did not record an Operation.');
                }

                return $operation;
            });
        } catch (NodeProvisioningLockException $exception) {
            throw $exception->toBusyException();
        }
    }

    public function execute(Node $node, string $publicKey): Node
    {
        app(AwaitOperation::class)->execute($this->start($node, $publicKey));

        return $node->refresh()->load('roles');
    }
}
