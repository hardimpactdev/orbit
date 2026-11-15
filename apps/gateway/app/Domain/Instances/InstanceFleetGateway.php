<?php

declare(strict_types=1);

namespace App\Domain\Instances;

/**
 * Whether this Gateway is the nested Gateway of an Instance container's inner fleet (docs/reference/development-containers.md#fleet-rules).
 * The fleet rules write the marker file on that Gateway only (swamp/ansible/tasks/instance-fleet.yml); a real Gateway and the nested
 * Gateway of a task VM have none. Its Nodes reach the internet only through the Node's egress proxy, which their own fleet file names
 * for apt, so this Gateway names no task VM package cache for them (TaskVmAptCache).
 */
final class InstanceFleetGateway
{
    /** The marker file, as the fleet rules write it. */
    public const string Marker = '/etc/orbit/instance-fleet';

    public static function holds(): bool
    {
        return is_file(config()->string('instances.fleet_marker'));
    }
}
