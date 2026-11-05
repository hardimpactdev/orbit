<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

/**
 * The instance config of a slot's VM, as the `incus init` stdin. The proof VM of an enrolment and a claim boot the same
 * way: the task image, a clone of its topology seed, the identity volume as a read-only disk, the slot's static address
 * and fixed MAC, and the account that the Gateway's SSH key opens.
 */
final readonly class TaskVmSlotBoot
{
    public static function config(TaskVmHost $host, string $name, string $publicKey, string $bridgeAddress, string $mac): string
    {
        $user = ['name' => 'orbit', 'lock_passwd' => true, 'shell' => '/bin/bash', 'sudo' => 'ALL=(ALL) NOPASSWD:ALL', 'ssh_authorized_keys' => [$publicKey]];

        return json_encode([
            'config' => [
                'limits.cpu' => (string) $host->cpus, 'limits.memory' => $host->memory,
                // Incus parses the user data as YAML, and go-yaml rejects the `\/` escape that json_encode writes for a slash.
                // `ssh_deletekeys: false` here, not only in the identity tree: cloud-init and the identity unit start together, and cloud-init
                // can delete the installed host keys before the unit's `99-orbit-slot.cfg` is in place.
                'cloud-init.user-data' => "#cloud-config\n".json_encode(['ssh_deletekeys' => false, 'users' => [$user]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n",
                'raw.qemu.conf' => "[device \"qemu_balloon\"]\nfree-page-reporting = \"on\"\n",
            ],
            'devices' => [
                'root' => ['type' => 'disk', 'path' => '/', 'pool' => $host->pool, 'size' => $host->disk],
                'eth0' => ['type' => 'nic', 'network' => $host->network, 'ipv4.address' => $bridgeAddress, 'hwaddr' => $mac, 'security.port_isolation' => 'true'],
                'cloud-init' => ['type' => 'disk', 'source' => 'cloud-init:config'],
                'topology-seed' => ['type' => 'disk', 'pool' => $host->pool, 'source' => TaskVmSlot::seedClone($name)],
                // The device name is the virtiofs tag `incus_identity` that the image's identity unit looks for.
                TaskVmSlot::IdentityDevice => ['type' => 'disk', 'pool' => $host->pool, 'source' => TaskVmSlot::identityVolume($name),
                    'path' => TaskVmSlot::IdentityPath, 'readonly' => 'true'],
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
