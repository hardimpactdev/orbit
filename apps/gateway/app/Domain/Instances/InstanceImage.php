<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\TaskVms\TaskVmNestedFleet;
use App\Infrastructure\TaskVms\RecipeImageBuilds;

/**
 * The container image of a development Instance (docs/reference/development-containers.md#inside-a-container): the stock Ubuntu image
 * with the OS packages the runtime needs, the users `agent` and `orbit` in the group `work`, no sudo, the firewall of the model route
 * and T3's port, the egress proxy for everything and no DNS, `hidepid`, and the T3 unit, which starts only once the create flow writes `/etc/orbit/instance`. No PHP, Node or agent
 * software: those come from the shared folders. The host builds it once per alias, a digest of this whole definition.
 *
 * A Node with nested-fleet hosting on also builds the nested-fleet variant (docs/reference/development-containers.md#the-nested-fleet-image):
 * the same image with the pinned inner Incus, `agent` in `incus-admin` with passwordless sudo, user namespaces, root's subordinate IDs,
 * the AppArmor marker, a firewall rule that keeps `orbit` off the inner fleet's networks and leaves the inner Incus's own tables alone,
 * `zstd` for the inner fleet's archives, and a network wait that does not ask for the DNS the container never gets.
 */
final readonly class InstanceImage
{
    public const string StableAlias = 'orbit-instance';

    public const string NestedAlias = 'orbit-instance-nested';

    /** The nested-fleet variant's network wait (self::nestedFiles). */
    public const string NestedWaitOnline = '/etc/systemd/system/systemd-networkd-wait-online.service.d/90-orbit-no-dns.conf';

    /**
     * The nested-fleet variant's packages beyond the normal ones: the tunnel to its fleet, the fleet's database client, sudo, and
     * `zstd` for the `.tar.zst` archives of an inner fleet's containers.
     */
    public const array NestedPackages = ['wireguard-tools', 'sqlite3', 'sudo', 'zstd'];

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
     * The normal image, or with `$incus` (MachineRecipe::incus) the nested-fleet variant. The normal definition never depends on it.
     *
     * @param  array{line: string, packages: list<array{name: string, sha256: string, urls: list<string>}>}|null  $incus
     * @return array{alias: string, packages: list<string>, incus?: list<array{name: string, sha256: string, urls: list<string>}>, prepare: string,
     *     files: list<array{path: string, content: string, uid: int, gid: int, mode: string}>, browser_trust: array<string, mixed>,
     *     finish: list<list<string>>}
     */
    public static function for(string $gateway, ?array $incus = null): array
    {
        $nested = $incus !== null;
        $image = [
            'packages' => $nested ? [...self::Packages, ...self::NestedPackages] : self::Packages,
            // The inner Incus from its pinned artifacts; the metapackage `incus` would pull the VM tools a container cannot run.
            ...($nested ? ['incus' => array_values(array_filter($incus['packages'], static fn (array $package): bool => $package['name'] !== 'incus'))] : []),
            // Users, the workspace and the leftovers of the stock image. The stock `ubuntu` user holds uid 1000 and a sudo grant, and
            // cloud-init would make it again at each new Instance's first boot, so it is off; the network keeps its DHCP setup.
            // Cloud-init also made the group `lxd` for that user, and it took gid 1000, which `orbit` needs.
            'prepare' => self::prepare().($nested ? "usermod --append --groups incus-admin agent\n" : ''),
            'files' => [
                ['path' => '/etc/nftables.conf', 'content' => self::firewall($gateway, $nested), 'uid' => 0, 'gid' => 0, 'mode' => '0640'],
                ['path' => '/etc/systemd/system/t3.service', 'content' => self::t3Unit(), 'uid' => 0, 'gid' => 0, 'mode' => '0640'],
                ...($nested ? self::nestedFiles() : [
                    ['path' => '/etc/sysctl.d/90-orbit-no-userns.conf', 'content' => "# Managed by Orbit.\nuser.max_user_namespaces = 0\n", 'uid' => 0, 'gid' => 0, 'mode' => '0644'],
                ]),
                ...self::egress($gateway),
                ...self::shared(),
                // The container environment for units (T3, its agents and every Orbit Process) and login shells; the Instance profile
                // gives it to `incus exec` sessions.
                ['path' => '/etc/systemd/system.conf.d/90-orbit-runtime.conf', 'content' => "# Managed by Orbit.\n[Manager]\nDefaultEnvironment=".self::assignments(self::Path, $gateway)."\n", 'uid' => 0, 'gid' => 0, 'mode' => '0644'],
                // A login shell keeps the PATH it inherits, such as the agent folder that T3's unit gives `agent`.
                ['path' => '/etc/profile.d/90-orbit-runtime.sh', 'content' => "# Managed by Orbit.\nexport ".self::assignments(self::ShellPath, $gateway)."\n", 'uid' => 0, 'gid' => 0, 'mode' => '0644'],
                // Codex reads its update check only from its own configuration; the create flow adds the model provider.
                ['path' => '/home/agent/.codex/config.toml', 'content' => "check_for_update_on_startup = false\n",
                    'uid' => InstanceHostIntent::AgentUid, 'gid' => InstanceHostIntent::AgentUid, 'mode' => '0600'],
            ],
            // The build writes it after it checked the module's library in the image (docs/reference/development-containers.md#trust).
            'browser_trust' => self::browserTrust(),
            'finish' => [
                ['systemctl', 'enable', 'nftables.service', 't3.service'],
                // The build has pushed the Node's Orbit root CA (docs/reference/development-containers.md#trust): the bundle and the
                // links in /etc/ssl/certs. It is part of the definition, so an image without the root gets a new name and is built again.
                ['update-ca-certificates'],
                ['apt-get', 'clean'],
                ['find', '/var/lib/apt/lists', '-mindepth', '1', '-delete'],
                // The build container has booted, so it holds a machine-id, and every container of the image would share it. An empty
                // file makes each container write its own at its first start; a missing file would make that start a systemd first
                // boot, which resets the units to their presets.
                ['truncate', '--size', '0', '/etc/machine-id'],
                // D-Bus links its copy to that file. A copy that is a file of its own would keep the old id.
                ['sh', '-c', 'if [ -f /var/lib/dbus/machine-id ] && [ ! -L /var/lib/dbus/machine-id ]; then rm /var/lib/dbus/machine-id; fi'],
                // The build container may not see the runtime folder's `lib`. Without a cache, the stock `ldconfig.service` builds one
                // at each new container's first start, with the runtime folder mounted.
                ['rm', '-f', '/etc/ld.so.cache'],
            ],
        ];

        // A package's download URLs are transport alternatives for the same digest, so they do not name the image.
        $named = $nested ? [...$image, 'incus' => array_map(static fn (array $package): array => ['name' => $package['name'], 'sha256' => $package['sha256']], $image['incus'] ?? [])] : $image;

        return ['alias' => ($nested ? self::NestedAlias : self::StableAlias).'-'.substr(hash('sha256', json_encode($named, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), 0, 12), ...$image];
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

    /**
     * The files that every container needs, also one made from an older image: the converge writes them into each existing
     * container too, marked `existing` (docs/reference/development-containers.md#existing-containers). Each later process reads them
     * by itself, so nothing restarts.
     *
     * - The dynamic linker finds the runtime folder's libraries, such as a headless browser's. The name sorts after
     *   `x86_64-linux-gnu.conf`, so a system library of the same name always wins: the folder only adds what the image lacks.
     * - Fontconfig, also the copy that Chromium bundles, finds the runtime folder's fonts and maps the generic families to them.
     * - Git trusts the workspace for every user, `agent` and `orbit` alike, and only that path: both users share it by design.
     *
     * @return list<array{path: string, content: string, uid: int, gid: int, mode: string, existing: true}>
     */
    public static function shared(): array
    {
        $runtime = InstanceHostIntent::RuntimeFolder.'/current';
        $file = static fn (string $path, string $content): array => ['path' => $path, 'content' => $content, 'uid' => 0, 'gid' => 0,
            'mode' => '0644', 'existing' => true];

        return [
            $file('/etc/ld.so.conf.d/zz-orbit-browser.conf', "# Managed by Orbit.\n{$runtime}/lib\n"),
            $file('/etc/fonts/fonts.conf', self::fonts("{$runtime}/fonts")),
            $file('/etc/gitconfig', "# Managed by Orbit.\n[safe]\n\tdirectory = ".InstanceHostIntent::Workspace."\n"),
        ];
    }

    /**
     * Chromium's trust of the Orbit root CA (docs/reference/development-containers.md#trust). Chromium on Linux takes local roots only
     * from the user's NSS store, `~/.pki/nssdb`, never from `/etc/ssl/certs`. So `agent`'s store names the runtime folder's p11-kit
     * trust module, which reads the system bundle that already holds the root; NSS makes its own databases at first use. `entry` is
     * the module's block in `pkcs11.txt`. The image, a clone and the converge write a missing store with only that block, and append
     * it to a store that lacks it; nothing else in the file changes. The module needs the container's `library` at exactly `version`,
     * the recipe part's version, so a new pin also names a new image, which the build checks again.
     *
     * @return array{home: string, directories: list<string>, path: string, entry: string, uid: int, gid: int, mode: string, directory_mode: string,
     *     library: string, version: string}
     */
    public static function browserTrust(): array
    {
        $home = '/home/agent';
        $module = app(RecipeImageBuilds::class)->currentRecipe()->instances?->trustModule()
            ?? throw new \LogicException('The recipe holds no shared Instance folders.');
        $library = $module->trustModule['library'] ?? throw new \LogicException('The trust module part names no library.');

        return ['home' => $home, 'directories' => ["{$home}/.pki", "{$home}/.pki/nssdb"], 'path' => "{$home}/.pki/nssdb/pkcs11.txt",
            'entry' => 'library='.InstanceHostIntent::RuntimeFolder.'/current/'.InstanceHostIntent::TrustModule."\nname=Orbit system trust\n\n",
            'uid' => InstanceHostIntent::AgentUid, 'gid' => InstanceHostIntent::AgentUid, 'mode' => '0600', 'directory_mode' => '0700',
            'library' => $library, 'version' => $module->version];
    }

    private static function fonts(string $folder): string
    {
        return <<<XML
            <?xml version="1.0"?>
            <!DOCTYPE fontconfig SYSTEM "urn:fontconfig:fonts.dtd">
            <!-- Managed by Orbit. -->
            <fontconfig>
              <dir>{$folder}</dir>
              <cachedir prefix="xdg">fontconfig</cachedir>
              <alias><family>sans-serif</family><prefer><family>DejaVu Sans</family><family>Noto Color Emoji</family></prefer></alias>
              <alias><family>serif</family><prefer><family>DejaVu Serif</family><family>Noto Color Emoji</family></prefer></alias>
              <alias><family>monospace</family><prefer><family>DejaVu Sans Mono</family><family>Noto Color Emoji</family></prefer></alias>
              <alias><family>emoji</family><prefer><family>Noto Color Emoji</family></prefer></alias>
              <alias binding="same"><family>Arial</family><accept><family>Liberation Sans</family></accept></alias>
              <alias binding="same"><family>Helvetica</family><accept><family>Liberation Sans</family></accept></alias>
              <alias binding="same"><family>Times New Roman</family><accept><family>Liberation Serif</family></accept></alias>
              <alias binding="same"><family>Courier New</family><accept><family>Liberation Mono</family></accept></alias>
            </fontconfig>

            XML;
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
            for part in bin js_runtime node_modules recipe config.json; do
                ln -s {$sharedVite}/\$part {$vite}/\$part
            done
            echo 'proc /proc proc defaults,hidepid=invisible 0 0' >> /etc/fstab

            SH;
    }

    /**
     * The nested-fleet variant's own files: the inner Incus maps root's range and the outer `orbit` into its containers, Docker in
     * them reads the AppArmor marker, and `agent` alone has sudo. Its user namespaces stay on, so it has no limit file.
     *
     * Two unit drop-ins. The inner Incus wants `network-online.target`, so each start after the first waits for it. Netplan's wait
     * asks for a DNS server on eth0, which the container never gets (self::egress), so it would hold the boot for its full 120 s;
     * this wait asks only that eth0 has a route, and gives up after 30 s. The wait stays, unlike a mask, so the inner Incus still
     * starts after eth0 has its address. Its name sorts after netplan's `10-netplan.conf`, so its `ExecStart` wins. And a stop of
     * nftables removes only Orbit's own table: the stock unit's `flush ruleset` would also remove the inner Incus's tables.
     *
     * @return list<array{path: string, content: string, uid: int, gid: int, mode: string}>
     */
    private static function nestedFiles(): array
    {
        $ids = "root:1000000:100000000\nroot:".InstanceHostIntent::OrbitUid.":1\n";

        return [
            ['path' => '/etc/subuid', 'content' => $ids, 'uid' => 0, 'gid' => 0, 'mode' => '0644'],
            ['path' => '/etc/subgid', 'content' => $ids, 'uid' => 0, 'gid' => 0, 'mode' => '0644'],
            ['path' => '/etc/orbit/apparmor-off', 'content' => "N\n", 'uid' => 0, 'gid' => 0, 'mode' => '0644'],
            ['path' => '/etc/sudoers.d/orbit-agent', 'content' => "agent ALL=(ALL:ALL) NOPASSWD: ALL\n", 'uid' => 0, 'gid' => 0, 'mode' => '0440'],
            ['path' => self::NestedWaitOnline, 'content' => "# Managed by Orbit.\n[Service]\nExecStart=\nExecStart=/usr/lib/systemd/systemd-networkd-wait-online --interface=eth0:routable --timeout=30\n",
                'uid' => 0, 'gid' => 0, 'mode' => '0644'],
            ['path' => '/etc/systemd/system/nftables.service.d/90-orbit-own-table.conf', 'content' => "# Managed by Orbit.\n[Service]\nExecStop=\nExecStop=/usr/sbin/nft destroy table inet orbit_agent_guard\n",
                'uid' => 0, 'gid' => 0, 'mode' => '0644'],
        ];
    }

    /**
     * Only `agent` reaches the model forwarder, and only root and `agent` reach T3's port; others get a reset. In the nested-fleet
     * variant the first rule also drops `orbit`'s traffic to the inner fleet's networks, and the file replaces only its own table:
     * a `flush ruleset` there would also remove the inner Incus's tables (its bridge NAT and ACLs) at each reload.
     */
    public static function firewall(string $gateway, bool $nested = false): string
    {
        $agent = InstanceHostIntent::AgentUid;
        $forwarder = InstanceHostIntent::ModelForwarderPort;
        $t3 = InstanceHostIntent::T3Port;
        $reset = $nested ? 'destroy table inet orbit_agent_guard' : 'flush ruleset';
        $guard = $nested ? "\n        ip daddr { ".TaskVmNestedFleet::WireGuardSubnet.', '.TaskVmNestedFleet::Subnet.' } meta skuid '.InstanceHostIntent::OrbitUid.' drop' : '';

        return <<<NFT
            #!/usr/sbin/nft -f
            # Managed by Orbit.
            {$reset}
            table inet orbit_agent_guard {
                chain output {
                    type filter hook output priority 0; policy accept;{$guard}
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
