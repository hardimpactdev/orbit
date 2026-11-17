<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Instances\InstanceFleetGateway;
use App\Models\Node;

/**
 * The package cache of a task VM: stock `apt-cacher-ng`, which the task VM role runs and opens on the nested bridge only
 * (swamp/ansible/tasks/task-vm-apt-cache.yml). The nested Nodes read the Ubuntu snapshot indexes from it, and every
 * apt run of their enrollment, roles and bootstrap goes through it (swamp/ansible/tasks/apt-proxy.yml).
 */
final readonly class TaskVmAptCache
{
    /** The port of the cache. The ufw rule of the task VM role (task-vm-nested-firewall.yml) names the same port. */
    public const int Port = 3142;

    /**
     * The cache a Node reads through (its apt runs and the Ubuntu snapshot indexes): the task VM's, at its bridge address, for a Node inside the nested
     * subnet. Null for any other Node.
     * The nested fleet of an Instance container has the same subnet and no cache: its Gateway (InstanceFleetGateway) names none, and its Nodes
     * read apt through the egress proxy that their fleet file names.
     */
    public static function for(Node $node): ?string
    {
        return self::forAddress((string) $node->public_ssh_host);
    }

    /** The same value for a machine by its address, as the fleet phase names its nested Gateway before that Node exists. */
    public static function forAddress(string $address): ?string
    {
        return TaskVmNestedFleet::holds($address) && ! InstanceFleetGateway::holds()
            ? 'http://'.explode('/', TaskVmNestedFleet::Bridge)[0].':'.self::Port
            : null;
    }
}
