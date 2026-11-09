<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeWireGuardHubInput;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\VpnSettings;
use App\Models\Node;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

it('prunes the peer files of Nodes that are gone or being removed, and keeps every other Node\'s file', function (): void {
    $hub = SwampNodeFixture::ensure();
    $node = static fn (string $name, string $ip, LifecycleStatus $status, ?string $key): Node => Node::withoutEvents(static fn (): Node => Node::query()->create([
        'name' => $name, 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => $ip, 'wireguard_public_key' => $key,
        'user' => 'orbit', 'status' => $status, 'platform' => 'linux', 'architecture' => 'x86_64']));
    $gone = $node('gone', '10.44.0.25', LifecycleStatus::Active, base64_encode(str_repeat('c', 32)));
    $goneId = $gone->id;
    Node::withoutEvents(static fn () => $gone->delete());
    $keyed = $node('keyed', '10.44.0.20', LifecycleStatus::Active, base64_encode(str_repeat('a', 32)));
    $unkeyed = $node('unkeyed', '10.44.0.21', LifecycleStatus::Active, null);
    $enrolling = $node('enrolling', '10.44.0.22', LifecycleStatus::Provisioning, null);
    $failed = $node('failed', '10.44.0.23', LifecycleStatus::Failed, null);
    $removing = $node('removing', '10.44.0.24', LifecycleStatus::Removing, base64_encode(str_repeat('b', 32)));

    $intent = NodeWireGuardHubInput::intent($hub);

    expect(array_column($intent['peers'], 'node_id'))->toBe([$keyed->id])
        ->and($intent['prune']['directory'])->toBe('/etc/wireguard/orbit-peers.d')
        ->and($intent['prune']['keep'])->toBe([$keyed->id, $unkeyed->id, $enrolling->id, $failed->id])
        ->and($intent['prune']['newest'])->toBe($removing->id);
    // The gone Node's id is at or below the newest one, so the prune deletes its file; the removing Node's too.
    expect($goneId)->toBeLessThanOrEqual($intent['prune']['newest']);
});

it('records the newest Node id, below every Node created later, and removes only the removed Node\'s file', function (): void {
    $hub = SwampNodeFixture::ensure();
    $node = static fn (string $name, string $ip): Node => Node::withoutEvents(static fn (): Node => Node::query()->create([
        'name' => $name, 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => $ip, 'wireguard_public_key' => null,
        'user' => 'orbit', 'status' => LifecycleStatus::Provisioning, 'platform' => 'linux', 'architecture' => 'x86_64']));
    $removed = $node('removed', '10.44.0.20');
    $removed->forceFill(['status' => LifecycleStatus::Removing])->saveQuietly();
    $newest = $node('newest', '10.44.0.21');
    // The newest Node is deleted before the intent, so the snapshot's newest id is the removed Node's.
    Node::withoutEvents(static fn () => $newest->delete());

    $intent = NodeWireGuardHubInput::intent($hub);
    $removal = NodeWireGuardHubInput::removal($hub, $removed);
    // A task VM claimed while the run waits: it joins after the snapshot and writes its own peer file.
    $joined = $node('joined', '10.44.0.22');

    expect($joined->id)->toBeGreaterThan($intent['prune']['newest'])
        ->and($intent['prune']['keep'])->not->toContain($joined->id)
        ->and($removal['prune_argv'])->toBe(['rm', '-f', '--', "/etc/wireguard/orbit-peers.d/{$removed->id}.conf"]);
});

