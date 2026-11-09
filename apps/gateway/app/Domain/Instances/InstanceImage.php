<?php

declare(strict_types=1);

namespace App\Domain\Instances;

/**
 * The container image of a development Instance (docs/reference/development-containers.md#inside-a-container): the stock Ubuntu image
 * with the OS packages the runtime needs, the users `agent` and `orbit` in the group `work`, no sudo, the firewall of the model route
 * and T3's port, `hidepid`, and the T3 unit, which starts only once the create flow writes `/etc/orbit/instance`. No PHP, Node or agent
 * software: those come from the shared folders. The host builds it once per alias, a digest of this whole definition.
 */
final readonly class InstanceImage
{
    public const string StableAlias = 'orbit-instance';

    /** OS packages beyond the stock image: the firewall, ACLs on the workspace, and Git for the workspace repository. */
    public const array Packages = ['acl', 'git', 'nftables'];

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
            'prepare' => self::prepare(),
            'files' => [
                ['path' => '/etc/nftables.conf', 'content' => self::firewall($gateway), 'uid' => 0, 'gid' => 0, 'mode' => '0640'],
                ['path' => '/etc/systemd/system/t3.service', 'content' => self::t3Unit(), 'uid' => 0, 'gid' => 0, 'mode' => '0640'],
                ['path' => '/etc/sysctl.d/90-orbit-no-userns.conf', 'content' => "# Managed by Orbit.\nuser.max_user_namespaces = 0\n", 'uid' => 0, 'gid' => 0, 'mode' => '0644'],
                ['path' => '/home/orbit/.gitconfig', 'content' => "[safe]\n\tdirectory = ".InstanceHostIntent::Workspace."\n",
                    'uid' => InstanceHostIntent::OrbitUid, 'gid' => InstanceHostIntent::OrbitUid, 'mode' => '0640'],
                // Codex reads its update check only from its own configuration; the create flow adds the model provider.
                ['path' => '/home/agent/.codex/config.toml', 'content' => "check_for_update_on_startup = false\n",
                    'uid' => InstanceHostIntent::AgentUid, 'gid' => InstanceHostIntent::AgentUid, 'mode' => '0600'],
            ],
            'finish' => [
                ['systemctl', 'enable', 'nftables.service', 't3.service'],
                ['apt-get', 'clean'],
                ['find', '/var/lib/apt/lists', '-mindepth', '1', '-delete'],
            ],
        ];

        return ['alias' => self::StableAlias.'-'.substr(hash('sha256', json_encode($image, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), 0, 12), ...$image];
    }

    private static function prepare(): string
    {
        $agent = InstanceHostIntent::AgentUid;
        $orbit = InstanceHostIntent::OrbitUid;
        $work = InstanceHostIntent::WorkGid;
        $workspace = InstanceHostIntent::Workspace;

        return <<<SH
            set -eu
            touch /etc/cloud/cloud-init.disabled
            if id ubuntu >/dev/null 2>&1; then userdel --remove ubuntu; fi
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
