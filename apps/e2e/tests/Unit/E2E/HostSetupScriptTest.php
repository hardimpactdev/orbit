<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

pest()->group('subprocess');

function hostSetupScript(): string
{
    return dirname(__DIR__, 5).'/bin/e2e-topology-host-setup';
}

/** @param list<string> $arguments */
function runHostSetup(array $arguments): Process
{
    $process = new Process([hostSetupScript(), ...$arguments], timeout: 10);
    $process->run();

    return $process;
}

describe('bin/e2e-topology-host-setup', function () {
    it('is valid Bash', function () {
        expect(new Process(['bash', '-n', hostSetupScript()], timeout: 5)->run())->toBe(0);
    });

    it('refuses invalid options with its usage before it checks for root', function (array $arguments) {
        $process = runHostSetup($arguments);

        expect($process->getExitCode())->toBe(64)
            ->and($process->getOutput())->toBe('')
            ->and($process->getErrorOutput())->toStartWith('usage: sudo bin/e2e-topology-host-setup [--pool-size=SIZE]');
    })->with([
        'pool size without a unit' => [['--pool-size=45']],
        'pool size in GB' => [['--pool-size=45GB']],
        'zero pool size' => [['--pool-size=0GiB']],
        'interface name too long' => [['--wireguard-interface=orbit-wireguard0']],
        'interface name with a space' => [['--wireguard-interface=orbit 0']],
        'subnet without a prefix' => [['--wireguard-subnet=10.44.0.0']],
        'IPv6 subnet' => [['--wireguard-subnet=fd00::/64']],
        'unknown option' => [['--pool=orbit-e2e']],
        'positional argument' => [['orbit-e2e']],
        'help' => [['--help']],
    ]);

    it('refuses to run without root after valid options', function () {
        $process = runHostSetup(['--pool-size=60GiB', '--wireguard-interface=wg0', '--wireguard-subnet=10.45.0.0/24']);

        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toBe("e2e-topology-host-setup: run it with sudo.\n");
    })->skip(fn (): bool => posix_geteuid() === 0, 'As root the script would change this machine.');

    it('gives root a subordinate id range when the files lack one, so Incus can map the operator container', function () {
        $source = (string) file_get_contents(hostSetupScript());

        expect($source)->toContain('for file in /etc/subuid /etc/subgid; do')
            ->toContain('grep -q \'^root:\' "$file"')
            ->toContain('printf \'root:1000000:1000000000\\n\' >>"$file"')
            ->toContain('((idmap_changed == 0)) || systemctl restart incus.service');
    });

    it('lets topology guests get DHCP and DNS and route out through an active UFW', function () {
        $source = (string) file_get_contents(hostSetupScript());

        expect($source)->toContain("if ufw status 2>/dev/null | grep -qx 'Status: active'; then")
            ->toContain('for interface in "$bridge" \'oe+\'; do')
            ->toContain('ufw allow in on "$interface" to any port 67 proto udp')
            ->toContain('ufw allow in on "$interface" to any port 53')
            ->toContain('ufw route allow in on "$interface"');
    });

    it('writes an isolation table that drops topology traffic to the WireGuard network', function () {
        $source = (string) file_get_contents(hostSetupScript());
        expect(preg_match('/<<NFT\n(.*?)\nNFT\n/s', $source, $match))->toBe(1);
        $table = str_replace(
            ['$wireguard_interface', '$bridge', '$wireguard_subnet'],
            ['orbit', 'incusbr0', '10.44.0.0/24'],
            $match[1],
        );
        $rules = array_values(array_filter(
            array_map(trim(...), explode("\n", $table)),
            static fn (string $line): bool => str_ends_with($line, ' drop'),
        ));

        expect($table)->toStartWith("table inet orbit-e2e-isolation\ndelete table inet orbit-e2e-isolation\n")
            ->and($rules)->toBe([
                'iifname "oe*" oifname "orbit" drop',
                'iifname "incusbr0" oifname "orbit" drop',
                'iifname "oe*" ip daddr 10.44.0.0/24 drop',
                'iifname "incusbr0" ip daddr 10.44.0.0/24 drop',
            ])
            ->and($table)
            ->toContain('type filter hook forward priority -10; policy accept;')
            ->toContain('type filter hook input priority -10; policy accept;')
            ->and($source)->toContain('ExecStart=/usr/sbin/nft -f /etc/orbit-e2e/isolation.nft');
    });
});
