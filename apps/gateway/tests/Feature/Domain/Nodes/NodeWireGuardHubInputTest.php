<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeWireGuardHubInput;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

it('prunes only the peer files of Nodes that are being removed, so a Node without a recorded key keeps its file', function (): void {
    $hub = SwampNodeFixture::ensure();
    $node = static fn (string $name, string $ip, LifecycleStatus $status, ?string $key): Node => Node::withoutEvents(static fn (): Node => Node::query()->create([
        'name' => $name, 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => $ip, 'wireguard_public_key' => $key,
        'user' => 'orbit', 'status' => $status, 'platform' => 'linux', 'architecture' => 'x86_64']));
    $keyed = $node('keyed', '10.44.0.20', LifecycleStatus::Active, base64_encode(str_repeat('a', 32)));
    // A task VM between its enrollment and the end of its provision: its run added the peer, the Gateway has no key yet.
    $unkeyed = $node('unkeyed', '10.44.0.21', LifecycleStatus::Active, null);
    $enrolling = $node('enrolling', '10.44.0.22', LifecycleStatus::Provisioning, null);
    $failed = $node('failed', '10.44.0.23', LifecycleStatus::Failed, null);
    $removing = $node('removing', '10.44.0.24', LifecycleStatus::Removing, base64_encode(str_repeat('b', 32)));

    $intent = NodeWireGuardHubInput::intent($hub);
    $argv = implode(' ', $intent['prune_argv']);

    expect(array_column($intent['peers'], 'node_id'))->toBe([$keyed->id]);
    foreach ([$keyed, $unkeyed, $enrolling, $failed] as $kept) {
        expect($argv)->toContain("! -name {$kept->id}.conf");
    }
    expect($argv)->not->toContain("! -name {$removing->id}.conf");
});
