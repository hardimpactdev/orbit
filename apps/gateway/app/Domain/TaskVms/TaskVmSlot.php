<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\Ipv4Subnet;

/**
 * The fixed names and addresses of a task VM slot. A host has up to {@see self::Maximum} slots, named
 * `<node-slug>-tvm-<n>`. The VM, its seed clone and the identity volume take their names from the slot name, so a
 * slot never changes them.
 */
final readonly class TaskVmSlot
{
    public const int Maximum = 64;

    /** Where the Incus agent mounts the read-only identity volume. */
    public const string IdentityPath = '/opt/orbit-slot';

    /** The disk device name. Incus exposes it to the guest as the virtiofs tag `incus_identity`, which the image's unit mounts. */
    public const string IdentityDevice = 'identity';

    public static function name(string $nodeName, int $slot): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($nodeName)), '-');
        $name = "{$slug}-tvm-{$slot}";
        if ($slug === '' || $slot < 1 || $slot > self::Maximum || strlen($name) > 63) {
            throw new ResourceOperationException('task_vm.slot_invalid', 'A slot is a number from 1 to '.self::Maximum.' on a host with a short name.', 422);
        }

        return $name;
    }

    /** The custom volume that holds the slot's keys and secrets. It outlives every VM of the slot. */
    public static function identityVolume(string $name): string
    {
        return "{$name}-identity";
    }

    /** The clone of the image's topology seed that one VM of the slot holds; the existing per-VM name. */
    public static function seedClone(string $name): string
    {
        return "{$name}-topology-seed";
    }

    /** The image's own seed volume. */
    public static function seedSource(string $fingerprint): string
    {
        return 'orbit-topology-seed-'.substr($fingerprint, 0, 12);
    }

    /**
     * The slot's static bridge address: the last usable address of the host bridge for slot 1, the one before it for
     * slot 2, and so on. DHCP leases start from the other end, so the two rarely meet; Incus reserves the address.
     */
    public static function bridgeAddress(TaskVmHost $host, int $slot): string
    {
        $address = long2ip(ip2long(Ipv4Subnet::from($host->cidr)->usableRange()[1]) - ($slot - 1));
        if (! $host->isGuestAddress($address)) {
            throw new ResourceOperationException('task_vm.slot_invalid', 'The host bridge has no address left for this slot.', 422);
        }

        return $address;
    }

    /** The slot's fixed MAC, in the Incus prefix, so the same slot always gets the same lease. */
    public static function bridgeMac(string $name): string
    {
        return '10:66:6a:'.implode(':', str_split(substr(hash('sha256', $name), 0, 6), 2));
    }
}
