<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\TaskVms\TaskVmAptCache;
use App\Models\Node;

/**
 * The update policy of every managed Linux Node, applied by `node:add` and by the `agent` phase of `node:converge` from this one
 * rendering (ADR 0251, docs/reference/node-provisioning.md#update-policy). `unattended-upgrades` installs Ubuntu `-security`
 * updates every day and never reboots; the nightly `fleet.reboot` pass reboots a due Node, drained.
 *
 * `needrestart` restarts the services that an update leaves on an old library, except the protected units: their restart drains
 * for a long time or cuts the fleet's work. A protected unit on an old library makes the Node due instead, through the same
 * `/var/run/reboot-required` that a kernel update writes, so the pass has one signal.
 *
 * A Node of a task VM's nested fleet gets the same files with the daily timers off: a proof must not change under an unattended
 * upgrade, and its fleet is rebuilt from the image, not patched.
 */
final readonly class NodeUpdatePolicy
{
    /** The unit name patterns that `needrestart` never restarts, as extended regular expressions. */
    public const array ProtectedUnits = [
        '^orbit-swamp-', '^orbit-gateway-', '^orbit-process-', '^orbit-agent\.service$',
        '^docker\.service$', '^containerd\.service$', '^incus', '^wg-quick@',
    ];

    public const string RebootDueScript = '/usr/local/lib/orbit/reboot-due';

    /** @return array{packages: list<string>, timers: list<string>, daily: bool, files: list<array{path: string, content: string, mode: string}>} */
    public static function for(Node $node): array
    {
        return ['packages' => ['unattended-upgrades', 'needrestart'], 'timers' => ['apt-daily.timer', 'apt-daily-upgrade.timer'],
            'daily' => TaskVmAptCache::for($node) === null, 'files' => [
                ['path' => '/etc/apt/apt.conf.d/20auto-upgrades', 'mode' => '0644',
                    'content' => "// Managed by Orbit.\nAPT::Periodic::Update-Package-Lists \"1\";\nAPT::Periodic::Unattended-Upgrade \"1\";\n"],
                ['path' => '/etc/apt/apt.conf.d/52orbit-unattended-upgrades', 'mode' => '0644',
                    'content' => "// Managed by Orbit (ADR 0251): security updates only, and never a reboot; Orbit reboots the Node, drained.\n"
                        ."#clear Unattended-Upgrade::Allowed-Origins;\n#clear Unattended-Upgrade::Origins-Pattern;\n"
                        ."Unattended-Upgrade::Allowed-Origins { \"\${distro_id}:\${distro_codename}-security\"; };\n"
                        ."Unattended-Upgrade::Automatic-Reboot \"false\";\nUnattended-Upgrade::Remove-Unused-Kernel-Packages \"true\";\n"],
                ['path' => '/etc/needrestart/conf.d/50-orbit.conf', 'mode' => '0644', 'content' => self::needrestart()],
                ['path' => '/etc/apt/apt.conf.d/53orbit-reboot-due', 'mode' => '0644',
                    'content' => "// Managed by Orbit (ADR 0251): a protected unit on an old library makes the Node due for its reboot.\n"
                        .'DPkg::Post-Invoke { "if [ -x '.self::RebootDueScript.' ]; then '.self::RebootDueScript."; fi\"; };\n"],
                ['path' => self::RebootDueScript, 'mode' => '0755', 'content' => self::rebootDue()],
            ]];
    }

    private static function needrestart(): string
    {
        $lines = array_map(static fn (string $pattern): string => '$nrconf{override_rc}->{qr('.$pattern.')} = 0;', self::ProtectedUnits);

        return "# Managed by Orbit (ADR 0251): restart services automatically, except the units whose restart drains or cuts the fleet's work.\n"
            ."\$nrconf{restart} = 'a';\n".implode("\n", $lines)."\n";
    }

    private static function rebootDue(): string
    {
        $pattern = implode('|', self::ProtectedUnits);

        return "#!/bin/sh\n# Managed by Orbit (ADR 0251). After each dpkg run: a protected unit that runs an old library makes the Node due for\n"
            ."# its drained reboot. needrestart only lists here; its own hook restarts the other services.\nset -u\n"
            ."out=\$(needrestart -b -r l 2>/dev/null) || exit 0\n"
            ."units=\$(printf '%s\\n' \"\$out\" | sed -n 's/^NEEDRESTART-SVC: //p' | grep -E '{$pattern}') || exit 0\n"
            ."printf '%s\\n' \"\$units\" >> /var/run/reboot-required.pkgs\n"
            ."[ -e /var/run/reboot-required ] || printf '*** System restart required ***\\n' > /var/run/reboot-required\n";
    }
}
