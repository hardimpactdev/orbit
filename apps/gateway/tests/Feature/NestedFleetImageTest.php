<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceHostIntent;
use App\Domain\Instances\InstanceImage;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Models\Node;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function nestedImageHost(bool $switch): Node
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'shark', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => '10.44.0.11', 'public_ssh_host' => '10.44.0.11', 'user' => 'orbit',
        'settings' => $switch ? ['nested_fleet' => true] : [],
    ]));
    config(['instances.container_nodes' => [$node->id]]);
    app(InstanceHostIntent::class)->prepare($node);

    return $node->refresh();
}

/** @return array{line: string, packages: list<array{name: string, sha256: string, urls: list<string>}>} */
function nestedImageIncus(): array
{
    return app(RecipeImageBuilds::class)->currentRecipe()->incus('amd64') ?? throw new RuntimeException('The recipe holds no Incus packages.');
}

it('keeps the normal image definition byte-identical to the one before the nested-fleet variant', function (): void {
    $normal = InstanceImage::for('10.253.0.1');

    // Captured at 2a-local 7b9e25ba02. The alias is a digest of the rest of the definition, and the second value of the whole of it.
    expect($normal['alias'])->toBe('orbit-instance-797779e64d85')
        ->and(hash('sha256', json_encode($normal, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)))
        ->toBe('e46d7b0f395fadb16f8e12185f15f3e700e12b1524ff306e7d7efd066d290e16');
});

it('gives a Node without the switch the same host intent members as before, and a switched-on Node only the nested image more', function (): void {
    $off = app(InstanceHostIntent::class)->for(nestedImageHost(false));
    Node::query()->delete();
    $on = app(InstanceHostIntent::class)->for(nestedImageHost(true));

    expect(array_keys($off))->toBe(['project', 'network', 'nic_acl', 'pool', 'cidr', 'address', 'build_address', 'dhcp_range', 'stock',
        'forwarder_port', 'ingress', 'bridge_admitted_at', 'bridge_endpoints', 'egress', 'ids', 'arc', 'incus', 'image', 'folders',
        'dev_ports', 'profile_config'])
        ->and($off['image'])->toBe(InstanceImage::for('10.253.0.1'))
        ->and($on['nested'])->toBe(['project' => 'orbit-instances-nested', 'image' => InstanceImage::for('10.253.0.1', nestedImageIncus())])
        // The hosting change adds the two spare ID maps of a switched-on Node; everything else is the same.
        ->and($on['ids'] - $off['ids'])->toBe(2 * InstanceHostIntent::TaskPlaceIds)
        ->and(array_diff_key($on, ['nested' => true, 'ids' => true, 'bridge_admitted_at' => true, 'dev_ports' => true]))
        ->toBe(array_diff_key($off, ['ids' => true, 'bridge_admitted_at' => true, 'dev_ports' => true]));
});