it('removes one Node from the hub by deleting its own file, and writes or keeps no other peer', function (): void {
    $hub = SwampNodeFixture::ensure();
    $node = static fn (string $name, string $ip, LifecycleStatus $status, ?string $key): Node => Node::withoutEvents(static fn (): Node => Node::query()->create([
        'name' => $name, 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => $ip, 'wireguard_public_key' => $key,
        'user' => 'orbit', 'status' => $status, 'platform' => 'linux', 'architecture' => 'x86_64']));
    $other = $node('other', '10.44.0.20', LifecycleStatus::Active, base64_encode(str_repeat('a', 32)));
    $removed = $node('removed', '10.44.0.21', LifecycleStatus::Removing, null);

    $intent = NodeWireGuardHubInput::removal($hub, $removed);

    // A removal admitted at the same time as another lists its Node as a peer; neither run may write or keep the other's file.
    expect($intent['peers'])->toBe([])->and($intent['peer_keys'])->toBe([])
        ->and($intent['prune_argv'])->toBe(['rm', '-f', '--', "/etc/wireguard/orbit-peers.d/{$removed->id}.conf"])
        ->and($intent['config'])->toBe(NodeWireGuardHubInput::config($hub));
    // A rewrite lists the other Node, which is the snapshot a concurrent removal must not carry.
    expect(array_column(NodeWireGuardHubInput::intent($hub)['peers'], 'node_id'))->toBe([$other->id]);
});

