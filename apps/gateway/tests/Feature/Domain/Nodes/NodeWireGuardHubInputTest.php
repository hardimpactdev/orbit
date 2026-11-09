<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeWireGuardHubInput;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\VpnSettings;
use App\Models\Node;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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

it('marks the hub\'s WireGuard packets and sends them to public peers from the public endpoint address', function (): void {
    $hub = SwampNodeFixture::ensure();
    $hub->forceFill(['public_ssh_host' => '94.237.124.160', 'lan_ip' => '10.0.0.2'])->saveQuietly();
    app(VpnSettings::class)->configure(subnet: '10.44.0.0/24', port: 51820);

    $intent = NodeWireGuardHubInput::intent($hub);

    // The 2026-11-09 incident: the hub started handshakes from its DHCP primary address and peers roamed to it. The rules
    // send every marked packet through the main table without its default route, then through the hub's own table, whose
    // default route leaves from the floating endpoint address; private networks keep their route and source.
    expect($intent['config'])->toBe(<<<'CONF'
        [Interface]
        Address = 10.44.0.209/24
        ListenPort = 51820
        FwMark = 51820
        PostUp = wg set %i private-key /etc/wireguard/orbit.key
        PostUp = for f in /etc/wireguard/orbit-peers.d/*.conf; do [ -e "$f" ] && wg addconf %i "$f"; done; true
        PostUp = ip -4 rule del priority 31820 2>/dev/null; ip -4 rule add priority 31820 fwmark 51820 lookup main suppress_prefixlength 0
        PostUp = ip -4 rule del priority 31821 2>/dev/null; ip -4 rule add priority 31821 fwmark 51820 lookup 51820
        PostUp = r=$(ip -4 route show default | awk '{for (i = 1; i < NF; i++) if ($i == "via" || $i == "dev") printf "%s %s ", $i, $(i + 1); exit}'); [ -z "$r" ] || ip -4 route replace default $r src 94.237.124.160 table 51820 || true

        CONF)
        ->and($intent['source'])->toBe(['address' => '94.237.124.160', 'mark' => 51820,
            'rules' => ['31820:0xca6c:main', '31821:0xca6c:51820'],
            'commands' => array_map(static fn (string $line): string => substr($line, strlen('PostUp = ')),
                array_slice(explode("\n", trim($intent['config'])), 6))]);
});

it('takes the source address from the stored Gateway endpoint, and pins none for a hostname endpoint', function (): void {
    $hub = SwampNodeFixture::ensure();
    app(VpnSettings::class)->configure(subnet: '10.44.0.0/24', port: 51820, endpoint: '198.51.100.7:51820');
    expect(NodeWireGuardHubInput::source($hub)['address'])->toBe('198.51.100.7');

    app(VpnSettings::class)->configure(subnet: '10.44.0.0/24', port: 51820, endpoint: 'vpn.example.com:51820');

    // A hostname leaves the hub nothing to pin, but every syncconf still carries the mark, so the interface keeps it.
    expect(NodeWireGuardHubInput::source($hub))->toBeNull()
        ->and(NodeWireGuardHubInput::config($hub))->toContain("FwMark = 51820\n")->not->toContain('ip -4 rule')
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
        ->and(json_encode($workflow, JSON_THROW_ON_ERROR))->toContain('inputs.intent.interface');
});
