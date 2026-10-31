<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\NodeProvisioningLock;
use App\Domain\Nodes\NodeProvisioningLockException;
use App\Domain\Nodes\NodeWireGuardCommandInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\WireGuard\VpnConfigurationRepository;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Captures one unmanaged peer mutation; the native root owns publication. */
final readonly class SetNodeWireGuardKeyAction
{
    public function __construct(private StartOperationAction $operations, private NodeWireGuardCommandInput $hub,
        private NodeProvisioningLock $lock, private VpnConfigurationRepository $configuration) {}

    public function execute(Node $node, string $publicKey): Operation
    {
        try {
            return $this->lock->run($node->name, fn (): Operation => $this->admit($node, $publicKey));
        } catch (NodeProvisioningLockException $exception) {
            throw $exception->toBusyException();
        }
    }

    private function admit(Node $node, string $publicKey): Operation
    {
        return DB::transaction(function () use ($node, $publicKey): Operation {
            $node = Node::query()->lockForUpdate()->findOrFail($node->id);
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
            $this->configuration->assertPeerKeyAvailable($node, $publicKey);
            $intent = $this->hub->capture($node);

            return $this->operations->execute('node.wireguard_key', 'node', $node->id, $node->id,
                ['hub_intent' => $intent, 'public_key' => $publicKey,
                    'participant_node_ids' => array_values(array_unique([$node->id, ValidatedData::integer($intent['hub_id']), 209]))],
                'operator', request()->attributes->getString('orbit.request_id'));
        });
    }
}
