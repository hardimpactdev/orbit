<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

/** The instance config that `incus init` reads for the base VM of a host, which Node 209 must reach: the cloud-init user data and the drive that carries it into the VM. */
final readonly class TaskVmLaunchConfig
{
    /**
     * The orbit account of the VM accepts the key of the Swamp control Node. The stock image has no SSH server, and Node 209 reaches the
     * VM over SSH, so its cloud-init installs the server before its status ends.
     */
    public static function for(string $publicKey): string
    {
        $cloud = ['package_update' => true, 'packages' => ['openssh-server'], 'users' => [['name' => 'orbit', 'uid' => 1000, 'no_user_group' => false, 'lock_passwd' => true, 'shell' => '/bin/bash',
            'sudo' => 'ALL=(ALL) NOPASSWD:ALL', 'ssh_authorized_keys' => [$publicKey]]]];

        return json_encode(['config' => ['cloud-init.user-data' => "#cloud-config\n".json_encode($cloud, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"],
            'devices' => ['cloud-init' => ['type' => 'disk', 'source' => 'cloud-init:config']]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
