<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Models\Node;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * The package cache of a task VM: stock `apt-cacher-ng`, which the task VM role runs and opens on the nested bridge only
 * (swamp/ansible/tasks/task-vm-apt-cache.yml, docs/cli/tasks/vm-fleet-build.mmd). The nested Nodes read the Ubuntu snapshot indexes from it, and every
 * apt run of their enrollment, roles and bootstrap goes through it (swamp/ansible/tasks/apt-proxy.yml).
 */
final readonly class TaskVmAptCache
{
    /** The port of the cache. The ufw rule of the task VM role (task-vm-nested-firewall.yml) names the same port. */
    public const int Port = 3142;

    /**
     * The cache a Node reads through (its apt runs and the Ubuntu snapshot indexes): the task VM's, at its bridge address, for a Node inside the nested
     * subnet. Null for any other Node. The e2e harness never hands out that subnet (apps/e2e HostCapacity), so no other Node can be inside it.
     */
    public static function for(Node $node): ?string
    {
        return self::forAddress((string) $node->public_ssh_host);
    }

    /** The same value for a machine by its address, as the fleet build names its nested Gateway before that Node exists. */
    public static function forAddress(string $address): ?string
    {
        return IpUtils::checkIp($address, TaskVmReadyCopiesIntent::Subnet)
            ? 'http://'.explode('/', TaskVmFleetBuildIntent::Bridge)[0].':'.self::Port
            : null;
    }
}
