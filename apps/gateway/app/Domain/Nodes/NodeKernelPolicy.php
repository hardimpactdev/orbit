<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

/**
 * The kernel policy of every managed Linux Node, applied by `node:add` and by the `agent` phase of `node:converge` from this one
 * rendering (docs/reference/node-provisioning.md#kernel-policy). Nothing in Orbit uses SCTP, so its module never loads. Docker
 * publishes Database server ports on the explicit WireGuard and LAN addresses and may start a container at boot before such an
 * address exists, so a bind to an address the Node does not hold yet succeeds.
 */
final readonly class NodeKernelPolicy
{
    public const string ModulePath = '/etc/modprobe.d/orbit-no-sctp.conf';

    public const string ModuleRule = "install sctp /bin/false\n";

    public const string SysctlPath = '/etc/sysctl.d/60-orbit-nonlocal-bind.conf';

    /** @return array{files: list<array{path: string, content: string}>, sysctl: list<array{name: string, value: string, file: string}>} */
    public static function intent(): array
    {
        return ['files' => [['path' => self::ModulePath, 'content' => self::ModuleRule]],
            'sysctl' => [['name' => 'net.ipv4.ip_nonlocal_bind', 'value' => '1', 'file' => self::SysctlPath]]];
    }
}
