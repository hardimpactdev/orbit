<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceEgress;
use App\Domain\Instances\InstanceHostIntent;
use App\Models\Cluster;
use App\Models\Node;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Process;

uses(LazilyRefreshDatabase::class);

describe('the Instance egress proxy', function (): void {
    it('serves only the bridge, on its gateway address, as a forward proxy without cache, disk or headers that name the container', function (): void {
        $squid = InstanceEgress::squid('10.253.0.1', '10.253.0.0/24', ['orbit', 'test']);

        expect($squid)->toContain(
            "http_port 10.253.0.1:3128\n", "dns_nameservers 127.0.0.53\n", "cache deny all\n", "pid_filename none\n", "via off\n",
            "forwarded_for delete\n", "access_log syslog:daemon.info orbit\n", "acl bridge src 10.253.0.0/24\n", "http_access deny !bridge\n",
        )
            // Every public host and port: no port list narrows CONNECT.
            ->and($squid)->not->toContain('SSL_ports', 'Safe_ports', 'cache_dir');
    });

    it('refuses its own cache manager and a private name before it looks it up, and leaves every address to the table', function (): void {
        $squid = InstanceEgress::squid('10.253.0.1', '10.253.0.0/24', ['orbit', 'test']);
        $rules = array_values(array_filter(explode("\n", $squid), static fn (string $line): bool => str_starts_with($line, 'http_access')));

        expect($squid)->toContain("acl private_name dstdomain .orbit .test\n")
            ->and($rules)->toBe(['http_access deny !bridge', 'http_access deny manager', 'http_access deny to_localhost', 'http_access deny private_name',
                'http_access allow bridge', 'http_access deny all'])
            // Squid holds IPv4 as mapped IPv6, so an address list of its own would have to state IPv6 ranges; the table holds the one rule.
            ->and($squid)->not->toContain(' dst ');
    });

    it('keeps the proxy user off the Node, WireGuard and every private address, and lets only the bridge reach the port', function (): void {
        $ruleset = InstanceEgress::ruleset('orbitinst0', '10.253.0.1');

        expect($ruleset)->toContain(
            "table inet orbit_instance_egress\ndelete table inet orbit_instance_egress\n",
            'ip daddr 10.253.0.1 tcp dport 3128 iifname != "orbitinst0" counter reject with tcp reset',
            "meta skuid != \"proxy\" return\n", "ct state != new return\n", 'ip daddr 127.0.0.53 meta l4proto { tcp, udp } th dport 53 return',
            'fib daddr type local counter reject', 'oifname "orbit" counter reject', 'ip daddr @private_v4 counter reject', 'meta nfproto ipv6 counter reject',
            'elements = { '.implode(', ', InstanceEgress::PrivateV4).' }',
        )
            // The resolver's return comes before the rule that refuses every Node address, which holds 127.0.0.53 too.
            ->and(strpos($ruleset, 'ip daddr 127.0.0.53'))->toBeLessThan(strpos($ruleset, 'fib daddr type local'))
            // The fleet range, the VPN and the Node's LAN sit in these ranges. A LAN's IPv6 is often global, so IPv6 is refused whole.
            ->and(InstanceEgress::PrivateV4)->toContain('10.0.0.0/8', '100.64.0.0/10', '172.16.0.0/12', '192.168.0.0/16', '169.254.0.0/16')
            ->and($ruleset)->not->toContain('ip6 daddr');
    });

    it('names the proxy for units, incus exec and login shells, and keeps loopback and the forwarder off it', function (): void {
        $environment = InstanceEgress::environment('10.253.0.1');
        $intent = InstanceEgress::for('orbitinst0', '10.253.0.1', '10.253.0.0/24', ['orbit']);

        expect($environment)->toMatchArray([
            'HTTP_PROXY' => 'http://10.253.0.1:3128', 'https_proxy' => 'http://10.253.0.1:3128', 'NO_PROXY' => 'localhost,127.0.0.1,::1,10.253.0.1',
            'NODE_USE_ENV_PROXY' => '1',
        ])
            ->and($intent['rule'])->toMatchArray(['comment' => 'orbit:instances-egress', 'destination' => '10.253.0.1', 'port' => '3128',
                'protocol' => 'tcp', 'in_interface' => 'orbitinst0'])
            // Squid's AppArmor profile lets it read only /etc/squid.
            ->and($intent)->toMatchArray(['user' => 'proxy', 'unit' => 'orbit-instance-egress.service', 'table' => 'orbit_instance_egress',
                'config' => '/etc/squid/orbit-instance-egress.conf']);
    });

    it('refuses the fleet\'s private routing domains, cluster domains included, in the converge intent', function (): void {
        $node = Node::withoutEvents(fn (): Node => Node::query()->create([
            'name' => 'shark', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
            'wireguard_ip' => '10.44.0.11', 'public_ssh_host' => '10.44.0.11', 'user' => 'orbit',
        ]));
        Cluster::query()->create(['name' => 'home', 'tld' => 'test']);
        config(['instances.container_nodes' => [$node->id]]);
        app(InstanceHostIntent::class)->prepare($node);

        expect(app(InstanceHostIntent::class)->for($node)['egress']['squid'])->toContain('acl private_name dstdomain .orbit .test');
    });
});

/**
 * Loads the rendered table on a real kernel, as root, through `tests/Support/instance-egress-nft-check.sh`: a container on a bridge
 * reaches the proxy and root does not; the proxy user reaches a public host and the stub resolver and is refused at once by a private
 * host, the Node's own addresses and WireGuard. It needs Linux, passwordless sudo, nft, ip, nsenter and python3, so it is in the
 * `privileged` group, which the `gateway-static` CI job runs with `ORBIT_REAL_KERNEL_TESTS=1`.
 */
it('confines the proxy user to public hosts and its port to the bridge, on a real kernel', function (): void {
    $required = getenv('ORBIT_REAL_KERNEL_TESTS') === '1';
    if (PHP_OS_FAMILY !== 'Linux' || Process::run(['sudo', '-n', 'nft', '--version'])->failed()) {
        expect($required)->toBeFalse('ORBIT_REAL_KERNEL_TESTS=1 needs Linux with passwordless sudo and nft.');
        $this->markTestSkipped('Needs Linux with passwordless sudo and nft.');
    }
    $ruleset = tempnam(sys_get_temp_dir(), 'orbit-egress-nft-');
    file_put_contents($ruleset, InstanceEgress::ruleset('oechkb0', '10.253.250.1'));
    try {
        $check = Process::timeout(120)->run(['sudo', '-n', base_path('tests/Support/instance-egress-nft-check.sh'), $ruleset, InstanceEgress::User,
            'oechkb0', '10.253.250.1', (string) InstanceEgress::Port]);
    } finally {
        unlink($ruleset);
    }

    expect($check->exitCode())->toBe(0, $check->output().$check->errorOutput())
        ->and($check->output())->toContain('ok: container to the proxy, on the bridge: reached')
        ->toContain('ok: root to the proxy, over lo: refused')
        ->toContain('ok: the proxy user to a public host: reached')
        ->toContain('ok: the proxy user to the stub resolver: reached')
        ->toContain('ok: the proxy user to a private host: refused')
        ->toContain('ok: the proxy user to the bridge address: refused')
        ->toContain('ok: the proxy user to WireGuard: refused')
        ->toContain('ok: the proxy user to a global IPv6 host: refused');
})->group('privileged');
