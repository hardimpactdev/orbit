<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeWireGuardHubInput;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmFleetBuildIntent;
use App\Models\Node;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

it('prunes the peer files of Nodes that are gone or being removed, and keeps every other Node\'s file', function (): void {
    $hub = SwampNodeFixture::ensure();
    $node = static fn (string $name, string $ip, LifecycleStatus $status, ?string $key): Node => Node::withoutEvents(static fn (): Node => Node::query()->create([
        'name' => $name, 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => $ip, 'wireguard_public_key' => $key,
        'user' => 'orbit', 'status' => $status, 'platform' => 'linux', 'architecture' => 'x86_64']));
    $keyed = $node('keyed', '10.44.0.20', LifecycleStatus::Active, base64_encode(str_repeat('a', 32)));
    $unkeyed = $node('unkeyed', '10.44.0.21', LifecycleStatus::Active, null);
    $enrolling = $node('enrolling', '10.44.0.22', LifecycleStatus::Provisioning, null);
    $failed = $node('failed', '10.44.0.23', LifecycleStatus::Failed, null);
    $removing = $node('removing', '10.44.0.24', LifecycleStatus::Removing, base64_encode(str_repeat('b', 32)));
    $gone = $node('gone', '10.44.0.25', LifecycleStatus::Active, base64_encode(str_repeat('c', 32)));
    $goneId = $gone->id;
    Node::withoutEvents(static fn () => $gone->delete());

    $intent = NodeWireGuardHubInput::intent($hub);
    $argv = implode(' ', $intent['prune_argv']);

    expect(array_column($intent['peers'], 'node_id'))->toBe([$keyed->id]);
    foreach ([$keyed, $unkeyed, $enrolling, $failed] as $kept) {
        expect($argv)->toContain("! -name {$kept->id}.conf");
    }
    expect($argv)->not->toContain("! -name {$removing->id}.conf")->not->toContain("! -name {$goneId}.conf");
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

it('writes the operator peer in the nested fleet start in the form the hub rendering uses', function (): void {
    $hub = SwampNodeFixture::ensure();
    $workflow = file_get_contents(base_path('../../swamp/workflows/workflow-task-nested-fleet.yaml'));

    // The step "Write the operator peer" pushes `"<head>" + <the key> + "<tail>"` to the peer file of the operator.
    preg_match('/stdin: \'\$\{\{ "(\[Peer\][^"]*)" \+ steps\["Make the operator key"\]\.outputs\.stdout\.split\("\\\\n"\)\[0\] \+ "([^"]*)" \}\}\'/', $workflow, $text);
    preg_match('/"(\/etc\/wireguard\/[^"]*)" \+ steps\["Read the operator id"\]\.outputs\.stdout\.split\("\\\\n"\)\[0\] \+ "(\.conf)"/', $workflow, $path);

    expect($text)->toHaveCount(3)->and($path)->toHaveCount(3);
    $section = stripcslashes($text[1]).'@KEY@'.stripcslashes($text[2]);
    expect($section)->toBe(NodeWireGuardHubInput::section('operator', '@KEY@', TaskVmFleetBuildIntent::OperatorAddress.'/32'))
        ->and($path[1].'7'.$path[2])->toBe(NodeWireGuardHubInput::peerFile(7))
        ->and(NodeWireGuardHubInput::config($hub))->toContain('for f in '.NodeWireGuardHubInput::PeerDirectory.'/*.conf; do [ -e "$f" ] && wg addconf %i "$f"; done');
});