it('marks the hub\'s WireGuard packets, and leaves the source rule to its own unit, outside orbit.conf', function (): void {
    $hub = SwampNodeFixture::ensure();
    $hub->forceFill(['public_ssh_host' => '94.237.124.160', 'lan_ip' => '10.0.0.2'])->saveQuietly();
    app(VpnSettings::class)->configure(subnet: '10.44.0.0/24', port: 51820);

    $intent = NodeWireGuardHubInput::intent($hub);

    // The 2026-11-09 incident: the hub started handshakes from its DHCP primary address and peers roamed to it. orbit.conf only
    // marks the packets: node:wireguard-key rewrites it without PostUp lines, so the rules cannot live there.
    expect($intent['config'])->toBe(<<<'CONF'
        [Interface]
        Address = 10.44.0.209/24
        ListenPort = 51820
        FwMark = 51820
        PostUp = wg set %i private-key /etc/wireguard/orbit.key
        PostUp = for f in /etc/wireguard/orbit-peers.d/*.conf; do [ -e "$f" ] && wg addconf %i "$f"; done; true

        CONF)
        ->and($intent['source']['rules'])->toBe(['31820:0xca6c:10.0.0.0/8:main', '31820:0xca6c:172.16.0.0/12:main',
            '31820:0xca6c:192.168.0.0/16:main', '31821:0xca6c:all:51820'])
        ->and($intent['source']['script'])->toBe(<<<'SH'
            #!/bin/sh
            # Managed by Orbit (ADR 0251): the hub sends WireGuard packets to public peers from 94.237.124.160.
            set -eu
            while ip -4 rule del priority 31820 2>/dev/null; do :; done
            while ip -4 rule del priority 31821 2>/dev/null; do :; done
            ip -4 route flush table 51820 2>/dev/null || true
            ip -4 rule add priority 31820 fwmark 51820 to 10.0.0.0/8 lookup main
            ip -4 rule add priority 31820 fwmark 51820 to 172.16.0.0/12 lookup main
            ip -4 rule add priority 31820 fwmark 51820 to 192.168.0.0/16 lookup main
            ip -4 rule add priority 31821 fwmark 51820 lookup 51820
            # The table copies the first default route of the main table and leaves from the endpoint address. At boot DHCP
            # may not have set the default route yet, so the script waits for it.
            for _ in $(seq 1 60); do
              set -- $(ip -4 route show default | head -n 1)
              route=
              while [ "$#" -gt 0 ]; do
                case "$1" in
                  via|dev) [ "$#" -gt 1 ] && route="$route $1 $2" && shift ;;
                  onlink) route="$route onlink" ;;
                esac
                shift
              done
              case "$route" in *via*) break ;; esac
              sleep 1
            done
            ip -4 route replace default $route src 94.237.124.160 table 51820

            SH)
        // Wanted by the tunnel, never required: a failing rule cannot keep the tunnel down.
        ->and($intent['source']['unit'])->toContain("Before=wg-quick@orbit.service\n")->toContain("ExecStart=/bin/sh /etc/orbit/wireguard/hub-source\n")
        ->toContain("WantedBy=wg-quick@orbit.service multi-user.target\n")->not->toContain('Requires=')->not->toContain('BindsTo=')
        // The networkd drop-in keeps the rules; a restart of networkd must not rerun the script before DHCP set a default route.
        ->not->toContain('PartOf=')
        ->and($intent['source']['networkd'])->toBe("[Network]\nManageForeignRoutingPolicyRules=no\nManageForeignRoutes=no\n")
        ->and($intent['source']['priorities'])->toBe([31820, 31821])
        ->and($intent['lan_ssh_argv'])->toBe([['ufw', 'allow', 'in', 'proto', 'tcp', 'from', '10.0.0.0/24', 'to', '10.0.0.2', 'port', '22', 'comment', 'orbit:vpn-lan-ssh']]);
});

it('keeps only private ranges on the main table, so the hub\'s own public prefix leaves from the endpoint too', function (): void {
    $hub = SwampNodeFixture::ensure();
    $hub->forceFill(['public_ssh_host' => '94.237.124.160'])->saveQuietly();
    app(VpnSettings::class)->configure(subnet: '10.44.0.0/24', port: 51820);
    expect(NodeWireGuardHubInput::source($hub)['script'])->not->toContain('suppress_prefixlength')->not->toContain('85.9.216.0/22');

    // A WireGuard subnet outside RFC 1918 keeps the main table as well.
    app(VpnSettings::class)->configure(subnet: '100.64.7.0/24', port: 51820);
    expect(NodeWireGuardHubInput::source($hub)['rules'])->toContain('31820:0xca6c:100.64.7.0/24:main');
});

it('takes the source address from the stored Gateway endpoint, and pins none for a hostname endpoint', function (): void {
    $hub = SwampNodeFixture::ensure();
    app(VpnSettings::class)->configure(subnet: '10.44.0.0/24', port: 51820, endpoint: '198.51.100.7:51820');
    expect(NodeWireGuardHubInput::source($hub)['address'])->toBe('198.51.100.7');

    app(VpnSettings::class)->configure(subnet: '10.44.0.0/24', port: 51820, endpoint: 'vpn.example.com:51820');

    // A hostname leaves the hub nothing to pin, but every syncconf still carries the mark, so the interface keeps it.
    expect(NodeWireGuardHubInput::source($hub))->toBeNull()
        ->and(NodeWireGuardHubInput::config($hub))->toContain("FwMark = 51820\n")
        ->and(NodeWireGuardHubInput::interface(51820))->toBe("ListenPort = 51820\nFwMark = 51820\n");
});

it('fits the closed intent schema of the hub workflow that a removal runs, and carries the mark in its syncconf', function (): void {
    $hub = SwampNodeFixture::ensure();
    $removed = Node::withoutEvents(static fn (): Node => Node::query()->create([
        'name' => 'removed', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.21', 'user' => 'orbit',
        'status' => LifecycleStatus::Removing, 'platform' => 'linux', 'architecture' => 'x86_64']));
    $workflow = Yaml::parseFile(dirname(base_path(), 2).'/swamp/workflows/workflow-node-network-hub.yaml');
    $schema = $workflow['inputs']['properties']['intent'];
    $intent = NodeWireGuardHubInput::removal($hub, $removed);

    expect(array_keys($intent))->toEqualCanonicalizing(array_keys($schema['properties']))
        ->and(array_diff($schema['required'], array_keys($intent)))->toBe([])
        ->and($intent['interface'])->toBe(NodeWireGuardHubInput::interface(51820))
        ->and(array_key_exists('source', $intent))->toBeTrue()
        ->and(json_encode($workflow, JSON_THROW_ON_ERROR))->toContain('inputs.intent.interface');
});

it('runs its script to the same rules every time, from the first default route only', function (): void {
    $hub = SwampNodeFixture::ensure();
    $hub->forceFill(['public_ssh_host' => '94.237.124.160'])->saveQuietly();
    app(VpnSettings::class)->configure(subnet: '10.44.0.0/24', port: 51820);
    $folder = sys_get_temp_dir().'/orbit-hub-source-'.bin2hex(random_bytes(4));
    mkdir($folder.'/bin', 0o755, true);
    file_put_contents($folder.'/script', NodeWireGuardHubInput::source($hub)['script']);
    // A stand-in `ip`: two default routes, the first on-link, and rule deletes that succeed twice before nothing is left.
    file_put_contents($folder.'/bin/ip', <<<'SH'
        #!/bin/sh
        echo "ip $*" >> "$STUB/calls.log"
        case "$*" in
          "-4 route show default") printf 'default via 85.9.216.1 dev eth0 proto dhcp src 85.9.218.89 metric 100 onlink\ndefault via 10.0.0.1 dev eth1 metric 200\n' ;;
          "-4 rule del priority "*) n=$(cat "$STUB/del" 2>/dev/null || echo 0); echo $((n + 1)) > "$STUB/del"; [ "$n" -lt 2 ] ;;
        esac
        SH);
    chmod($folder.'/bin/ip', 0o755);

    $run = Process::path($folder)->env(['PATH' => $folder.'/bin:'.getenv('PATH'), 'STUB' => $folder])->run(['sh', $folder.'/script']);
    $calls = (string) file_get_contents($folder.'/calls.log');
    File::deleteDirectory($folder);

    expect($run->successful())->toBeTrue($run->errorOutput())
        ->and($calls)->toContain("ip -4 rule add priority 31820 fwmark 51820 to 192.168.0.0/16 lookup main\nip -4 rule add priority 31821 fwmark 51820 lookup 51820\n")
        ->and($calls)->toContain("ip -4 route replace default via 85.9.216.1 dev eth0 onlink src 94.237.124.160 table 51820\n")
        ->and($calls)->not->toContain('10.0.0.1');
});

it('waits in its script for the default route that DHCP sets after boot', function (): void {
    $hub = SwampNodeFixture::ensure();
    $hub->forceFill(['public_ssh_host' => '94.237.124.160'])->saveQuietly();
    app(VpnSettings::class)->configure(subnet: '10.44.0.0/24', port: 51820);
    $folder = sys_get_temp_dir().'/orbit-hub-wait-'.bin2hex(random_bytes(4));
    mkdir($folder.'/bin', 0o755, true);
    file_put_contents($folder.'/script', NodeWireGuardHubInput::source($hub)['script']);
    // No default route on the first read; the second read has it.
    file_put_contents($folder.'/bin/ip', <<<'SH'
        #!/bin/sh
        echo "ip $*" >> "$STUB/calls.log"
        case "$*" in
          "-4 route show default") if [ -f "$STUB/seen" ]; then echo 'default via 85.9.216.1 dev eth0 proto dhcp metric 100'; else touch "$STUB/seen"; fi ;;
          "-4 rule del priority "*) exit 1 ;;
        esac
        SH);
    file_put_contents($folder.'/bin/sleep', "#!/bin/sh\nexit 0\n");
    chmod($folder.'/bin/ip', 0o755);
    chmod($folder.'/bin/sleep', 0o755);

    $run = Process::path($folder)->env(['PATH' => $folder.'/bin:'.getenv('PATH'), 'STUB' => $folder])->run(['sh', $folder.'/script']);
    $calls = (string) file_get_contents($folder.'/calls.log');
    File::deleteDirectory($folder);

    expect($run->successful())->toBeTrue($run->errorOutput())
        ->and(substr_count($calls, "ip -4 route show default\n"))->toBe(2)
        ->and($calls)->toContain("ip -4 route replace default via 85.9.216.1 dev eth0 src 94.237.124.160 table 51820\n");
});
