<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

describe('prepare node guest script', function () {
    it('replaces orbit SSH authorization with the validated gateway public key', function () {
        $source = file_get_contents(dirname(__DIR__, 3).'/resources/guest/prepare-node.sh');
        $root = temporaryPath('orbit-authorize-gateway-', 4);
        mkdir("{$root}/bin", 0o700, true);
        mkdir("{$root}/home/orbit/.ssh", 0o700, true);
        file_put_contents("{$root}/home/orbit/.ssh/authorized_keys", "stale key\n");
        file_put_contents("{$root}/bin/chown", "#!/usr/bin/env bash\nexit 0\n");
        chmod("{$root}/bin/chown", 0o700);

        $script = str_replace('/home/orbit', "{$root}/home/orbit", $source);
        $path = "{$root}/prepare-node.sh";
        file_put_contents($path, $script);
        chmod($path, 0o700);
        $key = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOJgN5jVtcfw7oASD2F6If4O5mQ/HZBqbrw4QC9PcHEO';
        $process = new Process(['bash', $path, 'gateway-authorize', $key], env: [
            'PATH' => "{$root}/bin:".getenv('PATH'),
        ]);

        expect($process->run())
            ->toBe(0)
            ->and(file_get_contents("{$root}/home/orbit/.ssh/authorized_keys"))
            ->toBe($key."\n");
    });

    it('rejects an invalid gateway public key without changing authorization', function () {
        $source = file_get_contents(dirname(__DIR__, 3).'/resources/guest/prepare-node.sh');
        $root = temporaryPath('orbit-reject-gateway-key-', 4);
        mkdir("{$root}/home/orbit/.ssh", 0o700, true);
        file_put_contents("{$root}/home/orbit/.ssh/authorized_keys", "existing key\n");
        $path = "{$root}/prepare-node.sh";
        file_put_contents($path, str_replace('/home/orbit', "{$root}/home/orbit", $source));
        chmod($path, 0o700);
        $process = new Process(['bash', $path, 'gateway-authorize', 'not-a-key']);

        expect($process->run())
            ->toBe(64)
            ->and(file_get_contents("{$root}/home/orbit/.ssh/authorized_keys"))
            ->toBe("existing key\n");
    });

    it('persists public DNS before selecting the stub and keeps repeated preparation stable', function () {
        $root = temporaryPath('orbit-persistent-dns-', 4);
        mkdir("{$root}/bin", 0o700, true);
        mkdir("{$root}/etc", 0o700, true);
        mkdir("{$root}/run/systemd/resolve", 0o700, true);
        file_put_contents("{$root}/run/systemd/resolve/stub-resolv.conf", "nameserver 127.0.0.53\n");
        file_put_contents("{$root}/etc/resolv.conf", "nameserver 1.1.1.1\n");
        file_put_contents("{$root}/bin/systemctl", "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> '{$root}/calls'\n");
        file_put_contents("{$root}/bin/id", "#!/bin/sh\nprintf '1000\\n'\n");
        file_put_contents("{$root}/bin/find", "#!/bin/sh\nexit 0\n");
        foreach (['systemctl', 'id', 'find'] as $command) {
            chmod("{$root}/bin/{$command}", 0o700);
        }
        $source = file_get_contents(dirname(__DIR__, 3).'/resources/guest/prepare-node.sh');
        $source = str_replace(['/etc/', '/run/systemd/'], ["{$root}/etc/", "{$root}/run/systemd/"], $source);
        $source = str_replace("..{$root}/run/", '../run/', $source);
        file_put_contents("{$root}/prepare-node.sh", $source);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $process = new Process(['bash', "{$root}/prepare-node.sh", 'align-identity'], env: [
                'PATH' => "{$root}/bin:".getenv('PATH'),
            ]);
            expect($process->run())->toBe(0, $process->getErrorOutput());
            expect(realpath("{$root}/etc/resolv.conf"))->toBe("{$root}/run/systemd/resolve/stub-resolv.conf");
            expect(file_get_contents("{$root}/etc/systemd/resolved.conf.d/orbit-e2e-upstream.conf"))
                ->toBe("[Resolve]\nDNS=1.1.1.1 8.8.8.8\n");
        }
        expect(substr_count(file_get_contents("{$root}/calls"), 'restart systemd-resolved'))->toBe(1);
    });

    /* ssh host pinning was removed; KnownHostsStore is authoritative. */
    it('moves the orbit account to uid and gid 1000 and re-owns stale files', function () {
        $root = temporaryDirectory().'/orbit-align-identity-'.bin2hex(random_bytes(4));
        mkdir("{$root}/bin", 0o700, true);
        $shim = "#!/usr/bin/env bash\nprintf '%s\\n' \"\$(basename \"\$0\") \$*\" >> '{$root}/commands'\n";
        foreach (['userdel', 'groupmod', 'usermod', 'find', 'systemctl'] as $command) {
            file_put_contents("{$root}/bin/{$command}", $shim);
            chmod("{$root}/bin/{$command}", 0o700);
        }
        file_put_contents("{$root}/bin/id", <<<'BASH'
            #!/usr/bin/env bash
            case "$*" in
              '-u orbit') printf '%s\n' "${ALIGN_ORBIT_UID:-1002}" ;;
              '-g orbit') printf '%s\n' "${ALIGN_ORBIT_GID:-1002}" ;;
              '-u ubuntu') [[ "${ALIGN_UBUNTU_PRESENT:-1}" == 1 ]] && printf '1000\n' ;;
              *) exit 1 ;;
            esac
            BASH);
        file_put_contents("{$root}/bin/getent", "#!/usr/bin/env bash\nexit 2\n");
        chmod("{$root}/bin/id", 0o700);
        chmod("{$root}/bin/getent", 0o700);
        $source = file_get_contents(dirname(__DIR__, 3).'/resources/guest/prepare-node.sh');
        file_put_contents("{$root}/prepare-node.sh", str_replace('/run/systemd/resolve/stub-resolv.conf', "{$root}/missing-stub", $source));
        $environment = ['PATH' => "{$root}/bin:".getenv('PATH')];

        try {
            $process = new Process(['bash', "{$root}/prepare-node.sh", 'align-identity'], env: $environment);
            expect($process->run())
                ->toBe(0, $process->getErrorOutput())
                ->and(array_values(preg_grep('/^(userdel|groupmod|usermod|systemctl) /', file(
                    "{$root}/commands",
                    FILE_IGNORE_NEW_LINES,
                ))))
                ->toBe([
                    'systemctl is-active --quiet systemd-resolved',
                    'userdel --remove ubuntu',
                    'groupmod --gid 1000 orbit',
                    'usermod --uid 1000 --gid 1000 orbit',
                    'systemctl is-active --quiet php8.5-fpm',
                    'systemctl restart php8.5-fpm',
                ]);
            expect(file_get_contents("{$root}/commands"))
                ->toContain('find / /run -xdev ( -uid 1002 -o -gid 1002 ) -exec python3 -c')
                ->toContain(
                    'os.lchown(path, 1000 if st.st_uid == old_uid else -1, 1000 if st.st_gid == old_gid else -1)',
                )
                ->toContain(' 1002 1002 {} +');

            unlink("{$root}/commands");
            $process = new Process(
                ['bash', "{$root}/prepare-node.sh", 'align-identity'],
                env: [...$environment, 'ALIGN_ORBIT_UID' => '1000', 'ALIGN_ORBIT_GID' => '1000'],
            );
            expect($process->run())
                ->toBe(0)
                ->and(array_values(preg_grep(
                    '/^(userdel|groupmod|usermod|systemctl) /',
                    file("{$root}/commands", FILE_IGNORE_NEW_LINES),
                )))
                ->toBe([
                    'systemctl is-active --quiet systemd-resolved',
                ])
                ->and(file_get_contents("{$root}/commands"))
                ->toContain('find /home/orbit -xdev ( -nouser -o -nogroup ) -exec python3 -c');
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });

    it('limits DHCP to uplinks and grants Docker access only where installed, idempotently', function (bool $network, bool $docker, bool $member, bool $recipeLauncher = false) {
        $root = temporaryPath('orbit-runtime-', 4);
        mkdir("{$root}/bin", 0o700, true);
        mkdir("{$root}/usr/local/bin", 0o700, true);
        mkdir("{$root}/opt/orbit/bun/bin", 0o700, true);
        $bun = "{$root}/usr/local/bin/bun";
        if ($recipeLauncher) {
            file_put_contents($bun, "#!/bin/sh\nexec {$root}/opt/orbit/bun/bin/bun \"\$@\"\n");
            chmod($bun, 0o755);
            file_put_contents("{$root}/opt/orbit/bun/bin/bun", "#!/bin/sh\nexit 0\n");
            chmod("{$root}/opt/orbit/bun/bin/bun", 0o755);
        }
        mkdir("{$root}/etc/systemd/network", 0o700, true);
        $path = "{$root}/etc/systemd/network/00-orbit-e2e.network";
        if ($network) {
            file_put_contents($path, "[Match]\nType=ether\n\n[Network]\nDHCP=ipv4\nLinkLocalAddressing=no\n");
        }
        if ($member) {
            touch("{$root}/member");
        }
        $commands = [
            'systemctl' => "if [ \"\$1\" = mask ]; then printf '%s\\n' \"\$*\" >> '{$root}/masks'; exit 0; fi; exit 3",
            'find' => 'exit 0',
            'networkctl' => "printf '%s\\n' \"networkctl \$*\" >> '{$root}/calls'",
            'getent' => $docker ? 'exit 0' : 'exit 2',
            'id' => "if [ \"\$1\" = -u ] || [ \"\$1\" = -g ]; then echo 1000; elif [ -f '{$root}/member' ]; then echo 'orbit docker'; else echo orbit; fi",
            'usermod' => "printf '%s\\n' \"usermod \$*\" >> '{$root}/calls'; touch '{$root}/member'",
            'docker' => 'exit 0',
            'stat' => 'echo 0:0:755',
        ];
        foreach ($commands as $name => $body) {
            file_put_contents("{$root}/bin/{$name}", "#!/bin/sh\n{$body}\n");
            chmod("{$root}/bin/{$name}", 0o700);
        }
        mkdir("{$root}/home/orbit/.ssh", 0o700, true);
        file_put_contents("{$root}/home/orbit/.ssh/known_hosts", "builder host key\n");
        $source = file_get_contents(dirname(__DIR__, 3).'/resources/guest/prepare-node.sh');
        $script = str_replace(
            ['/etc/', '/root/', '/home/', '/usr/local/bin/', '/opt/orbit/'],
            ["{$root}/etc/", "{$root}/root/", "{$root}/home/", "{$root}/usr/local/bin/", "{$root}/opt/orbit/"],
            $source,
        );
        foreach (['/etc/', '/root/', '/home/', '/usr/local/bin/', '/opt/orbit/'] as $prefix) {
            expect(str_replace("{$root}/", '', $script))->not->toContain($prefix);
        }
        file_put_contents("{$root}/prepare-node.sh", $script);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $process = new Process(['bash', "{$root}/prepare-node.sh", 'configure-runtime'], env: ['PATH' => "{$root}/bin:".getenv('PATH')]);
            expect($process->run())->toBe(0, $process->getErrorOutput());
            if ($recipeLauncher) {
                expect(is_link("{$root}/usr/local/bin/bun"))->toBeTrue();
                expect(readlink("{$root}/usr/local/bin/bun"))->toBe("{$root}/opt/orbit/bun/bin/bun");
            }
        }

        $expected = ($network ? "networkctl reload\n" : '').($docker && ! $member ? "usermod --append --groups docker orbit\n" : '');
        expect(is_file("{$root}/calls") ? file_get_contents("{$root}/calls") : '')->toBe($expected);
        expect(file_get_contents("{$root}/home/orbit/.ssh/known_hosts"))->toBe('');
        expect(file("{$root}/masks", FILE_IGNORE_NEW_LINES))->toBe(array_fill(0, 2, 'mask apt-daily.timer apt-daily-upgrade.timer unattended-upgrades.service motd-news.timer'));
        expect(is_file($path))->toBe($network);
        if ($recipeLauncher) {
            expect(is_link($bun))->toBeTrue()
                ->and(readlink($bun))->toBe("{$root}/opt/orbit/bun/bin/bun")
                ->and(is_executable($bun))->toBeTrue();
        }
        if ($network) {
            expect(file_get_contents($path))->toBe("[Match]\nType=ether\nName=en* eth*\n\n[Network]\nDHCP=ipv4\nLinkLocalAddressing=no\n");
            expect(fileperms($path) & 0o777)->toBe(0o644);
        }
    })->with([
        'old Docker VM' => [true, true, false],
        'already authorized Docker VM' => [true, true, true],
        'VM without Docker' => [true, false, false],
        'roleless container' => [false, false, false],
        'Docker without harness networking' => [false, true, false],
        'recipe base VM' => [true, true, false, true],
    ]);

    it('adapts only the known root-owned recipe Bun launcher, idempotently', function (string $contents, string $owner, bool $converted): void {
        $root = temporaryPath('orbit-bun-', 4);
        mkdir("{$root}/bin", 0o700, true);
        mkdir("{$root}/usr/local/bin", 0o700, true);
        mkdir("{$root}/opt/orbit/bun/bin", 0o700, true);
        $path = "{$root}/usr/local/bin/bun";
        $contents = str_replace('/opt/orbit/', "{$root}/opt/orbit/", $contents);
        file_put_contents($path, $contents);
        foreach ([
            'id' => 'echo 1000',
            'find' => 'exit 0',
            'systemctl' => 'if [ "$1" = mask ]; then exit 0; fi; exit 3',
            'getent' => 'exit 2',
            'stat' => "echo '{$owner}'",
        ] as $name => $body) {
            file_put_contents("{$root}/bin/{$name}", "#!/bin/sh\n{$body}\n");
            chmod("{$root}/bin/{$name}", 0o700);
        }
        $source = file_get_contents(dirname(__DIR__, 3).'/resources/guest/prepare-node.sh');
        $source = str_replace(
            ['/etc/', '/root/', '/home/', '/usr/local/bin/', '/opt/orbit/'],
            ["{$root}/etc/", "{$root}/root/", "{$root}/home/", "{$root}/usr/local/bin/", "{$root}/opt/orbit/"],
            $source,
        );
        file_put_contents("{$root}/prepare-node.sh", $source);
        foreach ([1, 2] as $attempt) {
            $process = new Process(['bash', "{$root}/prepare-node.sh", 'configure-runtime'], env: ['PATH' => "{$root}/bin:".getenv('PATH')]);
            expect($process->run())->toBe(0, $process->getErrorOutput());
        }
        expect(is_link($path))->toBe($converted);
        if ($converted) {
            expect(readlink($path))->toBe("{$root}/opt/orbit/bun/bin/bun");
        } else {
            expect(file_get_contents($path))->toBe($contents);
        }
    })->with([
        'recipe launcher' => ["#!/bin/sh\nexec /opt/orbit/bun/bin/bun \"$@\"\n", '0:0:755', true],
        'foreign executable' => ["#!/bin/sh\necho custom\n", '0:0:755', false],
        'foreign owner' => ["#!/bin/sh\nexec /opt/orbit/bun/bin/bun \"$@\"\n", '1000:1000:755', false],
    ]);

    it('does not expose an ssh-pins mode', function () {
        $source = file_get_contents(dirname(__DIR__, 3).'/resources/guest/prepare-node.sh');
        expect($source)->not->toContain('ssh-pins');
    });
});
