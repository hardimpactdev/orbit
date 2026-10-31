<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Data\Nodes\NodeData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Ssh\HostKey;
use App\Models\Node;
use Illuminate\Support\Facades\DB;

/** Record the approved public first scan; all scanning and host effects stay outside the Gateway. */
final readonly class RecordNodeSshHostKeyAction
{
    public function __construct(private RecordEventBroadcaster $broadcaster) {}

    public function execute(Node $node, string $host, HostKey $key): Node
    {
        return DB::transaction(function () use ($node, $host, $key): Node {
            $node = Node::query()->lockForUpdate()->findOrFail($node->id);
            if ($node->wireguard_ip !== $host) {
                throw new ResourceOperationException('node.ssh_host_key_target_mismatch', 'The observed host must match the stored Node WireGuard address.', 409);
            }
            $pin = ['ssh_host_key_type' => $key->type, 'ssh_host_key' => $key->value, 'ssh_host_fingerprint' => $key->fingerprint];
            if ($node->only(array_keys($pin)) === $pin) {
                return $node->load('roles');
            }
            if ($node->ssh_host_key_type !== null || $node->ssh_host_key !== null || $node->ssh_host_fingerprint !== null) {
                throw new ResourceOperationException('node.ssh_host_key_already_recorded', 'The Node already has a stored SSH host identity.', 409);
            }
            $node->update($pin);
            $node->load('roles');
            $this->broadcaster->broadcast(RecordEventType::NodeUpdated, $node->id, NodeData::fromModel($node)->toArray());

            return $node;
        });
    }
}
