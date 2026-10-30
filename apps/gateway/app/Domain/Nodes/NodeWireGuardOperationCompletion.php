<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\Ssh\KnownHostsRepository;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Models\Node;
use App\Models\Operation;

/** The admitted public key remains desired state, including after a failed publication. */
final readonly class NodeWireGuardOperationCompletion implements OperationCompletion
{
    public function __construct(private KnownHostsStore $knownHosts) {}

    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        if ($operation->kind !== 'node.network_rollback' || $outcome->status !== OperationStatus::Succeeded) {
            return;
        }
        $address = Node::query()->find($operation->node_id)?->wireguard_ip;
        if (is_string($address) && $address !== '') {
            new KnownHostsRepository($this->knownHosts->path())->remove($address, 22);
        }
    }
}
