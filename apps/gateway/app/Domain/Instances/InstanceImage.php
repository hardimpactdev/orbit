<?php

declare(strict_types=1);

namespace App\Domain\Instances;

/**
 * The container image of a development Instance (docs/reference/development-containers.md#inside-a-container): the stock Ubuntu image
 * with the OS packages the runtime needs, the users `agent` and `orbit` in the group `work`, no sudo, the firewall of the model route
 * and T3's port, the egress proxy for everything and no DNS, `hidepid`, and the T3 unit, which starts only once the create flow writes `/etc/orbit/instance`. No PHP, Node or agent
 * software: those come from the shared folders. The host builds it once per alias, a digest of this whole definition.
 */
final readonly class InstanceImage
{
    public const string StableAlias = 'orbit-instance';

    /** OS packages beyond the stock image: the firewall, ACLs on the workspace, and Git for the workspace repository. */
    public const array Packages = ['acl', 'git', 'nftables'];

    /**
     * The environment of every program from the runtime folder: Vite+ links the prepared runtime from its writable home,
     * where it can install the Project's package manager without changing the pinned runtime.
     */
    public const array RuntimeEnvironment = ['VP_HOME' => '/var/lib/orbit/vite-plus-home', 'VP_NO_UPDATE_CHECK' => '1'];

    /**
     * The `PATH` of units, login shells and `incus exec` sessions: the runtime folder's programs, such as `composer`, before the
     * system's. The container phase links the Project's `php` into `/usr/local/bin` (InstanceContainer::intent), so it is on it too.
     */
    public const string Path = InstanceHostIntent::RuntimeFolder.'/current/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';

    /** A login shell puts the same folders before the PATH it inherits. */
    public const string ShellPath = InstanceHostIntent::RuntimeFolder.'/current/bin:/usr/local/bin:$PATH';

    /**
     * @return array{alias: string, packages: list<string>, prepare: string,
     *     files: list<array{path: string, content: string, uid: int, gid: int, mode: string}>, finish: list<list<string>>}
     */
    public static function for(string $gateway): array
    {
        $image = [
            'packages' => self::Packages,
            // Users, the workspace and the leftovers of the stock image. The stock `ubuntu` user holds uid 1000 and a sudo grant, and
            // cloud-init would make it again at each new Instance's first boot, so it is off; the network keeps its DHCP setup.
            // Cloud-init also made the group `lxd` for that user, and it took gid 1000, which `orbit` needs.
            'prepare' => self::prepare(),
            'files' => [
                ['path' => '/etc/nftables.conf', 'content' => self::firewall($gateway), 'uid' => 0, 'gid' => 0, 'mode' => '0640'],
                ['path' => '/etc/systemd/system/t3.service', 'content' => self::t3Unit(), 'uid' => 0, 'gid' => 0, 'mode' => '0640'],
                ['path' => '/etc/sysctl.d/90-orbit-no-userns.conf', 'content' => "# Managed by Orbit.\nuser.max_user_namespaces = 0\n", 'uid' => 0, 'gid' => 0, 'mode' => '0644'],
                ...self::egress($gateway),
                ['path' => '/home/orbit/.gitconfig', 'content' => "[safe]\n\tdirectory = ".InstanceHostIntent::Workspace."\n",
                    'uid' => InstanceHostIntent::OrbitUid, 'gid' => InstanceHostIntent::OrbitUid, 'mode' => '0640'],
                // The container environment for units (T3, its agents and every Orbit Process) and login shells; the Instance profile
                // gives it to `incus exec` sessions.
                ['path' => '/etc/systemd/system.conf.d/90-orbit-runtime.conf', 'content' => "# Managed by Orbit.\n[Manager]\nDefaultEnvironment=".self::assignments(self::Path, $gateway)."\n", 'uid' => 0, 'gid' => 0, 'mode' => '0644'],
                // A login shell keeps the PATH it inherits, such as the agent folder that T3's unit gives `agent`.
                ['path' => '/etc/profile.d/90-orbit-runtime.sh', 'content' => "# Managed by Orbit.\nexport ".self::assignments(self::ShellPath, $gateway)."\n", 'uid' => 0, 'gid' => 0, 'mode' => '0644'],
                // Codex reads its update check only from its own configuration; the create flow adds the model provider.
                ['path' => '/home/agent/.codex/config.toml', 'content' => "check_for_update_on_startup = false\n",
                    'uid' => InstanceHostIntent::AgentUid, 'gid' => InstanceHostIntent::AgentUid, 'mode' => '0600'],
            ],
            'finish' => [
                ['systemctl', 'enable', 'nftables.service', 't3.service'],
                ['apt-get', 'clean'],
                ['find', '/var/lib/apt/lists', '-mindepth', '1', '-delete'],
                // The build container has booted, so it holds a machine-id, and every container of the image would share it. An empty
                // file makes each container write its own at its first start; a missing file would make that start a systemd first
                // boot, which resets the units to their presets.
                ['truncate', '--size', '0', '/etc/machine-id'],
                // D-Bus links its copy to that file. A copy that is a file of its own would keep the old id.
                ['sh', '-c', 'if [ -f /var/lib/dbus/machine-id ] && [ ! -L /var/lib/dbus/machine-id ]; then rm /var/lib/dbus/machine-id; fi'],
            ],
        ];

        return ['alias' => self::StableAlias.'-'.substr(hash('sha256', json_encode($image, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), 0, 12), ...$image];
    }

    /**
     * The environment of every process in a container beside its PATH: the runtime folder's and the egress proxy's.
     *
     * @return array<string, string>
     */
    public static function environment(string $gateway): array
    {
        return [...self::RuntimeEnvironment, ...InstanceEgress::environment($gateway)];
    }

    private static function assignments(string $path, string $gateway): string
    {
        $environment = ['PATH' => $path, ...self::environment($gateway)];

        return implode(' ', array_map(static fn (string $name, string $value): string => "{$name}={$value}", array_keys($environment), $environment));
    }

    private static function prepare(): string
    {
        $agent = InstanceHostIntent::AgentUid;
        $orbit = InstanceHostIntent::OrbitUid;
        $work = InstanceHostIntent::WorkGid;
        $workspace = InstanceHostIntent::Workspace;
        $vite = self::RuntimeEnvironment['VP_HOME'];
        $sharedVite = InstanceHostIntent::RuntimeFolder.'/current/vite-plus-home';

        return <<<SH
            set -eu
            touch /etc/cloud/cloud-init.disabled
            if id ubuntu >/dev/null 2>&1; then userdel --remove ubuntu; fi
            if getent group lxd >/dev/null 2>&1; then groupdel lxd; fi
            groupadd --gid {$agent} agent
            useradd --uid {$agent} --gid agent --create-home --shell /bin/bash agent
            groupadd --gid {$orbit} orbit
            useradd --uid {$orbit} --gid orbit --create-home --shell /bin/bash orbit
            groupadd --gid {$work} work
            usermod --append --groups work agent
            usermod --append --groups work orbit
            passwd --lock agent
            passwd --lock orbit
            chmod 0700 /home/agent
            chmod 0750 /home/orbit
            install -d -o agent -g agent -m 0700 /home/agent/.codex /home/agent/.config /home/agent/.config/orbit
            find /etc/sudoers.d -mindepth 1 ! -name README -delete
            install -d -o root -g root -m 0755 /etc/orbit
            install -d -o agent -g work -m 2770 {$workspace}
            setfacl -m g:work:rwX -m d:u::rwX,d:g::rwX,d:g:work:rwX,d:o::--- {$workspace}
            install -d -o agent -g work -m 2770 {$vite}
            setfacl -m g:work:rwX -m d:u::rwX,d:g::rwX,d:g:work:rwX,d:o::--- {$vite}
            install -d -o agent -g work -m 2770 {$vite}/cache
            install -d -o agent -g work -m 2770 {$vite}/js_runtime {$vite}/js_runtime/node
            cp -a --reflink=auto --no-preserve=ownership {$sharedVite}/js_runtime/. {$vite}/js_runtime/
            chown -R agent:work {$vite}/js_runtime
            chmod -R g+rwX {$vite}/js_runtime
            find {$vite}/js_runtime -type d -exec chmod 2770 {} +
            for part in bin node_modules recipe config.json; do
                ln -s {$sharedVite}/\$part {$vite}/\$part
            done
            echo 'proc /proc proc defaults,hidepid=invisible 0 0' >> /etc/fstab

            SH;
    }

    /** Only `agent` reaches the model forwarder, and only root and `agent` reach T3's port; others get a reset. */
    public static function firewall(string $gateway): string
    {
        $agent = InstanceHostIntent::AgentUid;
        $forwarder = InstanceHostIntent::ModelForwarderPort;
        $t3 = InstanceHostIntent::T3Port;

        return <<<NFT
            #!/usr/sbin/nft -f
            # Managed by Orbit.
            flush ruleset
            table inet orbit_agent_guard {
                chain output {
                    type filter hook output priority 0; policy accept;
                    ip daddr {$gateway} tcp dport {$forwarder} meta skuid != {$agent} reject with tcp reset
                    tcp dport {$t3} meta skuid != { 0, {$agent} } reject with tcp reset
                }
            }

            NFT;
    }

    /**
     * The egress model inside a container (docs/reference/development-containers.md#egress-and-dns), beyond the environment: apt reads
     * its own proxy setting. The network takes no DNS server from DHCP and the resolver has no fallback, so a name lookup fails at once
     * instead of waiting for a server the Node does not answer for.
     *
     * @return list<array{path: string, content: string, uid: int, gid: int, mode: string}>
     */
    private static function egress(string $gateway): array
    {
        $origin = InstanceEgress::origin($gateway);
        $file = static fn (string $path, string $content, string $mode = '0644', string $comment = '#'): array => ['path' => $path,
            'content' => $comment." Managed by Orbit.\n".$content, 'uid' => 0, 'gid' => 0, 'mode' => $mode];

        return [
            $file('/etc/apt/apt.conf.d/90orbit-egress', "Acquire::http::Proxy \"{$origin}\";\nAcquire::https::Proxy \"{$origin}\";\n", comment: '//'),
            $file('/etc/netplan/90-orbit-no-dns.yaml', "network:\n  version: 2\n  ethernets:\n    eth0:\n      dhcp4: true\n      dhcp4-overrides:\n        use-dns: false\n        use-domains: false\n", '0600'),
            $file('/etc/systemd/resolved.conf.d/90-orbit-no-dns.conf', "[Resolve]\nDNS=\nFallbackDNS=\n"),
        ];
    }

    /** T3 runs as `agent` from the agent folder, and only in an Instance the create flow has named; the default Instance has none. */
    public static function t3Unit(): string
    {
        $agentBin = InstanceHostIntent::AgentFolder.'/current/agent/bin';
        $runtimeBin = InstanceHostIntent::RuntimeFolder.'/current/bin';
        $t3 = InstanceHostIntent::T3Port;

        return <<<UNIT
            # Managed by Orbit.
            [Unit]
            Description=T3 server
            After=network-online.target nftables.service
            Requires=nftables.service
            ConditionPathExists=/etc/orbit/instance

            [Service]
            Type=simple
            User=agent
            Group=agent
            UMask=0007
            WorkingDirectory=/home/agent
            Environment=HOME=/home/agent
            Environment=PATH={$agentBin}:{$runtimeBin}:/usr/local/bin:/usr/bin:/bin
            Environment=DISABLE_AUTOUPDATER=1
            EnvironmentFile=/home/agent/.config/orbit/model.env
            ExecStart={$agentBin}/t3 serve --host=0.0.0.0 --port={$t3} --no-browser
            Restart=always
            RestartSec=2

            [Install]
            WantedBy=multi-user.target

            UNIT;
    }
}
