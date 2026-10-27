<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Data\Nodes\NodeData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Nodes\NodeProvisioningLock;
use App\Domain\Nodes\NodeProvisioningLockException;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;

/**
 * Records the platform of an unmanaged Node: one with no pinned SSH host key and no role. Enrollment observes a
 * managed Node's platform, so this command never changes one.
 */
final readonly class SetNodePlatformAction
{
    public function __construct(
        private NodeProvisioningLock $provisioningLock,
        private RecordEventBroadcaster $broadcaster,
    ) {}

    public function execute(Node $node, string $platform): Node
    {
        try {
            return $this->provisioningLock->run($node->name, function () use ($node, $platform): Node {
                $node->refresh();

                if ((is_string($node->ssh_host_fingerprint) && $node->ssh_host_fingerprint !== '') || $node->roles()->exists()) {
                    throw new ResourceOperationException('node.platform_managed', 'Only an unmanaged Node, with no pinned SSH host key and no role, takes a platform from this command.', 409);
                }

                if ($node->platform === $platform) {
                    return $node->load('roles');
                }

                $node->update(['platform' => $platform]);
                $result = $node->refresh()->load('roles');
                $this->broadcaster->broadcast(RecordEventType::NodeUpdated, $result->id, NodeData::fromModel($result)->toArray());

                return $result;
            });
        } catch (NodeProvisioningLockException $exception) {
            throw $exception->toBusyException();
        }
    }
}
