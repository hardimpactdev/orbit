<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\Instances\InstanceDestinationGuard;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Node;

/** Refuses a destination that anything occupies, through the Node agent's `instance.check-path-free`. */
final readonly class RemoteInstanceDestinationGuard implements InstanceDestinationGuard
{
    public function __construct(private AgentCommandRunner $agents) {}

    public function assertUnoccupied(Node $node, StoragePath $destination): void
    {
        if (($this->agents->run($node, 'instance.check-path-free', ['path' => $destination->value])['free'] ?? false) !== true) {
            throw new ResourceOperationException(
                errorCode: 'instance.migration_conflict',
                message: "Instance destination [{$destination->value}] is occupied by unmanaged data.",
                status: 409,
            );
        }
    }
}
