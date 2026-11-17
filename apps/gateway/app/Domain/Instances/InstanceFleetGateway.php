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

    /** The environment file that the fleet rules write whole, with the egress proxy of the fleet. */
    public const string Environment = '/etc/environment';

    public static function holds(): bool
    {
        return is_file(config()->string('instances.fleet_marker'));
    }

    /**
     * The egress proxy the fleet goes out through, as the fleet rules name it in `HTTPS_PROXY`, or null on any other Gateway: the one
     * reader of it. Its address lies in the Instance container's own bridge, so no Instance bridge of the fleet may hold it, and the
     * fleet Node's own egress proxy takes it as its only parent (docs/reference/development-containers.md#instance-host-in-the-fleet).
     *
     * @return array{host: string, port: int}|null
     */
    public static function egressProxy(): ?array
    {
        $file = config()->string('instances.fleet_environment');
        if (! self::holds() || ! is_readable($file)) {
            return null;
        }

        return preg_match('/^HTTPS_PROXY="https?:\/\/(\d{1,3}(?:\.\d{1,3}){3}):(\d{1,5})"$/m', (string) file_get_contents($file), $match) === 1
            ? ['host' => $match[1], 'port' => (int) $match[2]] : null;
    }
}
