<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Models\Node;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * The package cache of a task VM: stock `apt-cacher-ng`, which the task VM role runs and opens on the nested bridge only
 * (swamp/ansible/tasks/task-vm-apt-cache.yml, docs/cli/tasks/vm-fleet-build.mmd). The nested Nodes read the Ubuntu snapshot indexes from it.
 */
final readonly class TaskVmAptCache
{
    /** The port of the cache. The ufw rule of the task VM role (task-vm-nested-firewall.yml) names the same port. */
    public const int Port = 3142;

    /**
     * The cache a Node reads the Ubuntu snapshot through: the task VM's, at its bridge address, for a Node inside the nested subnet. Null for any
     * other Node. The e2e harness never hands out that subnet (apps/e2e HostCapacity), so no other Node can be inside it.
     */
    public static function for(Node $node): ?string
    {
        return IpUtils::checkIp((string) $node->public_ssh_host, TaskVmReadyCopiesIntent::Subnet)
            ? 'http://'.explode('/', TaskVmFleetBuildIntent::Bridge)[0].':'.self::Port
            : null;
    }
}