it('builds the nested-fleet variant with the pinned inner Incus, agent authority, user namespaces and the orbit guard', function (): void {
    $incus = nestedImageIncus();
    $normal = InstanceImage::for('10.253.0.1');
    $nested = InstanceImage::for('10.253.0.1', $incus);
    $files = array_column($nested['files'], null, 'path');

    expect($nested['alias'])->toStartWith('orbit-instance-nested-')->toHaveLength(strlen('orbit-instance-nested-') + 12)
        ->and($nested['packages'])->toBe(['acl', 'git', 'nftables', 'wireguard-tools', 'sqlite3', 'sudo', 'zstd'])
        ->and(array_column($nested['incus'], 'name'))->toBe(['incus-base', 'incus-client'])
        ->and($nested['incus'])->toBe(array_values(array_filter($incus['packages'], fn (array $package): bool => $package['name'] !== 'incus')))
        ->and($nested['prepare'])->toBe($normal['prepare']."usermod --append --groups incus-admin agent\n")
        ->and($files)->not->toHaveKey('/etc/sysctl.d/90-orbit-no-userns.conf')
        ->and($files['/etc/subuid'])->toBe(['path' => '/etc/subuid', 'content' => "root:1000000:100000000\nroot:1000:1\nroot:2750:1\n", 'uid' => 0, 'gid' => 0, 'mode' => '0644'])
        ->and($files['/etc/subgid']['content'])->toBe("root:1000000:100000000\nroot:1000:1\nroot:2760:1\n")
        ->and($files['/etc/orbit/apparmor-off']['content'])->toBe("N\n")
        ->and($files['/etc/sudoers.d/orbit-agent'])->toBe(['path' => '/etc/sudoers.d/orbit-agent', 'content' => "agent ALL=(ALL:ALL) NOPASSWD: ALL\n", 'uid' => 0, 'gid' => 0, 'mode' => '0440'])
        // The network wait asks only for a route on eth0: the DNS that netplan's wait asks for never comes, and held each start for 120 s.
        ->and($files[InstanceImage::NestedWaitOnline]['content'])
        ->toBe("# Managed by Orbit.\n[Service]\nExecStart=\nExecStart=/usr/lib/systemd/systemd-networkd-wait-online --interface=eth0:routable --timeout=30\n")
        // It sorts after netplan's drop-in, so its ExecStart replaces netplan's.
        ->and(strcmp(basename(InstanceImage::NestedWaitOnline), '10-netplan.conf'))->toBeGreaterThan(0)
        // A stop of nftables leaves the inner Incus's tables alone.
        ->and($files['/etc/systemd/system/nftables.service.d/90-orbit-own-table.conf']['content'])
        ->toBe("# Managed by Orbit.\n[Service]\nExecStop=\nExecStop=/usr/sbin/nft destroy table inet orbit_agent_guard\n")
        // Every other file and step is the normal image's.
        ->and(array_values(array_diff_key($files, array_flip(['/etc/nftables.conf', '/etc/subuid', '/etc/subgid', '/etc/orbit/apparmor-off', '/etc/sudoers.d/orbit-agent',
            InstanceImage::NestedWaitOnline, '/etc/systemd/system/nftables.service.d/90-orbit-own-table.conf']))))
        ->toBe(array_values(array_filter($normal['files'], fn (array $file): bool => ! in_array($file['path'], ['/etc/nftables.conf', '/etc/sysctl.d/90-orbit-no-userns.conf'], true))))
        ->and($nested['finish'])->toBe($normal['finish']);

    $rules = array_map('trim', explode("\n", $files['/etc/nftables.conf']['content']));
    $normalRules = array_map('trim', explode("\n", InstanceImage::firewall('10.253.0.1')));
    $policy = array_search('type filter hook output priority 0; policy accept;', $rules, true);
    expect($rules[$policy + 1])->toBe('ip daddr { 10.45.0.0/24, 10.232.2.0/24 } meta skuid 1000 drop')
        // The file replaces only Orbit's own table; the normal image keeps its flush of the whole rule set.
        ->and($rules)->toContain('destroy table inet orbit_agent_guard')->not->toContain('flush ruleset')
        ->and(array_values(array_diff($rules, [$rules[$policy + 1], 'destroy table inet orbit_agent_guard'])))->toBe(array_values(array_diff($normalRules, ['flush ruleset'])))
        ->and($normalRules)->toContain('flush ruleset')
        ->and(implode("\n", $normalRules))->not->toContain('10.45.0.0/24');

    $changed = $incus;
    $changed['packages'][1]['sha256'] = str_repeat('b', 64);
    $mirrored = $incus;
    $mirrored['packages'][1]['urls'] = ['https://mirror.example.test/incus-base.deb'];
    expect(InstanceImage::for('10.253.0.1', $changed)['alias'])->not->toBe($nested['alias'])
        // Another download URL for the same digest is the same image, so a hand build and the converge agree on the alias.
        ->and(InstanceImage::for('10.253.0.1', $mirrored)['alias'])->toBe($nested['alias']);
});
